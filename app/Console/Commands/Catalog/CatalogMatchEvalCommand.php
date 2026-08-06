<?php

namespace App\Console\Commands\Catalog;

use App\Models\RequestItem;
use App\Services\Catalog\CatalogResolutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Регресс-харнес матчера каталога (Фаза «тюнинг матчинга»).
 *
 * Эталон (ground truth): позиции ОТРАБОТАННЫХ заявок, где система авто-сматчила
 * один каталог, а менеджер в ОТПРАВЛЕННОМ КП указал другой (страница «Аудит
 * матчинга»). КП = истина. Значит для каждой такой позиции ИЗВЕСТЕН правильный
 * каталог (КП-шный) и текущий неверный (системный).
 *
 * Команда прогоняет ТЕКУЩИЙ матчер (matchOrResolve, A→B→C) на этих позициях
 * READ-ONLY — в откатываемой транзакции (applyCatalogToItem делает только
 * save(), без transitionTo/dispatch → rollback полностью изолирует БД; побочка —
 * только LLM-вызовы C-tier). Отработанные заявки НЕ меняются (по решению
 * заказчика — только тюнинг).
 *
 * Итог — confusion:
 *   correct     — матчер выбрал КП-каталог (истину) → стало верно;
 *   same_wrong  — матчер выбрал прежний (системный) неверный;
 *   other_wrong — матчер выбрал третий каталог (тоже != КП);
 *   pending     — матчер НЕ сматчил (в ручную) — это «безопасный» исход.
 *
 *   php artisan catalog:match-eval                 # первые 50 (LLM-стоимость)
 *   php artisan catalog:match-eval --limit=0       # все
 *   php artisan catalog:match-eval --limit=100 --show=15
 */
class CatalogMatchEvalCommand extends Command
{
    protected $signature = 'catalog:match-eval
        {--limit=50 : Сколько позиций-РАСХОЖДЕНИЙ прогнать (0 = все). LLM C-tier стоит денег — начинай с малого.}
        {--control=0 : Сколько позиций-КОНТРОЛЯ (C-матч сейчас = КП, истина) прогнать для замера РЕГРЕССИЙ. 0 = не мерить.}
        {--gate= : Переопределить ambiguity_gate_margin ТОЛЬКО на этот прогон (A/B без правки настроек). Напр. 0.05.}
        {--rawname= : Переопределить raw_name_retrieval на прогон: 1=вкл, 0=выкл. Для A/B retrieval-фикса.}
        {--nocat : Не подавать авто-категорию в LLM-реранк (A/B rerank_use_category=false).}
        {--show=10 : Сколько примеров каждой категории показать}';

    protected $description = 'Регресс-харнес матчера каталога на эталоне «система vs КП» (read-only, отработанные заявки не меняет).';

