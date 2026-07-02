<?php

namespace App\Services\Requests;

use App\Enums\EmailCategory;
use App\Enums\MailDirection;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Request;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

/**
 * Экспорт переписки заявки в PDF (опционально в ZIP с файлами-вложениями).
 *
 * По умолчанию экспортируется весь тред заявки; выборочно — по списку id писем.
 * Текст письма и КАРТИНКИ (inline cid: + фото-вложения) встраиваются прямо в PDF
 * как data-URI (dompdf работает с isRemoteEnabled=false, поэтому маршруты
 * attachments.* недоступны — нужны именно data-URI). Прочие файлы (PDF/Excel/doc
 * и т.п.) в PDF не помещаются — контроллер кладёт их рядом с PDF в ZIP-архив.
 *
 * Картинки прогоняются через VisionImageDownscaler (ужимает крупные фото с
 * телефона до 2048px/JPEG, fail-soft) — иначе PDF из десятка фото по 6-7 МБ
 * раздувается и dompdf рискует упасть по памяти.
 *
 * Рендер/шрифты повторяют QuotationPdfService (PT Sans из resources/fonts,
 * программная регистрация, writable font cache в storage).
 */
class CorrespondenceExportService
{
    /**
     * Тред заявки для экспорта. Та же выборка, что в Requests\Detail:
     * письма заявки без cross-mailbox копий, без черновиков (в экспорт-документ
     * неотправленные драфты не попадают). Опционально фильтруется по $ids.
     *
     * @param  int[]|null  $ids  выбранные id писем (null → весь тред)
     * @return Collection<int, EmailMessage>
     */
    public function buildThread(Request $request, ?array $ids = null): Collection
    {
        $query = EmailMessage::query()
            ->where('related_request_id', $request->id)
            ->whereRaw("(detected_artifacts->>'cross_mailbox_copy_of') IS NULL")
            ->where('is_draft', false)
            ->with([
                'attachments:id,email_message_id,filename,size_bytes,mime_type,content_id,is_inline,file_path,disk',
                'mailbox:id,email,name',
            ])
            ->orderByRaw('sent_at IS NULL, sent_at ASC')
            ->orderBy('id');

        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            $query->whereIn('id', $ids ?: [0]);
        }

