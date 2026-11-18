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

        Request::query()->whereIn('status', [RequestStatus::Quoted->value, RequestStatus::PartiallyQuoted->value])
            ->orderBy('id')->each(function (Request $r) use ($coverage, $dry, &$changes) {
                if (! $coverage->hasQuote($r)) {
                    return;
                }
                $open = $coverage->uncovered($r);
                $target = $open->isNotEmpty() ? RequestStatus::PartiallyQuoted : RequestStatus::Quoted;
                if ($r->status === $target) {
                    return;
                }
                $changes[$target === RequestStatus::PartiallyQuoted ? 'to_partial' : 'to_quoted']++;
                $this->line(sprintf('%s  %s → %s%s', $r->internal_code, $r->status->label(), $target->label(),
                    $open->isNotEmpty() ? '  · нет КП: '.$open->map(fn ($i) => mb_strimwidth((string) $i->parsed_name, 0, 50, '…'))->implode('; ') : ''));
                if (! $dry) {
                    $coverage->sync($r, 'backfill');
                }
            });

        $this->info(($dry ? '[dry-run] ' : '').'в «Частичное КП»: '.$changes['to_partial'].', в «КП отправлено»: '.$changes['to_quoted']);

        return self::SUCCESS;
    }
}
