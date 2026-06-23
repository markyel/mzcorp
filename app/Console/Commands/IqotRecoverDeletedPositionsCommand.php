<?php

namespace App\Console\Commands;

use App\Enums\IqotPositionStatus;
use App\Models\IqotPosition;
use App\Models\IqotSubmission;
use Illuminate\Console\Command;

/**
 * Восстановление iqot_positions, удалённых из пула, по которым УЖЕ потрачен
 * баланс IQOT (отправлялись в submission). Разовая чистка последствий слишком
 * агрессивного удаления в refreshPoolFromLostQuotes (до фикса): позиции с
 * оплаченными офферами сносились вместе с неквотированными.
 *
 * Данные офферов не потеряны — лежат в iqot_submissions (catalog_item_ids +
 * report/last_status_response, порядок = payload). Воссоздаём позицию для
 * каждого catalog_item из submission, у которого сейчас НЕТ строки, и
 * переприменяем отчёт (по индексу — как resolvePositionId по индексу).
 *
 *   php artisan iqot:recover-deleted-positions            # dry-run
 *   php artisan iqot:recover-deleted-positions --apply
 */
class IqotRecoverDeletedPositionsCommand extends Command
{
    protected $signature = 'iqot:recover-deleted-positions {--apply : Применить (без флага — dry-run)}';

    protected $description = 'Восстановить удалённые iqot-позиции с оплаченными отчётами из submissions.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $seen = [];
        $recreated = 0;
        $withReport = 0;

        // Свежие submission'ы — в приоритете (берём самые новые данные по cid).
        foreach (IqotSubmission::orderByDesc('id')->get() as $sub) {
            $cids = $this->asArray($sub->catalog_item_ids);
            if ($cids === []) {
                continue;
            }
            $payloadItems = array_values($this->asArray($sub->payload['items'] ?? null));
            $report = $this->asArray($sub->report);
            $reportItems = array_values($this->asArray($report['items'] ?? $report['lines'] ?? $report['results'] ?? null));

            foreach (array_values($cids) as $i => $cid) {
                $cid = (int) $cid;
                if ($cid <= 0 || isset($seen[$cid])) {
                    continue;
                }
                $seen[$cid] = true;
                if (IqotPosition::where('catalog_item_id', $cid)->exists()) {
                    continue; // не удалялась
                }

                $entry = is_array($reportItems[$i] ?? null) ? $reportItems[$i] : null;
                $pItem = is_array($payloadItems[$i] ?? null) ? $payloadItems[$i] : [];
                $hasOffers = $entry !== null && $this->offersCount($entry) !== null;

                $this->line(sprintf(
                    '  → cat %d (sub #%d): %s',
                    $cid,
                    $sub->id,
                    $hasOffers ? 'отчёт с офферами' : ($entry !== null ? 'отчёт без офферов' : 'отправлен, без отчёта'),
                ));

                if (! $apply) {
                    $recreated++;
                    $hasOffers && $withReport++;

                    continue;
                }

                $pos = new IqotPosition;
                $pos->catalog_item_id = $cid;
                $pos->source = IqotPosition::SOURCE_AUTO;
                $pos->iqot_submission_id = $sub->id;
                $pos->lost_quote_count = 0; // пере-проставит pool refresh, если квотирована
                $pos->qty = isset($pItem['quantity']) && is_numeric($pItem['quantity']) ? (float) $pItem['quantity'] : null;
                $pos->unit = isset($pItem['unit']) ? mb_substr((string) $pItem['unit'], 0, 32) : null;
                // Баланс по позиции потрачен — чистка пула её больше не тронет.
                $pos->last_enqueued_at = $sub->created_at;

                if ($entry !== null) {
                    $offers = $this->offersCount($entry);
                    $pos->report = $entry;
                    $cmp = $pos->priceComparison(); // читает $pos->report
                    $pos->report_offers_count = $offers;
                    $pos->report_min_price = $pos->minPriceFromReport();
                    $pos->cmp_our_rank = $cmp['our_rank'];
                    $pos->cmp_deviation_pct = $cmp['delta_pct'];
                    $pos->cmp_total = $cmp['total'] ?: null;
                    $pos->analyzed_at = $sub->report_fetched_at ?? $sub->updated_at ?? now();
                    $pos->status = ($offers ?? 0) > 0
                        ? IqotPositionStatus::Completed->value
                        : IqotPositionStatus::NoOffers->value;
                    $withReport++;
                } else {
                    // Отправлена, отчёта ещё нет — пусть поллер дотянет.
                    $pos->status = IqotPositionStatus::Analyzing->value;
                }

                $pos->save();
                $recreated++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Готово (%s). Восстановлено позиций: %d (из них с отчётом: %d).',
            $apply ? 'APPLY' : 'DRY-RUN',
            $recreated,
            $withReport,
        ));

        return self::SUCCESS;
    }

    private function offersCount(array $entry): ?int
    {
        foreach (['offers_count', 'offer_count', 'count'] as $k) {
            if (isset($entry[$k]) && is_numeric($entry[$k])) {
                return (int) $entry[$k];
            }
        }
        foreach (['all_offers', 'offers'] as $k) {
            if (isset($entry[$k]) && is_array($entry[$k])) {
                return count($entry[$k]);
            }
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    private function asArray(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $d = json_decode($v, true);

            return is_array($d) ? $d : [];
        }

        return [];
    }
}
