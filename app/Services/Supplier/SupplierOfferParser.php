<?php

namespace App\Services\Supplier;

use App\Models\EmailMessage;
use App\Models\SupplierInquiry;
use App\Models\SupplierInquiryItem;
use App\Models\SupplierOffer;
use App\Prompts\Suppliers\ParseSupplierReplyPrompt;
use App\Services\AI\OpenAIChatService;
use App\Services\Quotes\OutboundQuoteParsingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Разбор ответа поставщика на RFQ в предложения по позициям (Фаза 3.3).
 * Знаем запрошенные позиции (supplier_inquiry_items) → LLM сопоставляет ответ:
 * quoted (цена) / refused / skipped. Пишет SupplierOffer + обновляет статус
 * позиций. Идемпотентно по (inquiry, message): повторный разбор перезаписывает
 * офферы этого письма. Fail-safe.
 */
class SupplierOfferParser
{
    /** Лимиты разбора вложений (контроль стоимости/токенов). */
    private const MAX_ATTACHMENTS = 6;
    private const MAX_IMAGES = 6;

    public function __construct(
        private readonly OpenAIChatService $openai,
        private readonly ParseSupplierReplyPrompt $prompt,
        private readonly OutboundQuoteParsingService $extractor,
    ) {
    }

    /**
     * @return array{quoted:int, refused:int, skipped:int}
     */
    public function parse(SupplierInquiry $inquiry, EmailMessage $reply): array
    {
        $zero = ['quoted' => 0, 'refused' => 0, 'skipped' => 0];

        $items = $inquiry->items()->with([
            'requestItem:id,parsed_name,parsed_article,parsed_qty,parsed_unit',
            // Позиция-центричный RFQ из «Снабжения» (Фаза 4B): request_item_id=null,
            // имя/OEM берём из каталога — иначе LLM сопоставляет ответ вслепую.
            'catalogItem:id,name,brand_article',
        ])->get();
        if ($items->isEmpty()) {
            return $zero;
        }

        $promptItems = [];
        $byIndex = [];
        $i = 1;
        foreach ($items as $it) {
            $ri = $it->requestItem;
            $ci = $it->catalogItem;
            $promptItems[] = [
                'index' => $i,
                'name' => (string) ($it->item_name ?: $ri?->parsed_name ?: $ci?->name ?: '—'),
                'oem' => $ri?->parsed_article ?: ($ci?->brand_article ?: null),
                'qty' => $ri && $ri->parsed_qty ? trim($ri->parsed_qty . ' ' . ($ri->parsed_unit ?: 'шт.')) : null,
            ];
            $byIndex[$i] = $it;
            $i++;
        }

        $text = trim((string) $reply->body_plain);
        if ($text === '') {
            $text = trim(strip_tags((string) $reply->body_html));
        }
        // Разбираем ТОЛЬКО новый текст ответа поставщика — цитату нашего же
        // письма (и предыдущей переписки) срезаем. Иначе LLM цепляется за
        // содержимое цитаты: путает обсуждение артикулов/«альтернатива» в
        // истории с отказом (кейсы inquiry 1143 «M02690/M13261», 1231).
        $text = $this->stripQuotedReply($text);

        // Вложения-прайсы: текст (PDF/Excel/Word) + изображения (фото/скан) для Vision.
        [$attachmentText, $images] = $this->extractAttachments($reply);

        // Нечего разбирать только если пусто И в письме, И во вложениях.
        if ($text === '' && $attachmentText === '' && $images === []) {
            return $zero;
        }

        // Изображения → нужен Vision (gpt-4o); иначе дешёвый mini.
        $model = $images !== []
            ? config('services.openai.vision_model', 'gpt-4o')
            : config('services.openai.intent_model', 'gpt-4o-mini');

        try {
            $result = $this->openai->chat(
                $this->prompt->build($promptItems, $text, $attachmentText, $images),
                $model,
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

    /**
     * Срезать цитируемую историю из ответа поставщика — оставить только новый
     * текст (обычно top-posting). Режем всё, начиная с САМОГО РАННЕГО маркера
     * начала цитаты/пересылки. Маркеры покрывают RU/EN/CN клиентов, включая
     * Foxmail («发件人：» = «От:»), которым пользуются азиатские поставщики.
     *
     * Гард против bottom-posting: если осмысленного текста ДО маркера почти
     * нет (< 30 симв), вероятно ответ написан ПОД цитатой — тогда не режем,
     * отдаём как есть (лучше лишний контекст, чем потерять сам ответ).
     */
    private function stripQuotedReply(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $markers = [
            '/^\s*发件人\s*[：:]/mu',                                                   // From (Foxmail/CN)
            '/^\s*(?:From|От(?:правитель)?)\s*:\s*\S.*$/mu',                          // From:/От: заголовок цитаты (в т.ч. Foxmail «From:Name» без пробела)
            '/^\s*-{2,}\s*(?:Original Message|Пересланное сообщение)\s*-{2,}/imu',
            '/^\s*[-_]{10,}\s*$/mu',                                                   // Outlook/Foxmail-разделитель (длинная линия)
            '/^.*\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}.*(?:пишет|написал(?:а)?|wrote)\s*[:：]\s*$/imu', // «15.07.2026 …, X пишет:»
            '/^\s*On\b.{0,160}\bwrote:\s*$/imu',                                      // «On … wrote:»
            '/^\s*>\s?/mu',                                                            // >-цитата
        ];

        $cut = mb_strlen($text);
        foreach ($markers as $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                $charPos = mb_strlen(substr($text, 0, $m[0][1]));
                $cut = min($cut, $charPos);
            }
        }

        if ($cut < 30) {
            return $text; // bottom-posting или цитата с самого верха — не режем
        }

        return trim(mb_substr($text, 0, $cut));
    }

    /**
     * Достаём текст и изображения из вложений ответа поставщика (прайсы).
     * Текст — из PDF/Excel/Word; изображения — фото/сканы и страницы PDF без
     * текстового слоя (для Vision). Inline (подписи/логотипы) пропускаем.
     *
     * @return array{0:string, 1:array<int,string>}  [attachmentText, images]
     */
    private function extractAttachments(EmailMessage $reply): array
    {
        $attachments = $reply->attachments()
            ->where(fn ($q) => $q->whereNull('is_inline')->orWhere('is_inline', false))
            ->orderBy('id')
            ->limit(self::MAX_ATTACHMENTS)
            ->get();

        $textParts = [];
        $images = [];

        foreach ($attachments as $att) {
            if (count($images) >= self::MAX_IMAGES) {
                // изображений уже достаточно — но текст ещё можем добирать
            }
            $type = $this->classifyAttachment((string) $att->filename, (string) $att->mime_type);
            if ($type === null) {
                continue;
            }
            // Встроенная подпись/логотип (content_id или совсем мелкое изображение)
            // — не прайс, не тратим на неё разбор/Vision.
            if ($type === 'image' && ($att->content_id !== null || (int) $att->size_bytes < 30000)) {
                continue;
            }

            $disk = $att->disk ?: 'local';
            $path = (string) $att->file_path;
            if ($path === '' || ! Storage::disk($disk)->exists($path)) {
                continue;
            }
            $abs = Storage::disk($disk)->path($path);

            try {
                $content = $this->extractor->extractContent($abs, $type, isAbsolute: true);
            } catch (\Throwable $e) {
                Log::warning('SupplierOfferParser: attachment extract failed', [
                    'message_id' => $reply->id, 'attachment_id' => $att->id, 'error' => $e->getMessage(),
                ]);
                continue;
            }

            $extracted = trim((string) ($content['text'] ?? ''));
            $contentImages = is_array($content['images'] ?? null) ? $content['images'] : [];

            if ($extracted !== '') {
                $textParts[] = '— ' . $att->filename . ":\n" . $extracted;
            }

            // Изображения: image-вложение (подписи уже отфильтрованы выше) — всегда;
            // PDF — только если текстового слоя по сути нет (скан).
            $weakText = mb_strlen($extracted) < 40;
            if ($type === 'image' || ($type === 'pdf' && $weakText)) {
                foreach ($contentImages as $img) {
                    if (count($images) >= self::MAX_IMAGES) {
                        break;
                    }
                    if (is_string($img) && $img !== '') {
                        $images[] = $img;
                    }
                }
            }
        }

        return [trim(implode("\n\n", $textParts)), $images];
    }

    /**
     * Тип файла для OutboundQuoteParsingService::extractContent по расширению
     * (приоритет) / mime. null — не разбираем.
     */
    private function classifyAttachment(string $filename, string $mime): ?string
    {
        $ext = strtolower((string) Str::afterLast($filename, '.'));
        $map = [
            'pdf' => 'pdf',
            'xlsx' => 'xlsx', 'xls' => 'xls', 'xlsm' => 'xlsx',
            'docx' => 'docx', 'doc' => 'doc',
            'png' => 'image', 'jpg' => 'image', 'jpeg' => 'image',
            'gif' => 'image', 'webp' => 'image', 'bmp' => 'image', 'tif' => 'image', 'tiff' => 'image',
        ];
        if (isset($map[$ext])) {
            return $map[$ext];
        }

        $mime = strtolower($mime);
        return match (true) {
            str_contains($mime, 'pdf') => 'pdf',
            str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel') => 'xlsx',
            str_contains($mime, 'word') || str_contains($mime, 'msword') => 'docx',
            str_starts_with($mime, 'image/') => 'image',
            default => null,
        };
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
