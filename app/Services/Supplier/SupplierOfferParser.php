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

        $items = $this->loadItems($inquiry);
        if ($items->isEmpty()) {
            // Инквайри без позиций — ветка, которой не было при отправке RFQ
            // (запрос ушёл внутреннему коллеге-закупщику, а ответ поставщика
            // пришёл пересылкой и завёл инквайри задним числом по теме). Если
            // ветка привязана к заявке, позиции берём из неё — иначе ответ с
            // ценами молча остаётся без офферов. Кейс M-2026-12942: два мотора
            // (22 кВт €619, 16 кВт в PDF COAM) — ни одной цены в «Поставщиках».
            $items = $this->seedItemsFromRequest($inquiry, $reply);
        }
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
        $text = $this->relevantReplyText($text, $reply);

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

            // Сбой модели (429, сеть) — не «ответ без цены»: вызывающий повторит.
            return $zero + ['failed' => true];
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

            // Интент письма: когда конкретного ответа по позициям нет (0 офферов),
            // фиксируем — задал ли поставщик встречный вопрос НАМ или просто
            // принял/уточняет. Отражает ПОСЛЕДНИЙ обработанный ответ; появление
            // оффера позже сбрасывает.
            $intent = (string) ($parsed['reply_intent'] ?? 'none');
            $hasConcrete = $counts['quoted'] > 0 || $counts['refused'] > 0;
            $inquiry->forceFill([
                'reply_state' => (! $hasConcrete && in_array($intent, ['question_to_us', 'awaiting_supplier'], true))
                    ? $intent : null,
            ])->save();
        });

        Log::info('SupplierOfferParser: parsed reply', ['inquiry_id' => $inquiry->id, 'message_id' => $reply->id] + $counts);

        return $counts;
    }

    /**
     * Позиции инквайри с данными для промпта.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SupplierInquiryItem>
     */
    private function loadItems(SupplierInquiry $inquiry): \Illuminate\Database\Eloquent\Collection
    {
        return $inquiry->items()->with([
            'requestItem:id,parsed_name,parsed_article,parsed_qty,parsed_unit',
            // Позиция-центричный RFQ из «Снабжения» (Фаза 4B): request_item_id=null,
            // имя/OEM берём из каталога — иначе LLM сопоставляет ответ вслепую.
            'catalogItem:id,name,brand_article',
        ])->get();
    }

    /**
     * Засеять позиции инквайри активными позициями связанной заявки — так же,
     * как их создаёт SupplierDispatchService при отправке RFQ. Берём позиции,
     * существовавшие на момент ответа поставщика.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SupplierInquiryItem>
     */
    private function seedItemsFromRequest(SupplierInquiry $inquiry, EmailMessage $reply): \Illuminate\Database\Eloquent\Collection
    {
        $request = $inquiry->relatedRequest;
        if ($request === null) {
            return new \Illuminate\Database\Eloquent\Collection;
        }
        // Только позиции, которые уже были в заявке к моменту ответа: позиции,
        // добавленные позже (другой тред, другой поставщик), этому поставщику
        // не задавали — иначе модель отметит их «нет в прайсе» = ложный отказ.
        $requestItems = $request->items()
            ->where('is_active', true)
            ->when($reply->sent_at !== null, fn ($q) => $q->where('created_at', '<=', $reply->sent_at))
            ->orderBy('id')
            ->get();
        if ($requestItems->isEmpty()) {
            return new \Illuminate\Database\Eloquent\Collection;
        }
        foreach ($requestItems as $ri) {
            SupplierInquiryItem::create([
                'supplier_inquiry_id' => $inquiry->id,
                'request_item_id' => $ri->id,
                'catalog_item_id' => $ri->catalog_item_id,
                'item_name' => $ri->parsed_name,
                'status' => 'pending',
            ]);
        }
        Log::info('SupplierOfferParser: inquiry had no items — seeded from request', [
            'inquiry_id' => $inquiry->id,
            'request_id' => $request->id,
            'items' => $requestItems->count(),
        ]);

        return $this->loadItems($inquiry);
    }

    /** Маркеры начала ПЕРЕСЫЛКИ (не цитаты ответа): дальше идёт чужое письмо целиком. */
    private const FORWARD_MARKERS = '/^\s*(?:-{2,}\s*)?(?:Begin forwarded message|Forwarded message|Пересылаемое сообщение|Пересланное сообщение|Начало переадресованного письма|Weitergeleitete Nachricht|Messaggio inoltrato)\s*:?(?:\s*-{2,})?\s*$/imu';

    /** Своё письмо отсылает к пересылке: «см кп ниже», «see below», «forwarding». */
    private const POINTS_BELOW = '/\bниже\b|\bbelow\b|\bсм\.?\s|\bsee\b|перес[ыл]|forward|inoltr|weiterleit/iu';

    /** Сколько символов пересланного письма отдаём модели. */
    private const MAX_FORWARDED_CHARS = 6000;

    /**
     * Текст ответа, который имеет смысл разбирать: собственные слова поставщика
     * и — когда он ПЕРЕСЫЛАЕТ чужое письмо — тело этого письма.
     *
     * Срез цитат защищает от мусора истории (обсуждение артикулов, старые
     * отказы). Но посредник-закупщик часто отвечает «См кп ниже» и прикладывает
     * предложение реального поставщика пересылкой: кейс M-2026-18330 — UniSystem
     * переслала оффер Fox «N°5 K4TAO1 Unit net price Euro 39,58», а срез по
     * маркеру «Начало переадресованного письма» выкинул его целиком → 0 офферов.
     *
     * Условия, при которых заглядываем в пересылку (все сразу):
     *   • своё письмо короткое ИЛИ явно отсылает к тексту ниже;
     *   • блок начинается с маркера пересылки либо с заголовка From:/От:/Da:
     *     (Outlook без слова «forwarded»);
     *   • отправитель пересланного письма — не наш домен (иначе это эхо нашего
     *     же RFQ без цен).
     * Из пересланного письма берём только его собственный текст: вложенная
     * история (наш RFQ под «Da: UniSystem … Oggetto:») режется тем же срезом.
     */
    private function relevantReplyText(string $text, EmailMessage $reply): string
    {
        $text = trim($text);
        // Строгий срез (без гарда «цитата с самого верха»): нужен, чтобы увидеть
        // хвост даже при коротком «См кп ниже».
        $own = $this->stripQuotedReply($text, guardTop: false);
        $rest = trim(mb_substr($text, mb_strlen($own)));
        if ($rest === '') {
            return $this->stripQuotedReply($text);
        }

        $forwarded = $this->forwardedBody($rest);
        if ($forwarded === null) {
            return $this->stripQuotedReply($text); // обычная цитата — прежнее поведение
        }
        if ($forwarded['body'] === null) {
            // Переслано наше же письмо (RFQ без цен) — его не разбираем даже
            // при коротком собственном тексте.
            return $own !== '' ? $own : $this->stripQuotedReply($text);
        }

        $pointsBelow = preg_match(self::POINTS_BELOW, $own) === 1;
        if (mb_strlen($own) > 400 && ! $pointsBelow) {
            return $this->stripQuotedReply($text); // содержательный ответ сам по себе
        }

        Log::info('SupplierOfferParser: forwarded letter included into parse', [
            'message_id' => $reply->id,
            'from' => $forwarded['from'],
            'chars' => mb_strlen($forwarded['body']),
        ]);

        return trim($own."

[Пересланное письмо от ".$forwarded['from']."]
".$forwarded['body']);
    }

    /**
     * Тело первого пересланного письма из хвоста: заголовок (От/Дата/Кому/Тема)
     * пропускаем, текст берём до следующей вложенной цитаты.
     *
     * @return array{from: string, body: ?string}|null  null — это не пересылка;
     *                                                   body=null — переслано наше же письмо
     */
    private function forwardedBody(string $rest): ?array
    {
        $lines = preg_split('/
?
/', $rest) ?: [];
        // Apple Mail/Outlook пересылают с «> » перед КАЖДОЙ строкой чужого письма
        // («> Начало переадресованного письма: > > Отправитель: …»). Снимаем один
        // уровень цитирования, если он у большинства непустых строк — иначе
        // заголовок не распознать, а тело срежется как цитата.
        $nonEmpty = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        $quoted = count(array_filter($nonEmpty, fn ($l) => preg_match('/^\s*>/', $l) === 1));
        if ($nonEmpty !== [] && $quoted >= (int) ceil(count($nonEmpty) * 0.8)) {
            $lines = array_map(fn ($l) => preg_replace('/^\s*>\s?/', '', $l) ?? $l, $lines);
        }
        $i = 0;
        while ($i < count($lines) && trim($lines[$i]) === '') {
            $i++;
        }
        // Маркер пересылки — необязателен (Outlook даёт сразу заголовок).
        if (isset($lines[$i]) && preg_match(self::FORWARD_MARKERS, $lines[$i]) === 1) {
            $i++;
        }
        // Заголовок: несколько строк «Поле: значение» (с пустыми между ними).
        $from = null;
        $sawSubject = false;
        $headerLines = 0;
        for (; $i < count($lines) && $headerLines < 12; $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                if ($sawSubject) {
                    $i++;
                    break;
                }
                continue;
            }
            if (! preg_match('/^(?:>\s*)?([\p{L}][\p{L} -]{1,24})\s*[：:]\s*(.*)$/u', $line, $m)) {
                break;
            }
            $headerLines++;
            $field = mb_strtolower(trim($m[1]));
            if (in_array($field, ['from', 'от', 'отправитель', 'da', 'von', 'de', 'fra', 'van', '发件人'], true)) {
                $from = trim($m[2]);
            }
            if (in_array($field, ['subject', 'тема', 'oggetto', 'betreff', 'objet', 'onderwerp', '主题'], true)) {
                $sawSubject = true;
            }
        }
        if ($from === null) {
            return null; // заголовка письма нет — обычная цитата, не пересылка
        }

        // Эхо нашего же письма (RFQ без цен) — не берём.
        if ($this->isOurSender($from)) {
            return ['from' => $from, 'body' => null];
        }

        $body = trim(implode("\n", array_slice($lines, $i)));
        if ($body === '') {
            return null;
        }
        // Внутри пересланного письма — своя история (наш RFQ): режем тем же
        // срезом, но без гарда «цитата с самого верха».
        $body = $this->stripQuotedReply($body, guardTop: false);

        return ['from' => $from, 'body' => mb_substr($body, 0, self::MAX_FORWARDED_CHARS)];
    }

    /**
     * Отправитель пересланного письма — мы сами? По домену адреса; а если
     * Outlook/Foxmail дали только имя («From: Alexander R MyZiP»), — по бренду
     * в имени или совпадению с именем пользователя системы.
     */
    private function isOurSender(string $from): bool
    {
        $domains = array_map(fn ($d) => mb_strtolower((string) $d), (array) config('services.mail.internal_domains', []));
        if (preg_match('/[\w.+-]+@([\w.-]+)/u', $from, $em) === 1) {
            return in_array(mb_strtolower($em[1]), $domains, true);
        }

        $name = mb_strtolower(trim($from));
        $brands = array_merge(
            ['myzip', 'мой зип', 'mylift', 'мой лифт', 'mzcorp'],
            array_map(fn ($d) => (string) strtok($d, '.'), $domains),
        );
        foreach ($brands as $brand) {
            if ($brand !== '' && str_contains($name, $brand)) {
                return true;
            }
        }
        try {
            return \App\Models\User::query()
                ->whereRaw('lower(name) = ?', [$name])
                ->exists();
        } catch (\Throwable) {
            return false;
        }
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
    private function stripQuotedReply(string $text, bool $guardTop = true): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $markers = [
            '/^\s*发件人\s*[：:]/mu',                                                   // From (Foxmail/CN)
            '/^\s*(?:From|От(?:правитель)?)\s*:\s*\S.*$/mu',
            '/^\s*(?:Da|Von|De|Fra|Van)\s*:\s*\S.*$/mu',                                   // From: в итальянских/немецких/французских заголовках цитаты                          // From:/От: заголовок цитаты (в т.ч. Foxmail «From:Name» без пробела)
            '/^\s*-{2,}\s*(?:Original Message|Пересланное сообщение)\s*-{2,}/imu',
            self::FORWARD_MARKERS,                                                     // начало пересылки (само пересланное письмо разбирает relevantReplyText)
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

        if ($guardTop && $cut < 30) {
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
        // Инлайн-вложения обычно = подписи/логотипы (мелкие картинки). НО инлайн
        // НЕ-картинка (PDF/Excel/Word/.eml) — реальный документ: поставщики часто
        // шлют прайс-PDF с Content-ID (is_inline=true). Их разбираем. Кейс inq 3380
        // (paulschaab): PDF 1110000200.pdf inline=1 → парсер его пропускал, offer=0.
        // Инлайн-картинки (подписи) по-прежнему отсекаются image-level гардом ниже.
        $attachments = $reply->attachments()
            ->where(function ($q) {
                $q->whereNull('is_inline')
                    ->orWhere('is_inline', false)
                    ->orWhere('mime_type', 'not ilike', 'image/%');
            })
            ->orderBy('id')
            ->limit(self::MAX_ATTACHMENTS)
            ->get();

        $textParts = [];
        $images = [];

        foreach ($attachments as $att) {
            if (count($images) >= self::MAX_IMAGES) {
                // изображений уже достаточно — но текст ещё можем добирать
            }

            // Вложенное письмо (.eml / message/rfc822): поставщик приложил свой
            // ОРИГИНАЛЬНЫЙ ответ (частый кейс «мы же вам высылали» — цена в
            // приложенном оригинале, а НЕ в новом теле). Разбираем его текст.
            // Кейс inquiry 2664 (OSS): оффер USD1343 лежал в .eml от 07.08 —
            // classifyAttachment его не знал, stripQuotedReply в теле его не видел.
            $extLower = strtolower((string) Str::afterLast((string) $att->filename, '.'));
            if ($extLower === 'eml' || str_contains(strtolower((string) $att->mime_type), 'message/rfc822')) {
                $emlText = $this->extractEmlText($att);
                if ($emlText !== '') {
                    $textParts[] = '— вложенное письмо ' . $att->filename . ":\n" . $emlText;
                }
                continue;
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

    /**
     * Текст из вложенного .eml (message/rfc822) — поставщик приложил свой оригинал.
     * Внутри — своя цитата нашего RFQ; оставляем только новый текст поставщика
     * (там цена). Webklex парсит raw-строку.
     */
    private function extractEmlText(\App\Models\EmailAttachment $att): string
    {
        $disk = $att->disk ?: 'local';
        $path = (string) $att->file_path;
        if ($path === '' || ! Storage::disk($disk)->exists($path)) {
            return '';
        }
        try {
            $raw = (string) Storage::disk($disk)->get($path);
            if ($raw === '') {
                return '';
            }
            $msg = \Webklex\PHPIMAP\Message::fromString($raw);
            $text = trim((string) $msg->getTextBody());
            if ($text === '') {
                $html = method_exists($msg, 'getHTMLBody') ? (string) $msg->getHTMLBody() : '';
                $text = trim(strip_tags($html));
            }
            $text = $this->stripQuotedReply($text);

            return mb_substr($text, 0, 4000);
        } catch (\Throwable $e) {
            Log::warning('SupplierOfferParser: .eml parse failed', [
                'attachment_id' => $att->id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
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
