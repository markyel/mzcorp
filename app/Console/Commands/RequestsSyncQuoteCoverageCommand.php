<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\Request;
use App\Services\Quotes\QuoteCoverageService;
use Illuminate\Console\Command;

/**
 * Пересчёт «КП отправлено» ↔ «Частичное КП» по покрытию позиций (QuoteCoverageService) — разово при выкладке правила
 * (18.11.2026) и после правок matcher'а. --dry-run показывает, что изменится, ничего не меняя.
 */
class RequestsSyncQuoteCoverageCommand extends Command
{
    protected $signature = 'requests:sync-quote-coverage
        {--dry-run : Только показать, какие заявки сменят статус}';

    protected $description = 'Пересчитать «КП отправлено» / «Частичное КП» по покрытию позиций отправленными КП';

    public function handle(QuoteCoverageService $coverage): int
    {
        $dry = (bool) $this->option('dry-run');
        $changes = ['to_partial' => 0, 'to_quoted' => 0];

        // Статусом («КП отправлено» / «Частичное КП») или вехой — после КП заявка ждёт клиента, на правках, отложена.
        Request::query()->where(fn ($q) => $q->whereIn('status', [RequestStatus::Quoted->value, RequestStatus::PartiallyQuoted->value])
            ->orWhere(fn ($q) => $q->whereIn('peak_status', [RequestStatus::Quoted->value, RequestStatus::PartiallyQuoted->value])
                ->whereIn('status', [RequestStatus::AwaitingClientClarification->value, RequestStatus::InProgress->value, RequestStatus::PostponedUntil->value])))
            ->orderBy('id')->each(function (Request $r) use ($coverage, $dry, &$changes) {
                if (! $coverage->hasQuote($r)) {
                    return;
                }
                $open = $coverage->uncovered($r);
                $target = $open->isNotEmpty() ? RequestStatus::PartiallyQuoted : RequestStatus::Quoted;
                $byStatus = in_array($r->status, QuoteCoverageService::QUOTE_STATUSES, true);
                $current = $byStatus ? $r->status : $r->peak_status;
                if ($current === $target) {
                    return;
                }
                $changes[$target === RequestStatus::PartiallyQuoted ? 'to_partial' : 'to_quoted']++;
                $this->line(sprintf('%s  %s%s → %s%s', $r->internal_code, $byStatus ? '' : '['.$r->status->label().', веха] ', $current->label(), $target->label(),
                    $open->isNotEmpty() ? '  · нет КП: '.$open->map(fn ($i) => mb_strimwidth((string) $i->parsed_name, 0, 50, '…'))->implode('; ') : ''));
                if (! $dry) {
                    $coverage->sync($r, 'backfill');
                }
            });

        $this->info(($dry ? '[dry-run] ' : '').'в «Частичное КП»: '.$changes['to_partial'].', в «КП отправлено»: '.$changes['to_quoted']);

        return self::SUCCESS;
    }
}