        return $query->get();
    }

    /**
     * Файлы-вложения для ZIP: всё, что НЕ картинка (картинки уже в PDF).
     * Учитываем только реально существующие на диске файлы.
     *
     * @param  Collection<int, EmailMessage>  $thread
     * @return Collection<int, EmailAttachment>
     */
    public function bundleAttachments(Collection $thread): Collection
    {
        return $thread
            ->flatMap(fn (EmailMessage $m) => $m->attachments)
            ->reject(fn (EmailAttachment $a) => $this->isImage($a))
            ->values();
    }

    /**
     * Сгенерировать PDF переписки и вернуть бинарный контент.
     *
     * @param  Collection<int, EmailMessage>  $thread
     */
    public function render(Request $request, Collection $thread): string
    {
        $messages = $thread->map(fn (EmailMessage $m) => $this->presentMessage($m))->all();

        $payload = [
            'request' => $request,
            'messages' => $messages,
            'company' => (array) config('services.company'),
            'generatedAt' => now()->setTimezone(config('app.timezone'))->format('d.m.Y H:i'),
        ];

        $html = View::make('requests.correspondence-pdf', $payload)->render();

        $options = new Options();
        $options->set('defaultFont', 'PT Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('dpi', 72);
        $options->set('chroot', [resource_path(), public_path()]);

        $fontDir = storage_path('app/dompdf/fonts');
        if (! is_dir($fontDir)) {
            @mkdir($fontDir, 0775, true);
        }
        $options->set('fontDir', $fontDir);
        $options->set('fontCache', $fontDir);

        $dompdf = new Dompdf($options);
        $this->registerFonts($dompdf);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Имя файла для скачивания: «Переписка M-2026-NNNN.pdf|zip».
     */
    public function filename(Request $request, string $ext): string
    {
        $code = preg_replace('/[^A-Za-zА-Яа-я0-9\-]+/u', '-', (string) $request->internal_code) ?: 'request';

        return "Переписка {$code}.{$ext}";
    }

    /**
     * View-model одного письма для шаблона: подготовленный body (cid→data-URI,
     * вырезанные style/script), встроенные картинки и список файлов-вложений
     * (для пометки «в архиве»).
     *
     * @return array<string, mixed>
     */
    private function presentMessage(EmailMessage $m): array
    {
        $images = $m->attachments
            ->filter(fn (EmailAttachment $a) => $this->isImage($a) && ! $a->is_inline)
            ->map(function (EmailAttachment $a) {
                $bytes = $this->safeContents($a);

                return $bytes === null ? null : [
                    'name' => $a->filename,
                    'data' => $this->imageDataUri($bytes, $a->mime_type, $a->id),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $files = $m->attachments
            ->reject(fn (EmailAttachment $a) => $this->isImage($a))
            ->map(fn (EmailAttachment $a) => [
                'name' => $a->filename,
                'kb' => $a->size_bytes ? (int) round($a->size_bytes / 1024) : null,
            ])
            ->values()
            ->all();

        return [
            'outbound' => $m->direction === MailDirection::Outbound,
            'author' => $m->from_name ?: $m->from_email,
            'from_email' => $m->from_email,
            'sent_at' => $m->sent_at?->setTimezone(config('app.timezone'))->format('d.m.Y H:i'),
            'mailbox' => $m->mailbox?->email,
            'category' => $m->category
                ? (EmailCategory::tryFrom($m->category)?->label() ?? $m->category)
                : null,
            'body' => $this->prepareBody($m),
            'images' => $images,
            'files' => $files,
        ];
    }

    /**
     * HTML тела письма для dompdf: вырезаем head/style/script, разворачиваем
     * body-обёртку, cid: → data-URI. Fallback на plain-text (экранированный).
     */
    private function prepareBody(EmailMessage $m): ?string
    {
        if ($m->body_html) {
            $html = $this->stripUnrenderableChars($m->body_html);

            if (preg_match('#<body[^>]*>(.*)</body>#is', $html, $mm)) {
                $html = $mm[1];
            }

            // Изоляция: dompdf не исполняет JS, но <style> письма мог бы
            // протечь на следующие письма одного документа.
            $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;

            // В экспорт идут только сами письма — процитированную переписку
            // («портянки» предыдущих писем) вырезаем.
            $html = $this->stripQuotedBlocks($html);

            // Кириллица в подписях рендерилась как «???»: подписи из почтовых
            // клиентов (Thunderbird moz-signature и т.п.) несут собственный
            // font-family (Helvetica/Arial), а для этих семейств dompdf берёт
            // встроенный Type1-шрифт БЕЗ кириллицы. Снимаем декларации шрифта из
            // inline-style и <font face=…>, чтобы текст наследовал PT Sans
            // (defaultFont). Доп. страховка — !important в шаблоне.
            $html = preg_replace('/font-family\s*:[^;"\']*;?/i', '', $html) ?? $html;
            $html = preg_replace('/(<font\b[^>]*?)\s+face\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '$1', $html) ?? $html;

            $html = $this->resolveRemoteImages($html);

            return $this->inlineCidImages($html, $m);
        }

        if ($m->body_plain) {
            $plain = $this->stripQuotedPlain($this->stripUnrenderableChars($m->body_plain));

            return '<pre style="white-space:pre-wrap;font-family:inherit;margin:0;font-size:11px;">'
                . e($plain) . '</pre>';
        }

        return null;
    }

    /**
     * Удаляет символы, которые dompdf не может отрисовать через PT Sans и рисует
     * «тофу»-боксами («XX»):
     *  - невидимые / zero-width (U+200B ZWSP, U+200C/D, BOM, word-joiner,
     *    directional marks, soft hyphen). Кейс M-2026-5290: клиент вставил по
     *    7× U+200B перед номерами пунктов списка → «XXXXXXX» вместо «4. Канат».
     *  - emoji / пиктографы (misc symbols, dingbats, supplemental symbols,
     *    variation selectors, keycap) — в PT Sans нет emoji-глифов. Кейс
     *    M-2026-5770: 📝/🏢 на кнопках «Открыть форму ответа» / «Кабинет
     *    поставщика» письма поставщику → «XX». Вырезаем только иконку, текст
     *    кнопки остаётся.
     * Типографику (— · № «») не трогаем — она вне этих диапазонов.
     */
    private function stripUnrenderableChars(string $s): string
    {
        return preg_replace(
            '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}\x{00AD}'
            . '\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{20E3}\x{1F000}-\x{1FAFF}]/u',
            '',
            $s
        ) ?? $s;
    }

    /**
     * Удаляет процитированные блоки из HTML письма (в экспорт идут только сами
     * письма, без цитат предыдущей переписки). Зеркалит детект из
     * Requests\Detail::collapseQuotedBlocks, но НЕ сворачивает в <details>, а
     * вырезает: blockquote / gmail_quote / yahoo_quoted + attribution-строки
     * («Кому:/От:/… написал(а):») непосредственно перед цитатой, а также
     * Outlook-стиль без blockquote — реплай-хедер «From:/Sent:/To:»
     * («От:/Отправлено:/Кому:») и всё после него.
     */
    private function stripQuotedBlocks(string $html): string
    {
        if (stripos($html, '<blockquote') === false
            && stripos($html, 'gmail_quote') === false
            && stripos($html, 'yahoo_quoted') === false
            && preg_match('/(From|От)\s*:/iu', $html) !== 1
            && stripos($html, 'Original Message') === false
            && stripos($html, 'Исходное сообщение') === false) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"?><div id="mylift-export-root">' . $html . '</div>';
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($doc);

        // Порядок как в Detail: сначала Outlook-хедер (вырезает и вложенные в
        // хвост blockquote), потом обычные blockquote-цитаты.
        $changed = $this->stripOutlookStyleQuote($doc, $xpath);
        $changed = $this->stripBlockquoteNodes($doc, $xpath) || $changed;

        if (! $changed) {
            return $html;
        }

        $root = $doc->getElementById('mylift-export-root');
        if (! $root) {
            return $html;
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    /**
     * Outlook-стиль цитирования без blockquote: реплай-хедер + оригинал письма
     * обычными абзацами до конца. Логика поиска — копия
     * Requests\Detail::collapseOutlookStyleQuote, но узлы удаляются.
     * Форвард целиком (нет собственного текста до хедера) не трогаем.
     */
    private function stripOutlookStyleQuote(\DOMDocument $doc, \DOMXPath $xpath): bool
    {
        $candidates = $xpath->query('//p[not(ancestor::blockquote)] | //div[not(ancestor::blockquote)]');
        if ($candidates === false || $candidates->length === 0) {
            return false;
        }

        $header = null;
        foreach ($candidates as $el) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $el->textContent ?? ''));
            if ($text === '' || mb_strlen($text) > 600) {
                continue;
            }
            if ($this->looksLikeReplyHeader($text)) {
                $header = $el;
                break;
            }
        }
        if ($header === null) {
            return false;
        }

        $node = $header;
        while ($node->parentNode instanceof \DOMElement
            && $node->parentNode->getAttribute('id') !== 'mylift-export-root'
            && ! $this->hasMeaningfulPrecedingSibling($node)) {
            $node = $node->parentNode;
        }
        if (! $this->hasMeaningfulPrecedingSibling($node)) {
            return false;
        }

        $toRemove = [$node];
        for ($n = $node->nextSibling; $n !== null; $n = $n->nextSibling) {
            $toRemove[] = $n;
        }
        foreach ($toRemove as $n) {
            $n->parentNode?->removeChild($n);
        }

        return true;
    }

    /** Прежний проход: вырезание blockquote / gmail_quote / yahoo_quoted. */
    private function stripBlockquoteNodes(\DOMDocument $doc, \DOMXPath $xpath): bool
    {
        $nodes = $xpath->query(
            '//blockquote[not(ancestor::blockquote)]'
            . ' | //div[contains(@class, "gmail_quote") and not(ancestor::blockquote)]'
            . ' | //div[contains(@class, "yahoo_quoted") and not(ancestor::blockquote)]'
        );
        if ($nodes === false || $nodes->length === 0) {
            return false;
        }

        // Сначала собираем узлы в массив — живая NodeList ломается при удалении.
        $toRemove = [];
        foreach ($nodes as $bq) {
            $toRemove[] = $bq;
            $prev = $bq->previousSibling;
            while ($prev !== null) {
                if ($this->looksLikeQuoteAttribution($prev)
                    || ($prev->nodeType === XML_TEXT_NODE && trim($prev->textContent) === '')
                    || ($prev->nodeType === XML_ELEMENT_NODE && strtolower($prev->nodeName) === 'br')
                ) {
                    $toRemove[] = $prev;
                    $prev = $prev->previousSibling;
                    continue;
                }
                break;
            }
        }
        foreach ($toRemove as $node) {
            $node->parentNode?->removeChild($node);
        }

        return true;
    }

    /**
     * Реплай-хедер Outlook/корп-систем: «From:» + минимум два поля из
     * Sent/To/Subject/Date (или русские аналоги), либо «--- Original Message ---».
     * Копия Requests\Detail::looksLikeReplyHeader.
     */
    private function looksLikeReplyHeader(string $text): bool
    {
        if (preg_match('/^-{2,}\s*(Original Message|Исходное сообщение|Forwarded message|Пересылаемое сообщение|Перенаправленное сообщение)/iu', $text)) {
            return true;
        }
        if (preg_match('/(^|\s)(From|От)\s*:/iu', $text) !== 1) {
            return false;
        }
        $fields = 0;
        foreach (['/(Sent|Отправлено)\s*:/iu', '/(To|Кому)\s*:/iu', '/(Subject|Тема)\s*:/iu', '/(Date|Дата)\s*:/iu'] as $p) {
            if (preg_match($p, $text) === 1) {
                $fields++;
            }
        }

        return $fields >= 2;
    }

    /** Есть ли перед нодой содержательный контент письма (копия из Detail). */
    private function hasMeaningfulPrecedingSibling(\DOMNode $node): bool
    {
        for ($p = $node->previousSibling; $p !== null; $p = $p->previousSibling) {
            if ($p->nodeType === XML_TEXT_NODE && trim($p->textContent) !== '') {
                return true;
            }
            if ($p->nodeType === XML_ELEMENT_NODE) {
                if (in_array(strtolower($p->nodeName), ['br', 'hr'], true)) {
                    continue;
                }
                if (trim($p->textContent) !== '') {
                    return true;
                }
                if ($p instanceof \DOMElement && $p->getElementsByTagName('img')->length > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Эвристика attribution-строки перед цитатой (RU/EN). Копия логики из
     * Requests\Detail::looksLikeQuoteAttribution.
     */
    private function looksLikeQuoteAttribution(\DOMNode $node): bool
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return false;
        }
        if (! in_array(strtolower($node->nodeName), ['div', 'p', 'span', 'blockquote'], true)) {
            return false;
        }
        $text = trim($node->textContent);
        if ($text === '' || mb_strlen($text) > 600) {
            return false;
        }
        $patterns = [
            '/^\s*Кому\s*:/iu',
            '/^\s*Тема\s*:/iu',
            '/^\s*От\s*:/iu',
            '/^\s*Дата\s*:/iu',
            '/^\s*To\s*:/i',
            '/^\s*From\s*:/i',
            '/^\s*Subject\s*:/i',
            '/^\s*Date\s*:/i',
            '/-{3,}\s*(Original message|Перенаправленное сообщение|Forwarded message|Пересылаемое сообщение)/iu',
            '/\d{1,2}[\.\/]\d{1,2}[\.\/]\d{2,4}.*(написал|пиш[еу]т|писал|wrote|writes)/iu',
            // Текстовый месяц: «23 июн. 2026 г., … написал(а):» — точка после
            // сокращённого месяца («июн.») не должна ломать матч.
            '/\d{1,2}\s+\p{L}+\.?\s+\d{4}.*(написал|пиш[еу]т|писал|wrote|writes)/iu',
            // Общий хвост attribution-строки: «… написал(а):» / «… пишет:» /
            // «… wrote:» (разные клиенты: Apple/Yandex — «написал(а)», Mail.ru — «пишет»).
            '/(написал\(а\)|написал[аи]?|пиш[еу]т|писал[аи]?|wrote|writes)\s*:?\s*$/iu',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Чистка цитат в plain-text письме: обрываем на attribution-строке
     * («… написал(а):» / «--- Original message ---»), на Outlook-хедере
     * («From:/От:» + в ближайших строках «Sent:/Отправлено:/Кому:/…»)
     * и убираем `>`-цитаты.
     */
    private function stripQuotedPlain(string $text): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $out = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match('/^\s*-{2,}\s*(Original message|Исходное сообщение|Перенаправленное|Forwarded|Пересылаемое)/iu', $line)
                || preg_match('/(написал\(а\)|написал[аи]?|пиш[еу]т|писал[аи]?|wrote|writes)\s*:?\s*$/iu', $line)
            ) {
                break;
            }
            // Outlook-хедер: «From:/От: …» и в пределах 3 следующих строк второе поле.
            if (preg_match('/^\s*(From|От)\s*:\s*\S/iu', $line)) {
                $isHeader = false;
                for ($j = $i + 1; $j <= min($i + 3, $count - 1); $j++) {
                    if (preg_match('/^\s*(Sent|Отправлено|To|Кому|Date|Дата|Subject|Тема)\s*:/iu', $lines[$j])) {
                        $isHeader = true;
                        break;
                    }
                }
                if ($isHeader && trim(implode('', $out)) !== '') {
                    break;
                }
            }
            if (preg_match('/^\s*>/', $line)) {
                continue;
            }
            $out[] = $line;
        }

        return rtrim(implode("\n", $out));
    }

    /**
     * Заменяет cid:-ссылки в HTML на data-URI из соответствующего вложения.
     * Картинки прогоняются через downscaler. Нет вложения/файла — ссылку
     * оставляем как есть (dompdf просто покажет «битую» картинку).
     */
    private function inlineCidImages(string $html, EmailMessage $m): string
    {
        return preg_replace_callback(
            '/(src|href)\s*=\s*(["\'])cid:([^"\']+)\2/i',
            function ($match) use ($m) {
                $cid = trim($match[3], "<> \t");
                $att = $m->attachments->first(
                    fn (EmailAttachment $a) => trim((string) $a->content_id, "<> \t") === $cid
                );
                if (! $att) {
                    return $match[0];
                }
                $bytes = $this->safeContents($att);
                if ($bytes === null) {
                    return $match[0];
                }
                $uri = $this->imageDataUri($bytes, $att->mime_type, $att->id);

                return $match[1] . '=' . $match[2] . $uri . $match[2];
            },
            $html
        ) ?? $html;
    }

    /**
     * data-URI картинки для PDF: ужимаем до ~900px по длинной стороне + JPEG q78.
     * На странице фото показывается мелко (~150px), поэтому крупный исходник
     * (фото с телефона 3–6 МБ, 4000px) только раздувает PDF и ест память dompdf.
     * Fail-soft: нет GD / битый кадр / гигапиксель → отдаём оригинал как есть.
     */
    private function imageDataUri(string $bytes, ?string $mime, ?int $id): string
    {
        $mime = $mime ?: 'image/jpeg';
        $raw = fn () => 'data:' . $mime . ';base64,' . base64_encode($bytes);

        if (! function_exists('imagecreatefromstring')) {
            return $raw();
        }

        $maxEdge = 900;
        $src = null;
        $canvas = null;
        try {
            $dim = @getimagesizefromstring($bytes);
            if (is_array($dim) && isset($dim[0], $dim[1]) && ($dim[0] * $dim[1]) > 60_000_000) {
                return $raw();
            }
            $src = @imagecreatefromstring($bytes);
            if ($src === false) {
                return $raw();
            }
            $w = imagesx($src);
            $h = imagesy($src);
            if ($w < 1 || $h < 1) {
                return $raw();
            }

            $long = max($w, $h);
            $ratio = $long > $maxEdge ? $maxEdge / $long : 1.0;
            $nw = max(1, (int) round($w * $ratio));
            $nh = max(1, (int) round($h * $ratio));

            $canvas = imagecreatetruecolor($nw, $nh);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $nw, $nh, $white);
            imagecopyresampled($canvas, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

            ob_start();
            imagejpeg($canvas, null, 78);
            $out = (string) ob_get_clean();

            // Без ресайза и без выигрыша в байтах — смысла перепаковывать нет.
            if ($out === '' || ($ratio === 1.0 && strlen($out) >= strlen($bytes))) {
                return $raw();
            }

            return 'data:image/jpeg;base64,' . base64_encode($out);
        } catch (\Throwable $e) {
            Log::warning('CorrespondenceExport: image downscale failed', [
                'attachment_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $raw();
        } finally {
            if ($src instanceof \GdImage) {
                imagedestroy($src);
            }
            if ($canvas instanceof \GdImage) {
                imagedestroy($canvas);
            }
        }
    }

    /**
     * Обработка удалённых картинок (http/https src) в теле письма.
     *
     * dompdf работает с isRemoteEnabled=false (защита от SSRF и трекинг-пикселей
     * из писем), поэтому удалённую картинку он не грузит, а рисует её alt-текст —
     * длинный alt вылезает из ячейки и НАЕЗЖАЕТ на соседний текст (кейс логотипа
     * подписи «Мой ЗиП…»). Картинки с наших доменов инлайним из public/, любые
     * другие удалённые картинки вырезаем (логотипы/пиксели — не контент).
     */
    private function resolveRemoteImages(string $html): string
    {
        return preg_replace_callback(
            '/<img\b[^>]*>/i',
            function ($m) {
                if (! preg_match('/\bsrc\s*=\s*(["\'])(https?:\/\/[^"\']+)\1/i', $m[0], $s)) {
                    return $m[0]; // не удалённая (cid:/data:/относительная) — не трогаем
                }
                $local = $this->mapToLocalAsset($s[2]);
                if ($local !== null && is_file($local)) {
                    $data = @file_get_contents($local);
                    if ($data !== false) {
                        $mime = $this->guessImageMime($local);
                        $uri = 'data:' . $mime . ';base64,' . base64_encode($data);

                        return preg_replace(
                            '/\bsrc\s*=\s*(["\'])https?:\/\/[^"\']+\1/i',
                            'src="' . $uri . '"',
                            $m[0],
                            1
                        ) ?? $m[0];
                    }
                }

                return ''; // чужая удалённая картинка → выкидываем (без alt-наезда)
            },
            $html
        ) ?? $html;
    }

    /**
     * Маппинг URL картинки с наших доменов на файл в public/. Только наши хосты,
     * без path-traversal. Иначе null (картинка будет удалена вызывающим кодом).
     */
    private function mapToLocalAsset(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $path = ltrim($parts['path'] ?? '', '/');

        $ourHosts = ['mzcorp.ru', 'www.mzcorp.ru', 'myzip.ru', 'www.myzip.ru', 'mylift.ru', 'www.mylift.ru'];
        if (! in_array($host, $ourHosts, true) || $path === '' || str_contains($path, '..')) {
            return null;
        }

        return public_path($path);
    }

    private function guessImageMime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private function isImage(EmailAttachment $a): bool
    {
        return str_starts_with((string) $a->mime_type, 'image/');
    }

    /**
     * Содержимое вложения с диска, fail-soft (битый путь не валит экспорт).
     */
    private function safeContents(EmailAttachment $a): ?string
    {
        try {
            return $a->contents();
        } catch (\Throwable $e) {
            Log::warning('CorrespondenceExport: attachment read failed', [
                'attachment_id' => $a->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Регистрируем PT Sans / PT Mono из resources/fonts (см. QuotationPdfService).
     */
    private function registerFonts(Dompdf $dompdf): void
    {
        $dir = resource_path('fonts');
        $fonts = [
            ['PT Sans', 'normal', 'normal', 'PTSans-Regular.ttf'],
            ['PT Sans', 'bold', 'normal', 'PTSans-Bold.ttf'],
            ['PT Sans', 'normal', 'italic', 'PTSans-Italic.ttf'],
            ['PT Sans', 'bold', 'italic', 'PTSans-BoldItalic.ttf'],
            ['PT Mono', 'normal', 'normal', 'PTMono-Regular.ttf'],
            ['PT Mono', 'bold', 'normal', 'PTMono-Regular.ttf'],
        ];

        $metrics = $dompdf->getFontMetrics();
        foreach ($fonts as [$family, $weight, $style, $file]) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                $metrics->registerFont(
                    ['family' => $family, 'weight' => $weight, 'style' => $style],
                    $path
                );
            }
        }
    }
}
