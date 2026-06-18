<?php

namespace App\Services\Supplier;

use App\Models\EmailMessage;
use App\Models\SupplierInquiry;
use App\Models\SupplierInquiryItem;
use App\Models\SupplierOffer;
use App\Prompts\Suppliers\ParseSupplierReplyPrompt;
use App\Services\AI\OpenAIChatService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Разбор ответа поставщика на RFQ в предложения по позициям (Фаза 3.3).
 * Знаем запрошенные позиции (supplier_inquiry_items) → LLM сопоставляет ответ:
 * quoted (цена) / refused / skipped. Пишет SupplierOffer + обновляет статус
 * позиций. Идемпотентно по (inquiry, message): повторный разбор перезаписывает
 * офферы этого письма. Fail-safe.
 */
class SupplierOfferParser
{
    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly ParseSupplierReplyPrompt $prompt,
    ) {
    }

    /**
     * @return array{quoted:int, refused:int, skipped:int}
     */
    public function parse(SupplierInquiry $inquiry, EmailMessage $reply): array
    {
        $zero = ['quoted' => 0, 'refused' => 0, 'skipped' => 0];

        $items = $inquiry->items()->with('requestItem:id,parsed_name,parsed_article,parsed_qty,parsed_unit')->get();
        if ($items->isEmpty()) {
            return $zero;
        }

        $promptItems = [];
        $byIndex = [];
        $i = 1;
        foreach ($items as $it) {
            $ri = $it->requestItem;
            $promptItems[] = [
                'index' => $i,
                'name' => (string) ($it->item_name ?: $ri?->parsed_name ?: '—'),
                'oem' => $ri?->parsed_article ?: null,
                'qty' => $ri && $ri->parsed_qty ? trim($ri->parsed_qty . ' ' . ($ri->parsed_unit ?: 'шт.')) : null,
            ];
            $byIndex[$i] = $it;
            $i++;
        }

        $text = trim((string) $reply->body_plain);
        if ($text === '') {
            $text = trim(strip_tags((string) $reply->body_html));
        }
        if ($text === '') {
            return $zero;
        }

        try {
            $result = $this->openai->chat(
                $this->prompt->build($promptItems, $text),
                config('services.openai.intent_model', 'gpt-4o-mini'),
                ['temperature' => 0, 'max_tokens' => 1500, 'response_format' => ['type' => 'json_object']],
            );
        } catch (\Throwable $e) {
            Log::warning('SupplierOfferParser: LLM failed', ['inquiry_id' => $inquiry->id, 'message_id' => $reply->id, 'error' => $e->getMessage()]);

            return $zero;
        }

        $parsed = json_decode($result['content'] ?? '', true);
        if (! is_array($parsed) || ! isset($parsed['offers']) || ! is_array($parsed['offers'])) {
            return $zero;
        }

        $counts = $zero;

        DB::transaction(function () use ($parsed, $byIndex, $inquiry, $reply, &$counts) {
            // Идемпотентность: сносим офферы этого письма по этому запросу.
            SupplierOffer::query()
                ->where('supplier_inquiry_id', $inquiry->id)
                ->where('email_message_id', $reply->id)
                ->delete();

            foreach ($parsed['offers'] as $o) {
                if (! is_array($o)) {
                    continue;
                }
                $idx = (int) ($o['index'] ?? 0);
                $item = $byIndex[$idx] ?? null;
                if ($item === null) {
                    continue;
                }
                $outcome = (string) ($o['outcome'] ?? 'skipped');
                if ($outcome === 'skipped') {
                    $counts['skipped']++;
                    continue;
                }
                if (! in_array($outcome, ['quoted', 'refused'], true)) {
                    $counts['skipped']++;
                    continue;
                }

                $price = isset($o['price']) && is_numeric($o['price']) ? (float) $o['price'] : null;

                SupplierOffer::create([
                    'supplier_inquiry_id' => $inquiry->id,
                    'supplier_inquiry_item_id' => $item->id,
                    'email_message_id' => $reply->id,
                    'outcome' => $outcome,
                    'price' => $outcome === 'quoted' ? $price : null,
                    'currency' => $outcome === 'quoted' ? ($this->str($o['currency'] ?? null, 16)) : null,
                    'valid_until_text' => $outcome === 'quoted' ? ($this->str($o['valid_until_text'] ?? null, 255)) : null,
                    'refusal_reason' => $outcome === 'refused' ? ($this->str($o['refusal_reason'] ?? null, 500)) : null,
                    'raw_quote' => $this->str($o['quote'] ?? null, 1000),
                ]);

                // Статус позиции: quoted приоритетнее refused (если несколько ответов).
                if ($outcome === 'quoted' || $item->status === 'pending') {
                    $item->forceFill(['status' => $outcome])->save();
                }
                $counts[$outcome]++;
            }
        });

        Log::info('SupplierOfferParser: parsed reply', ['inquiry_id' => $inquiry->id, 'message_id' => $reply->id] + $counts);

        return $counts;
    }

    private function str(mixed $v, int $max): ?string
    {
        if (! is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v !== '' ? mb_substr($v, 0, $max) : null;
    }
}
