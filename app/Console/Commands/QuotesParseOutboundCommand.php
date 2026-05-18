<?php

namespace App\Console\Commands;

use App\Enums\DetectorType;
use App\Jobs\Quotes\ParseOutboundQuoteJob;
use App\Models\AiDecision;
use App\Models\EmailAttachment;
use App\Models\OutboundQuote;
use App\Models\Request as RequestModel;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Backfill парсера исходящих КП/счетов по уже зафиксированным AiDecision.
 *
 *   php artisan quotes:parse-outbound                 # dry-run, показывает что бы dispatch'нули
 *   php artisan quotes:parse-outbound --apply         # реально dispatch
 *   php artisan quotes:parse-outbound --apply --since=30d
 *   php artisan quotes:parse-outbound --apply --request=M-2026-0759
 *   php artisan quotes:parse-outbound --apply --message=12345
 *   php artisan quotes:parse-outbound --apply --reset --quote=42   # force reparse конкретного quote
 *
 * По умолчанию пропускает attachment'ы у которых OutboundQuote.status уже
 * `matched`. Флаг --reset форсит reparse (force=true в job), что заодно
 * перезапишет items и пересчитает matcher.
 */
class QuotesParseOutboundCommand extends Command
{
    protected $signature = 'quotes:parse-outbound
        {--apply : Реально dispatch job\'ы, иначе dry-run}
        {--since= : Период (например 7d / 30d / 90d), отсекает старые AiDecision}
        {--request= : Точечно по internal_code заявки (M-2026-NNNN)}
        {--message= : Точечно по email_message_id}
        {--quote= : Точечно по outbound_quotes.id (требует --reset для повторного парсинга)}
        {--reset : Force reparse (truncate items и пересчитать) — игнорирует status=matched}
        {--limit=200 : Максимум dispatch\'ей за прогон}';

    protected $description = 'Backfill парсера исходящих КП/счетов по AiDecision\'ам';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $reset = (bool) $this->option('reset');
        $since = $this->option('since');
        $requestCode = $this->option('request');
        $messageId = $this->option('message');
        $quoteId = $this->option('quote');
        $limit = (int) $this->option('limit');

        // Точечный режим по quote_id.
        if ($quoteId !== null) {
            $quote = OutboundQuote::find((int) $quoteId);
            if (! $quote) {
                $this->error("OutboundQuote #{$quoteId} not found");

                return self::FAILURE;
            }
            if ($quote->email_attachment_id === null) {
                $this->error("OutboundQuote #{$quoteId} is body-source, не attachment");

                return self::FAILURE;
            }
            if (! $reset && in_array($quote->status, [OutboundQuote::STATUS_PARSED, OutboundQuote::STATUS_MATCHED], true)) {
                $this->warn('Quote already parsed. Use --reset для повторного парсинга.');

                return self::FAILURE;
            }

            $this->line(sprintf(
                '%s ParseOutboundQuoteJob(attachment=%d, type=%s, force=%s)',
                $apply ? '[DISPATCH]' : '[DRY-RUN]',
                $quote->email_attachment_id,
                $quote->document_type?->value,
                $reset ? 'true' : 'false',
            ));
            if ($apply) {
                ParseOutboundQuoteJob::dispatch(
                    $quote->email_attachment_id,
                    $quote->document_type?->value ?? DetectorType::OutboundQuotationFull->value,
                    $reset
                );
            }

            return self::SUCCESS;
        }

        // Базовый запрос: AiDecision'ы с outbound quotation/invoice.
        $q = AiDecision::query()
            ->whereIn('detector_type', [
                DetectorType::OutboundQuotationFull->value,
                DetectorType::OutboundInvoice->value,
            ])
            ->whereNotNull('email_message_id')
            ->orderBy('id');

        if ($messageId !== null) {
            $q->where('email_message_id', (int) $messageId);
        }
        if ($requestCode !== null) {
            $req = RequestModel::where('internal_code', $requestCode)->first();
            if (! $req) {
                $this->error("Request {$requestCode} not found");

                return self::FAILURE;
            }
            $q->where('request_id', $req->id);
        }
        if ($since !== null && $since !== '') {
            $sinceDate = $this->parseSince($since);
            if ($sinceDate === null) {
                $this->error('Bad --since format. Use 7d / 30d / 24h.');

                return self::FAILURE;
            }
            $q->where('created_at', '>=', $sinceDate);
        }

        $total = $q->count();
        $this->info(sprintf(
            'AiDecisions: %d. Mode: %s. Reset: %s. Limit: %d.',
            $total,
            $apply ? 'APPLY' : 'DRY-RUN',
            $reset ? 'YES' : 'no',
            $limit,
        ));
        if ($total === 0) {
            return self::SUCCESS;
        }

        $parseable = (array) config('services.quotes.parseable_extensions', ['pdf', 'xlsx', 'xls', 'docx']);
        $dispatched = 0;
        $skipped = 0;

        $q->chunkById(200, function ($decisions) use (
            &$dispatched, &$skipped, $apply, $reset, $parseable, $limit
        ) {
            foreach ($decisions as $dec) {
                if ($dispatched >= $limit) {
                    return false;
                }

                $atts = EmailAttachment::where('email_message_id', $dec->email_message_id)->get();
                if ($atts->isEmpty()) {
                    $skipped++;

                    continue;
                }

                foreach ($atts as $att) {
                    $ext = strtolower((string) pathinfo((string) $att->filename, PATHINFO_EXTENSION));
                    if (! in_array($ext, $parseable, true)) {
                        continue;
                    }

                    $existing = OutboundQuote::where('email_attachment_id', $att->id)->first();
                    if ($existing && in_array($existing->status, [
                        OutboundQuote::STATUS_PARSED,
                        OutboundQuote::STATUS_MATCHED,
                    ], true) && ! $reset) {
                        $skipped++;

                        continue;
                    }

                    $this->line(sprintf(
                        '  %s decision#%d msg#%d att#%d %s (%s)',
                        $apply ? '[D]' : '[~]',
                        $dec->id,
                        $dec->email_message_id,
                        $att->id,
                        $att->filename,
                        $dec->detector_type instanceof DetectorType
                            ? $dec->detector_type->value
                            : (string) $dec->detector_type,
                    ));

                    if ($apply) {
                        $type = $dec->detector_type instanceof DetectorType
                            ? $dec->detector_type->value
                            : (string) $dec->detector_type;
                        ParseOutboundQuoteJob::dispatch($att->id, $type, $reset);
                    }
                    $dispatched++;
                    if ($dispatched >= $limit) {
                        return false;
                    }
                }
            }

            return true;
        });

        $this->info(sprintf(
            'Done. Dispatched: %d. Skipped (no attachments / already parsed): %d.',
            $dispatched,
            $skipped,
        ));

        if (! $apply) {
            $this->warn('Это был DRY-RUN. Запусти с --apply чтобы реально dispatch\'нуть.');
        }

        return self::SUCCESS;
    }

    private function parseSince(string $s): ?Carbon
    {
        if (preg_match('/^(\d+)([dh])$/', $s, $m) !== 1) {
            return null;
        }
        $n = (int) $m[1];
        $unit = $m[2];

        return $unit === 'h' ? now()->subHours($n) : now()->subDays($n);
    }
}
