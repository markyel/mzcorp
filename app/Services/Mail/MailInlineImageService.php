<?php

namespace App\Services\Mail;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Картинки, вставленные в тело письма из редактора (кнопка / буфер обмена /
 * drag&drop): сохраняются как inline-вложение черновика с content_id, в HTML
 * подставляется ссылка на inline-роут вложений (видна в редакторе и в треде
 * CRM), при отправке OutgoingMailMimeBuilder превращает её в cid: и
 * встраивает файл в MIME. Слишком большие картинки уменьшаются (GD): почтовые
 * клиенты всё равно показывают их шириной письма, а 8-мегапиксельные фото
 * с телефона иначе раздувают письмо до 5–10 МБ.
 */
class MailInlineImageService
{
    public const DISK = 'local';

    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Максимальная сторона картинки в письме (px), больше — уменьшаем. */
    public const MAX_SIDE = 1600;

    public const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/gif'];

    public function store(EmailMessage $draft, UploadedFile $file): EmailAttachment
    {
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \InvalidArgumentException('Поддерживаются PNG, JPG и GIF.');
        }

        $bytes = (string) file_get_contents($file->getRealPath());
        [$bytes, $mime, $ext] = $this->shrinkIfNeeded($bytes, $mime);

        $cid = Str::uuid()->toString() . '@mzcorp.ru';
        $path = sprintf('mail/%d/drafts/%d/inline/%s.%s', $draft->mailbox_id ?? 0, $draft->id, Str::random(10), $ext);
        Storage::disk(self::DISK)->put($path, $bytes);
        if (! Storage::disk(self::DISK)->exists($path)) {
            throw new \RuntimeException('Файл не записался на диск.');
        }

        $original = mb_substr((string) $file->getClientOriginalName(), 0, 200);
        $name = $original !== '' ? $original : 'image.' . $ext;
        // Расширение имени — по фактическому формату после уменьшения.
        $name = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $name) . '.' . $ext;

        return EmailAttachment::create([
            'email_message_id' => $draft->id,
            'filename' => $name,
            'mime_type' => $mime,
            'size_bytes' => strlen($bytes),
            'content_id' => $cid,
            'file_path' => $path,
            'disk' => self::DISK,
            'is_inline' => true,
        ]);
    }

    /** URL картинки для HTML редактора/треда (inline-роут вложений письма). */
    public function urlFor(EmailAttachment $attachment): string
    {
        return route('attachments.inline', [
            'emailMessage' => $attachment->email_message_id,
            'contentId' => rawurlencode((string) $attachment->content_id),
        ]);
    }

    /**
     * Уменьшить картинку до MAX_SIDE по большей стороне. GIF не трогаем
     * (анимация). При отсутствии GD или ошибке — возвращаем как есть.
     *
     * @return array{0: string, 1: string, 2: string} bytes, mime, ext
     */
    private function shrinkIfNeeded(string $bytes, string $mime): array
    {
        $ext = match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            default => 'jpg',
        };
        if ($mime === 'image/gif' || ! function_exists('imagecreatefromstring')) {
            return [$bytes, $mime, $ext];
        }

        $size = @getimagesizefromstring($bytes);
        if (! $size || max($size[0], $size[1]) <= self::MAX_SIDE) {
            return [$bytes, $mime, $ext];
        }

        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            return [$bytes, $mime, $ext];
        }
        try {
            [$w, $h] = [$size[0], $size[1]];
            $scale = self::MAX_SIDE / max($w, $h);
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            if ($mime === 'image/png') {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            if ($mime === 'image/png') {
                imagepng($dst, null, 6);
            } else {
                imagejpeg($dst, null, 85);
            }
            $out = (string) ob_get_clean();
            imagedestroy($dst);

            return $out !== '' ? [$out, $mime, $ext] : [$bytes, $mime, $ext];
        } catch (\Throwable) {
            return [$bytes, $mime, $ext];
        } finally {
            imagedestroy($src);
        }
    }
}
