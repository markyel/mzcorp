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
            $html = $m->body_html;

            if (preg_match('#<body[^>]*>(.*)</body>#is', $html, $mm)) {
                $html = $mm[1];
            }

            // Изоляция: dompdf не исполняет JS, но <style> письма мог бы
            // протечь на следующие письма одного документа.
            $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;

            // Кириллица в подписях рендерилась как «???»: подписи из почтовых
            // клиентов (Thunderbird moz-signature и т.п.) несут собственный
            // font-family (Helvetica/Arial), а для этих семейств dompdf берёт
            // встроенный Type1-шрифт БЕЗ кириллицы. Снимаем декларации шрифта из
            // inline-style и <font face=…>, чтобы текст наследовал PT Sans
            // (defaultFont). Доп. страховка — !important в шаблоне.
            $html = preg_replace('/font-family\s*:[^;"\']*;?/i', '', $html) ?? $html;
            $html = preg_replace('/(<font\b[^>]*?)\s+face\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '$1', $html) ?? $html;

            return $this->inlineCidImages($html, $m);
        }

        if ($m->body_plain) {
            return '<pre style="white-space:pre-wrap;font-family:inherit;margin:0;font-size:11px;">'
                . e($m->body_plain) . '</pre>';
        }

        return null;
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
