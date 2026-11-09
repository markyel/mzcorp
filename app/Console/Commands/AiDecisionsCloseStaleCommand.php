<?php

namespace App\Console\Commands;

use App\Enums\AiDecisionStatus;
use App\Enums\DetectorType;
use App\Enums\RequestStatus;
use App\Models\AiDecision;
use Illuminate\Console\Command;

/**
 * Закрывает подсказки детектора, которые больше ничего не меняют: заявка уже в целевом
 * статусе, ушла дальше по воронке или закрыта. Менеджер видел их плашками «перевести в
 * КП отправлено» на заявке, где КП давно отправлено.
 *
 * Кейс 09.11.2026: после восстановления баз загрузка истории (05–06.11) оставила ~400 таких
 * подсказок — статусы заявок проставлены по истории, а подсказки по тем же письмам висели.
 *
 * Закрывает в dismissed с payload.closed_as = stale: в «AI quality» они не считаются
 * ошибкой детектора (он чаще всего угадал — заявка там, куда он предлагал).
 *
 *   php artisan ai-decisions:close-stale            # dry-run: что закроет, по типам
 *   php artisan ai-decisions:close-stale --apply
 */
class AiDecisionsCloseStaleCommand extends Command
{
    protected $signature = 'ai-decisions:close-stale
        {--apply : Закрыть (по умолчанию dry-run)}
        {--type= : Фильтр по detector_type (через запятую)}';

    protected $description = 'Закрыть подсказки детектора, устаревшие для своей заявки (уже в целевом статусе, дальше или закрыта)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $types = array_filter(array_map('trim', explode(',', (string) $this->option('type'))));

        $query = AiDecision::query()
            ->with('request:id,status')
            ->where('status', AiDecisionStatus::Suggested->value)
            ->when($types !== [], fn ($q) => $q->whereIn('detector_type', $types))
            ->orderBy('id');

        $byType = [];
        $closed = 0;
        $query->chunkById(200, function ($rows) use ($apply, &$byType, &$closed) {
            foreach ($rows as $d) {
                $reason = self::staleReason($d->detector_type, $d->request?->status);
                if ($reason === null) {
                    continue;
                }
                $key = $d->detector_type->value.' · '.$reason;
                $byType[$key] = ($byType[$key] ?? 0) + 1;
                $closed++;

                if ($apply) {
                    $d->update([
                        'status' => AiDecisionStatus::Dismissed->value,
                        'applied_at' => now(),
                        'payload' => array_merge(is_array($d->payload) ? $d->payload : [], [
                            AiDecision::PAYLOAD_CLOSED_AS => AiDecision::CLOSED_AS_STALE,
                            'closed_as_reason' => $reason,
                        ]),
                    ]);
                }
            }
        });

        ksort($byType);
        $this->table(['Тип · причина', 'Подсказок'], collect($byType)->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->line(($apply ? 'Закрыто: ' : 'Будет закрыто (dry-run, --apply чтобы закрыть): ').$closed);

        return self::SUCCESS;
    }

    /**
     * Почему подсказка устарела, или null — актуальна. Нет заявки или она закрыта —
     * устарела любая подсказка. Иначе — заявка в целевом статусе, или цель в воронке
     * (КП → согласование → ждёт счёт → счёт) и заявка ушла по ней дальше. Цель вне воронки
     * (in_progress, ждём уточнения, отложена, closed_lost; lifecycleOrder -1) с порядком не
     * сравниваем: отказ или «отложим» клиента после КП — актуальная подсказка, не устаревшая.
     */
    public static function staleReason(DetectorType $type, ?RequestStatus $current): ?string
    {
        if ($current === null) {
            return 'нет заявки';
        }
        if ($current->isTerminal()) {
            return 'заявка закрыта';
        }

        $target = $type->targetStatus();
        if ($target === null) {
            return null;
        }
        if ($current === $target) {
            return 'уже в целевом';
        }

        return $target->lifecycleOrder() > 0 && $current->lifecycleOrder() > $target->lifecycleOrder()
            ? 'заявка дальше цели'
            : null;
    }
}
