<?php

namespace App\Services\Request;

use App\Enums\DetectorType;
use App\Models\OutboundQuote;
use App\Models\Request;
use App\Models\RequestItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Засев позиций заявки из НАШЕГО ЖЕ КП (outbound_quotation_full), когда клиент
 * присылает это КП обратно с просьбой обновить/дослать.
 *
 * Кейс M-2026-12063: клиент переслал наше «Предложение МЗ-344330» («обновите КП»).
 * Наш КП-PDF корректно НЕ разбирается в позиции (isOwnOutboundDocument), инлайн-
 * спеки из цитаты тоже больше не разбираются (см. RequestItemParsingService), но
 * тогда у заявки не остаётся каталожных позиций. При этом сам КП уже распарсен в
 * outbound_quote_items со сматченным каталогом (M-коды). Берём их как источник
 * истины по позициям.
 *
 * Гард: сеем ТОЛЬКО если у заявки нет ни одной активной каталожно-сматченной
 * позиции. У нормальной заявки (КП сделан ИЗ неё) такие позиции есть → выходим,
 * ничего не трогаем. Идемпотентно.
 */
class OwnQuoteRequestItemSeeder
{
    public const SOURCE = 'own_quote_seed';

    /**
     * @param  ?int  $sourceMessageId  письмо-источник КП: несматченный авто-шум
     *                                 ИЗ ЭТОГО ЖЕ письма (позиции из процитированного
     *                                 КП-треда) деактивируется; позиции из других
     *                                 писем и ручные — не трогаются.
     * @return int  сколько позиций засеяно
     */
    public function seedForRequest(Request $request, ?int $sourceMessageId = null): int
    {
        // Наше исходящее КП по заявке с распарсенными позициями (последнее).
        $quote = OutboundQuote::query()
            ->where('request_id', $request->id)
            ->where('document_type', DetectorType::OutboundQuotationFull->value)
            ->whereIn('status', [OutboundQuote::STATUS_MATCHED, OutboundQuote::STATUS_PARSED])
            ->latest('id')
            ->first();
        if (! $quote) {
            return 0;
        }

        $matched = $quote->items()
            ->whereNotNull('matched_catalog_item_id')
            ->where('is_analog', false)
            ->orderBy('position')
            ->get();
        if ($matched->isEmpty()) {
            return 0;
        }

        // Гард: у заявки уже есть каталожно-сматченная активная позиция → нормальная
        // заявка, КП сделан из неё. Не вмешиваемся.
        $hasMatched = $request->items()
            ->where('is_active', true)
            ->whereNotNull('catalog_item_id')
            ->exists();
        if ($hasMatched) {
            return 0;
        }

        return DB::transaction(function () use ($request, $matched, $sourceMessageId) {
            // Деактивировать несматченный авто-шум ИЗ письма-источника КП (позиции
            // из процитированного треда — как «КВШ» из тела/спеков). Ручные и позиции
            // из других писем сохраняем.
            if ($sourceMessageId !== null) {
                $request->items()
                    ->where('is_active', true)
                    ->whereNull('catalog_item_id')
                    ->where('source_email_message_id', $sourceMessageId)
                    ->where(fn ($q) => $q->whereNull('data_source')->orWhere('data_source', '!=', 'manual'))
                    ->update(['is_active' => false]);
            }

            $pos = (int) ($request->items()->max('position') ?? 0);
            $seeded = 0;
            foreach ($matched as $qi) {
                // Не дублируем уже присутствующую каталожную позицию.
                $exists = $request->items()
                    ->where('is_active', true)
                    ->where('catalog_item_id', $qi->matched_catalog_item_id)
                    ->exists();
                if ($exists) {
                    continue;
                }
                $pos++;
                RequestItem::create([
                    'request_id' => $request->id,
                    'position' => $pos,
                    'parsed_name' => mb_substr(trim((string) $qi->raw_name), 0, 250) ?: null,
                    'parsed_article' => $qi->raw_article,
                    'parsed_brand' => $qi->raw_brand,
                    'parsed_qty' => $qi->quantity !== null ? (float) $qi->quantity : 1,
                    'parsed_unit' => $qi->unit_measure ?: 'шт',
                    'catalog_item_id' => $qi->matched_catalog_item_id,
                    'data_source' => self::SOURCE,
                    'is_active' => true,
                    'source_email_message_id' => $sourceMessageId,
                    'quality_assessment_payload' => [
                        'catalog_match' => [
                            'method' => 'own_quote_seed',
                            'matched_at' => now()->toIso8601String(),
                            'catalog_item_id' => $qi->matched_catalog_item_id,
                            'source_outbound_quote_id' => $qi->outbound_quote_id,
                        ],
                    ],
                ]);
                $seeded++;
            }

            if ($seeded > 0) {
                Log::info('OwnQuoteRequestItemSeeder: seeded request items from own КП', [
                    'request_id' => $request->id,
                    'seeded' => $seeded,
                    'source_message_id' => $sourceMessageId,
                ]);
            }

            return $seeded;
        });
    }
}
