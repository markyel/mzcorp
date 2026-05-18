<?php

namespace App\Jobs\Quotes;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\OutboundQuote;
use App\Models\OutboundQuoteItem;
use App\Models\Request;
use App\Services\Quotes\OutboundQuoteCatalogEnricher;
use App\Services\Quotes\OutboundQuoteItemMatcher;
use App\Services\Quotes\OutboundQuoteParsingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Парсер одного исходящего вложения (PDF/XLSX/DOCX), которое DocumentDetector
 * пометил как `outbound_quotation_full` или `outbound_invoice`.
 *
 * Pipeline:
 *   1. firstOrCreate OutboundQuote (status=parsing) — idempotency по attachment_id
 *   2. OutboundQuoteParsingService::extractContent → text + images
 *   3. parseWithGPT → document + items
 *   4. Запись items в outbound_quote_items (truncate если $force)
 *   5. OutboundQuoteItemMatcher::match → matched_* поля
 *   6. OutboundQuoteCatalogEnricher::enrich → auto-link request_items.catalog_item_id
 *
 * Сбой на любом шаге → status=failed + parse_error в OutboundQuote.
 *
 * Диспатчится из:
 *   - MailRouter после OutboundDocumentDetector/Classifier нашёл quotation/invoice
 *   - CLI quotes:parse-outbound для backfill
 *
 * Idempotent: ShouldBeUnique key = ('quote-parse:<attachment_id>'), 30 минут.
 * Force-режим: повторный run с $force=true перезаписывает items (truncate +
 * recreate) и пересчитывает matcher.
 */
class ParseOutboundQuoteJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 300; // PDF Vision-вызов может занять до ~90s

    public function __construct(
        public readonly int $emailAttachmentId,
        public readonly string $documentType,
        public readonly bool $force = false,
    ) {
    }

    public function uniqueId(): string
    {
        return 'quote-parse:'.$this->emailAttachmentId.($this->force ? ':force' : '');
    }

    public function uniqueFor(): int
    {
        return 30 * 60;
    }

    public function handle(
        OutboundQuoteParsingService $parser,
        OutboundQuoteItemMatcher $matcher,
        OutboundQuoteCatalogEnricher $enricher,
    ): void {
        $attachment = EmailAttachment::with('emailMessage')->find($this->emailAttachmentId);
        if (! $attachment) {
            Log::info('ParseOutboundQuoteJob: attachment not found', ['id' => $this->emailAttachmentId]);

            return;
        }

        $message = $attachment->emailMessage;
        if (! $message instanceof EmailMessage || $message->related_request_id === null) {
            Log::info('ParseOutboundQuoteJob: no linked request, skip', [
                'attachment_id' => $attachment->id,
                'email_message_id' => $message?->id,
            ]);

            return;
        }

        $request = Request::find($message->related_request_id);
        if (! $request) {
            Log::info('ParseOutboundQuoteJob: request not found', [
                'request_id' => $message->related_request_id,
            ]);

            return;
        }

        // Guard 1: расширение файла поддерживается.
        $ext = strtolower((string) pathinfo((string) $attachment->filename, PATHINFO_EXTENSION));
        $parseable = (array) config('services.quotes.parseable_extensions', ['pdf', 'xlsx', 'xls', 'docx']);
        if (! in_array($ext, $parseable, true)) {
            Log::info('ParseOutboundQuoteJob: extension not parseable', [
                'attachment_id' => $attachment->id,
                'ext' => $ext,
            ]);

            return;
        }

        // Guard 2: размер файла.
        $maxBytes = (int) config('services.quotes.max_attachment_bytes', 15 * 1024 * 1024);
        if ($attachment->size_bytes > $maxBytes) {
            Log::warning('ParseOutboundQuoteJob: attachment too large', [
                'attachment_id' => $attachment->id,
                'size_bytes' => $attachment->size_bytes,
                'max_bytes' => $maxBytes,
            ]);

            return;
        }

        // Guard 3: файл физически существует.
        $disk = $attachment->disk ?: 'local';
        if (! Storage::disk($disk)->exists($attachment->file_path)) {
            Log::warning('ParseOutboundQuoteJob: file missing on storage', [
                'attachment_id' => $attachment->id,
                'disk' => $disk,
                'file_path' => $attachment->file_path,
            ]);

            return;
        }

        // Idempotency: уже распарсено и НЕ force?
        $existing = OutboundQuote::where('email_attachment_id', $attachment->id)->first();
        if ($existing && in_array($existing->status, [OutboundQuote::STATUS_PARSED, OutboundQuote::STATUS_MATCHED], true) && ! $this->force) {
            Log::info('ParseOutboundQuoteJob: already parsed, skip (use --reset to force)', [
                'attachment_id' => $attachment->id,
                'quote_id' => $existing->id,
            ]);

            return;
        }

        $quote = $existing ?? new OutboundQuote([
            'request_id' => $request->id,
            'email_message_id' => $message->id,
            'email_attachment_id' => $attachment->id,
            'source' => OutboundQuote::SOURCE_ATTACHMENT,
            'document_type' => $this->documentType,
            'currency' => 'RUB',
        ]);
        $quote->status = OutboundQuote::STATUS_PARSING;
        $quote->parse_error = null;
        $quote->save();

        try {
            $absolutePath = Storage::disk($disk)->path($attachment->file_path);
            $content = $parser->extractContent($absolutePath, $ext, isAbsolute: true);

            $parsed = $parser->parseWithGPT(
                $content['text'] ?? null,
                $content['images'] ?? [],
                $request
            );

            $document = $parsed['document'] ?? [];
            $items = $parsed['items'] ?? [];

            DB::transaction(function () use ($quote, $document, $items) {
                $quote->document_number = isset($document['number']) ? mb_substr((string) $document['number'], 0, 128) : null;
                $quote->document_date = ! empty($document['date']) ? (string) $document['date'] : null;
                $quote->currency = (string) ($document['currency'] ?? $quote->currency ?? 'RUB');
                $quote->subtotal = isset($document['subtotal']) && is_numeric($document['subtotal'])
                    ? (string) $document['subtotal'] : null;
                $quote->vat_amount = isset($document['vat_amount']) && is_numeric($document['vat_amount'])
                    ? (string) $document['vat_amount'] : null;
                $quote->total_amount = isset($document['total_amount']) && is_numeric($document['total_amount'])
                    ? (string) $document['total_amount'] : null;
                $quote->vat_rate = isset($document['vat_rate']) && is_numeric($document['vat_rate'])
                    ? (string) $document['vat_rate'] : null;
                $quote->prices_include_vat = isset($document['prices_include_vat'])
                    ? (bool) $document['prices_include_vat'] : null;

                $quote->status = OutboundQuote::STATUS_PARSED;
                $quote->parsed_at = now();
                $quote->save();

                // Сбрасываем старые items (force-режим или просто грязный previous run).
                OutboundQuoteItem::where('outbound_quote_id', $quote->id)->delete();

                $position = 0;
                foreach ($items as $raw) {
                    if (! is_array($raw)) {
                        continue;
                    }
                    $position++;

                    OutboundQuoteItem::create([
                        'outbound_quote_id' => $quote->id,
                        'position' => $position,
                        'raw_name' => mb_substr((string) ($raw['name'] ?? ''), 0, 1000),
                        'raw_article' => isset($raw['article']) && $raw['article'] !== ''
                            ? mb_substr((string) $raw['article'], 0, 128) : null,
                        'raw_brand' => isset($raw['brand']) && $raw['brand'] !== ''
                            ? mb_substr((string) $raw['brand'], 0, 128) : null,
                        'quantity' => isset($raw['quantity']) && is_numeric($raw['quantity'])
                            ? (string) $raw['quantity'] : null,
                        'unit_measure' => isset($raw['unit_measure']) && $raw['unit_measure'] !== ''
                            ? mb_substr((string) $raw['unit_measure'], 0, 32) : null,
                        'unit_quantity' => isset($raw['unit_quantity']) && is_numeric($raw['unit_quantity'])
                            ? (string) $raw['unit_quantity'] : null,
                        'unit_price' => isset($raw['unit_price']) && is_numeric($raw['unit_price'])
                            ? (string) $raw['unit_price'] : null,
                        'line_price' => isset($raw['price']) && is_numeric($raw['price'])
                            ? (string) $raw['price'] : null,
                        'line_total' => isset($raw['total']) && is_numeric($raw['total'])
                            ? (string) $raw['total'] : null,
                        'delivery_days' => isset($raw['delivery_days']) && is_numeric($raw['delivery_days'])
                            ? (int) $raw['delivery_days'] : null,
                        'is_analog' => (bool) ($raw['is_analog'] ?? false),
                        'notes' => isset($raw['notes']) && $raw['notes'] !== ''
                            ? mb_substr((string) $raw['notes'], 0, 1000) : null,
                        'payload' => [
                            'qty_available' => $raw['qty_available'] ?? null,
                            'vat_applied' => $raw['vat_applied'] ?? null,
                        ],
                    ]);
                }
            });

            // Cтоп: items нет — нечего матчить.
            $quote->refresh();
            if ($quote->items()->count() === 0) {
                Log::warning('ParseOutboundQuoteJob: parser returned 0 items', [
                    'quote_id' => $quote->id,
                ]);

                return;
            }

            // Сохраняем raw LLM-ответ для диагностики (после транзакции, чтобы не раздувать её).
            $quote->ai_raw_response = $parsed['raw'] ?? null;
            $quote->save();

            $matchStats = $matcher->match($quote);
            $quote->refresh();
            $quote->status = OutboundQuote::STATUS_MATCHED;
            $quote->matched_at = now();
            $quote->payload = array_merge(
                is_array($quote->payload) ? $quote->payload : [],
                ['match_stats' => $matchStats]
            );
            $quote->save();

            $enrichStats = $enricher->enrich($quote);

            Log::info('ParseOutboundQuoteJob: success', [
                'quote_id' => $quote->id,
                'request_id' => $request->id,
                'document_type' => $this->documentType,
                'items_count' => $quote->items()->count(),
                'total_amount' => $quote->total_amount,
                'match_stats' => $matchStats,
                'enrich_stats' => $enrichStats,
            ]);
        } catch (\Throwable $e) {
            $quote->status = OutboundQuote::STATUS_FAILED;
            $quote->parse_error = mb_substr($e->getMessage(), 0, 1000);
            $quote->save();

            Log::error('ParseOutboundQuoteJob: failed', [
                'quote_id' => $quote->id,
                'attachment_id' => $attachment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
