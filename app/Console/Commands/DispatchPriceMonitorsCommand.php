<?php

namespace App\Console\Commands;

use App\Models\PriceMonitor;
use App\Models\User;
use App\Services\Procurement\PriceMonitorService;
use App\Services\Supplier\SupplierProcurementDispatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Автоматический перезапрос цен (Фаза 4.3): по мониторингам, у которых подошёл
 * срок, повторно рассылает запрос тем же поставщикам, что и в исходной отправке.
 *
 * Позиции группируются по ОДИНАКОВОМУ набору поставщиков — иначе на каждую
 * позицию ушло бы отдельное письмо, и поставщик получил бы их десяток вместо
 * одного списка.
 *
 * Отправитель письма — тот, кто включил мониторинг (у него есть ящик);
 * если пользователь удалён или слать некому, срок сдвигается на сутки,
 * чтобы мониторинг не крутился впустую каждый час.
 */
class DispatchPriceMonitorsCommand extends Command
{
    protected $signature = 'procurement:price-monitors
        {--limit=50 : Максимум позиций за прогон}
        {--dry-run : Показать, что ушло бы, и ничего не отправлять}';

    protected $description = 'Повторные запросы цен поставщикам по мониторингам, у которых подошёл срок';

    public function handle(SupplierProcurementDispatchService $dispatcher, PriceMonitorService $monitors): int
    {
        $dry = (bool) $this->option('dry-run');
        $due = PriceMonitor::query()->due()
            ->with('catalogItem:id,sku,name,is_active')
            ->orderBy('next_due_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($due->isEmpty()) {
            $this->info('Мониторингов к отправке нет.');

            return self::SUCCESS;
        }
        $this->info("Подошёл срок у позиций: {$due->count()}".($dry ? ' (сухой прогон)' : ''));

        // Ключ группы — набор поставщиков и автор: письмо уходит из ящика автора.
        $groups = $due->groupBy(function (PriceMonitor $m) {
            $ids = array_map('intval', (array) $m->supplier_ids);
            sort($ids);

            return $m->created_by_user_id.'|'.implode(',', $ids);
        });

        $sentTotal = 0;
        $skipped = 0;
        foreach ($groups as $key => $group) {
            [$userId, $supplierCsv] = explode('|', (string) $key, 2);
            $supplierIds = array_values(array_filter(array_map('intval', explode(',', $supplierCsv))));
            $user = $userId !== '' ? User::find((int) $userId) : null;

            // Позиция могла быть выключена в каталоге после включения мониторинга.
            $live = $group->filter(fn (PriceMonitor $m) => $m->catalogItem !== null && $m->catalogItem->is_active);
            $cids = $live->pluck('catalog_item_id')->map('intval')->values()->all();

            if ($user === null || $supplierIds === [] || $cids === []) {
                foreach ($group as $m) {
                    $monitors->postpone($m);
                    $skipped++;
                }
                Log::warning('PriceMonitors: нечего или некому слать', [
                    'user_id' => $userId, 'suppliers' => $supplierIds, 'items' => count($cids),
                ]);

                continue;
            }

            $this->line(sprintf('  %s → поставщиков %d, позиций %d',
                $user->name, count($supplierIds), count($cids)));
            if ($dry) {
                continue;
            }

            $note = 'Плановый перезапрос цен (мониторинг).';
            // Поставщики, принявшие приглашение QuoteParts, — через платформу; остальным письмо.
            // Через платформу — автоматический запрос: ящик поставщика выбирает его режим (главный / по очереди).
            $route = app(\App\Services\QuoteParts\QuotePartsRouting::class)->split($supplierIds);
            $qpSent = 0;
            if ($route['quoteparts'] !== []) {
                try {
                    app(\App\Services\QuoteParts\QuotePartsRfqService::class)->send(null, array_fill_keys($cids, 1.0), $route['quoteparts'], null, $note, $user, [], routed: true, automatic: true);
                    $qpSent = count($route['quoteparts']);
                } catch (\Throwable $e) {
                    Log::warning('PriceMonitors: QuoteParts не принял запрос', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
            // Внесистемные — наше же письмо через QuoteParts (orders@); не принял — им обычное письмо.
            if ($route['offsystem'] !== []) {
                try {
                    $catalog = \App\Models\CatalogItem::query()->whereIn('id', $cids)->get(['id', 'sku', 'name', 'name_en', 'brand', 'brand_article', 'articles']);
                    $letters = [];
                    foreach (\App\Models\Supplier::query()->whereIn('id', $route['offsystem'])->get() as $s) {
                        $letters[(int) $s->id] = $dispatcher->composeLetter($catalog, $s, $note);
                    }
                    app(\App\Services\QuoteParts\QuotePartsRfqService::class)->send(null, array_fill_keys($cids, 1.0), $route['offsystem'], null, $note, $user, [], routed: true, letters: $letters, automatic: true);
                    $qpSent += count($route['offsystem']);
                } catch (\Throwable $e) {
                    Log::warning('PriceMonitors: QuoteParts не принял запрос внесистемным', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                    $route['mail'] = array_values(array_unique([...$route['mail'], ...$route['offsystem']]));
                }
            }
            $res = $route['mail'] === [] ? ['sent' => 0] : $dispatcher->dispatch($cids, $route['mail'], $note, $user);
            $res['sent'] = ($res['sent'] ?? 0) + $qpSent;

            if (($res['sent'] ?? 0) === 0) {
                // Ничего не ушло: письмо не отправилось или всё уже запрошено.
                // Сдвигаем на сутки, чтобы не долбить на каждом прогоне.
                foreach ($live as $m) {
                    $monitors->postpone($m);
                    $skipped++;
                }
                Log::warning('PriceMonitors: рассылка без результата', [
                    'user_id' => $user->id, 'error' => $res['error'] ?? null, 'skipped' => $res['skipped'] ?? 0,
                ]);

                continue;
            }

            foreach ($live as $m) {
                $monitors->markDispatched($m, $res['inquiry_ids'][0] ?? null);
            }
            $sentTotal += (int) $res['sent'];
        }

        $this->info("Отправлено писем: {$sentTotal}, отложено позиций: {$skipped}.");

        return self::SUCCESS;
    }
}