    public function handle(CatalogResolutionService $service): int
    {
        // A/B ambiguity-gate: переопределяем дефолт config ТОЛЬКО в этом
        // процессе. app_setting() при отсутствии DB-строки вернёт этот config
        // → гейт активен на прогон, прод-настройки не трогаем.
        if ($this->option('gate') !== null) {
            $g = (float) $this->option('gate');
            config(['services.catalog_name_match.ambiguity_gate_margin' => $g]);
            $this->warn("ambiguity_gate_margin переопределён на {$g} (только этот прогон)");
        }
        if ($this->option('rawname') !== null) {
            $on = (bool) (int) $this->option('rawname');
            config(['services.catalog_name_match.raw_name_retrieval' => $on]);
            $this->warn('raw_name_retrieval переопределён на '.($on ? 'ON' : 'OFF').' (только этот прогон)');
        }
        if ($this->option('nocat')) {
            config(['services.catalog_name_match.rerank_use_category' => false]);
            $this->warn('rerank_use_category=OFF — авто-категория не подаётся в реранк (только этот прогон)');
        }

        $labeled = $this->labeledSet((int) $this->option('limit'));
        $this->info('Эталон (система≠КП, КП=истина): '.count($labeled).' позиций. Прогоняю матчер read-only…');

        $stats = ['correct' => 0, 'same_wrong' => 0, 'other_wrong' => 0, 'pending' => 0];
        $byMethod = [];
        /** @var array<string, array<string, int>> метод × категория */
        $methodByCat = [];
        $samples = ['correct' => [], 'same_wrong' => [], 'other_wrong' => [], 'pending' => []];
        $show = max(0, (int) $this->option('show'));
        $bar = $this->output->createProgressBar(count($labeled));

        foreach ($labeled as $lab) {
            $item = RequestItem::find($lab->request_item_id);
            if ($item === null) {
                $bar->advance();

                continue;
            }
            [$predicted, $method, $readonlyOk] = $this->predict($item, $service);
            if (! $readonlyOk) {
                $this->error("НАРУШЕНА read-only гарантия на ri#{$lab->request_item_id} — прерываю.");

                return self::FAILURE;
            }

            $cat = $predicted === null ? 'pending'
                : ((int) $predicted === (int) $lab->kp_catalog_id ? 'correct'
                : ((int) $predicted === (int) $lab->sys_catalog_id ? 'same_wrong' : 'other_wrong'));
            $stats[$cat]++;
            if ($cat !== 'pending' && $method !== null) {
                $byMethod[$method] = ($byMethod[$method] ?? 0) + 1;
                $methodByCat[$method][$cat] = ($methodByCat[$method][$cat] ?? 0) + 1;
            }
            if (count($samples[$cat]) < $show) {
                $samples[$cat][] = sprintf('%s | %s → пред:%s (было sys:%s, истина КП:%s)',
                    $lab->internal_code,
                    mb_substr((string) $lab->parsed_name, 0, 30),
                    $predicted ?? '—', $lab->sys_sku, $lab->kp_sku);
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $total = array_sum($stats);
        $this->info('РЕЗУЛЬТАТ (эталон = '.$total.'):');
        $pct = fn ($n) => $total > 0 ? round($n / $total * 100, 1) : 0;
        $this->line(sprintf('  ✅ correct   (стало = КП):        %d (%s%%)', $stats['correct'], $pct($stats['correct'])));
        $this->line(sprintf('  🟡 pending   (в ручную, безопасно): %d (%s%%)', $stats['pending'], $pct($stats['pending'])));
        $this->line(sprintf('  ❌ same_wrong (прежний неверный):  %d (%s%%)', $stats['same_wrong'], $pct($stats['same_wrong'])));
        $this->line(sprintf('  ❌ other_wrong (третий, тоже != КП): %d (%s%%)', $stats['other_wrong'], $pct($stats['other_wrong'])));

        if ($methodByCat !== []) {
            $this->newLine();
            $this->line('По методу матча (correct / same_wrong / other_wrong):');
            foreach ($methodByCat as $m => $c) {
                $hint = str_starts_with($m, 'C_') ? '  ← тюнируемое (по названию)' : '  (по артикулу — вероятно легит-замена)';
                $this->line(sprintf('  %-16s ✅%d / ❌%d / ❌%d%s',
                    $m, $c['correct'] ?? 0, $c['same_wrong'] ?? 0, $c['other_wrong'] ?? 0, $hint));
            }
        }

        foreach (['same_wrong', 'other_wrong', 'correct', 'pending'] as $cat) {
            if ($samples[$cat] !== []) {
                $this->newLine();
                $this->line("— примеры [$cat] —");
                foreach ($samples[$cat] as $s) {
                    $this->line('  '.$s);
                }
            }
        }

        $this->runControl($service, (int) $this->option('control'), $show);

        return self::SUCCESS;
    }

    /**
     * Контрольный прогон: позиции, где C-матч СЕЙЧАС совпал с КП (истина).
     * После изменения ранкера здесь не должно расти число «сломано» — это
     * защита от регрессий (харнес расхождений видит только выигрыш).
     */
    private function runControl(CatalogResolutionService $service, int $n, int $show): void
    {
        if ($n <= 0) {
            return;
        }
        $control = $this->controlSet($n);
        $this->newLine(2);
        $this->info('КОНТРОЛЬ (C-матч сейчас = КП): '.count($control).' позиций. Проверяю, не ломает ли матчер верное…');

        $held = $regressed = $nowPending = $errors = 0;
        $broken = [];
        $bar = $this->output->createProgressBar(count($control));
        foreach ($control as $lab) {
            $item = RequestItem::find($lab->request_item_id);
            if ($item === null) {
                $bar->advance();

                continue;
            }
            [$predicted, , $readonlyOk] = $this->predict($item, $service);
            if (! $readonlyOk) {
                $errors++;
                $bar->advance();

                continue;
            }
            if ($predicted === null) {
                $nowPending++;
            } elseif ((int) $predicted === (int) $lab->kp_catalog_id) {
                $held++;
            } else {
                $regressed++;
                if (count($broken) < $show) {
                    $broken[] = sprintf('%s | %s: было верно %s → стало %s',
                        $lab->internal_code, mb_substr((string) $lab->parsed_name, 0, 30), $lab->kp_sku, $predicted);
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $tot = max(1, $held + $regressed + $nowPending);
        $this->info('КОНТРОЛЬ (регрессии) — '.($held + $regressed + $nowPending).':');
        $this->line(sprintf('  ✅ держит верное:        %d (%.1f%%)', $held, $held / $tot * 100));
        $this->line(sprintf('  🟡 стало pending (в ручную): %d (%.1f%%)', $nowPending, $nowPending / $tot * 100));
        $this->line(sprintf('  ❌ СЛОМАНО (было верно → неверно): %d (%.1f%%)', $regressed, $regressed / $tot * 100));
        foreach ($broken as $b) {
            $this->line('  '.$b);
        }
    }

    /**
     * Один прогон матчера read-only (txn + rollback). Возвращает
     * [predicted catalog_item_id|null, method|null, read-only не нарушен?].
     *
     * @return array{0: ?int, 1: ?string, 2: bool}
     */
    private function predict(RequestItem $item, CatalogResolutionService $service): array
    {
        $originalCatalog = $item->catalog_item_id;
        $predicted = null;
        $method = null;
        DB::beginTransaction();
        try {
            $item->catalog_item_id = null;
            $item->quality_assessment_status = 'internal_catalog_pending';
            $service->matchOrResolve($item);
            $predicted = $item->catalog_item_id;
            $method = is_array($item->quality_assessment_payload['catalog_match'] ?? null)
                ? ($item->quality_assessment_payload['catalog_match']['method'] ?? '?')
                : null;
        } catch (\Throwable $e) {
            // eval-сбой одной позиции не валит прогон.
        } finally {
            DB::rollBack();
        }

        $readonlyOk = (int) (RequestItem::whereKey($item->id)->value('catalog_item_id')) === (int) $originalCatalog;

        return [$predicted, $method, $readonlyOk];
    }

    /**
     * Контрольный набор: C-матчи, совпавшие с КП (parsed_article пуст → шли
     * путём C; matched_catalog = ri.catalog = истина). Дедуп по позиции.
     *
     * @return array<int, object>
     */
    private function controlSet(int $n): array
    {
        $sql = "
            SELECT DISTINCT ON (ri.id)
                   ri.id AS request_item_id,
                   ri.catalog_item_id AS kp_catalog_id,
                   r.internal_code, ri.parsed_name, kpc.sku AS kp_sku
            FROM outbound_quote_items oqi
            JOIN outbound_quotes oq ON oq.id = oqi.outbound_quote_id
            JOIN request_items ri ON ri.id = oqi.matched_request_item_id
            JOIN requests r ON r.id = oq.request_id
            LEFT JOIN catalog_items kpc ON kpc.id = ri.catalog_item_id
            WHERE ri.catalog_item_id IS NOT NULL
              AND ri.catalog_item_id = oqi.matched_catalog_item_id
              AND ri.is_active = true AND oqi.is_analog = false
              AND (ri.parsed_article IS NULL OR ri.parsed_article = '')
              -- только матчи, реально выданные путём C (не ручные/артикульные),
              -- иначе матчер их не воспроизведёт и контроль ложно «нестабилен».
              AND ri.quality_assessment_payload->'catalog_match'->>'method' = 'C_name_vector'
            ORDER BY ri.id DESC
        ";
        if ($n > 0) {
            $sql .= ' LIMIT '.$n;
        }

        return DB::select($sql);
    }

    /**
     * Эталонный набор — та же логика, что «Аудит матчинга» (с исключением
     * подтверждённых системных матчей и дедупом строк КП).
     *
     * @return array<int, object>
     */
    private function labeledSet(int $limit): array
    {
        $sql = "
            SELECT oqi.matched_request_item_id AS request_item_id,
                   ri.catalog_item_id AS sys_catalog_id,
                   oqi.matched_catalog_item_id AS kp_catalog_id,
                   r.internal_code, ri.parsed_name, sysc.sku AS sys_sku, kpc.sku AS kp_sku
            FROM outbound_quote_items oqi
            JOIN outbound_quotes oq ON oq.id = oqi.outbound_quote_id
            JOIN request_items ri ON ri.id = oqi.matched_request_item_id
            JOIN requests r ON r.id = oq.request_id
            LEFT JOIN catalog_items kpc ON kpc.id = oqi.matched_catalog_item_id
            LEFT JOIN catalog_items sysc ON sysc.id = ri.catalog_item_id
            WHERE ri.catalog_item_id IS NOT NULL AND oqi.matched_catalog_item_id IS NOT NULL
              AND ri.catalog_item_id <> oqi.matched_catalog_item_id AND ri.is_active = true
              AND oqi.is_analog = false
              AND (sysc.name IS NULL OR sysc.name NOT ILIKE '%ЗАМЕНЕНО%')
              AND NOT EXISTS (SELECT 1 FROM outbound_quote_items o2
                    JOIN outbound_quotes oq2 ON oq2.id = o2.outbound_quote_id
                    WHERE oq2.request_id = oq.request_id
                      AND o2.matched_catalog_item_id = ri.catalog_item_id)
              AND oqi.id = (SELECT min(o3.id) FROM outbound_quote_items o3
                    WHERE o3.matched_request_item_id = oqi.matched_request_item_id
                      AND o3.matched_catalog_item_id = oqi.matched_catalog_item_id
                      AND o3.outbound_quote_id = oqi.outbound_quote_id)
            ORDER BY oqi.id DESC
        ";
        if ($limit > 0) {
            $sql .= ' LIMIT '.$limit;
        }

        return DB::select($sql);
    }
}
