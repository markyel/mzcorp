<?php

namespace App\Services\Mail;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Вложенные контейнеры письма — пересланное письмо (.eml, message/rfc822) и
 * архив (.zip) — раскрываются в обычные вложения того же письма, чтобы разбор
 * позиций видел фото и документы внутри. Кейсы 07.11.2026: MZ2026-00205
 * («Фото от Павел.eml» ×2, позиции только на фото внутри), M-2026-21609
 * (zip со скриншотами запроса).
 *
 * Распакованное сохраняется строками email_attachments с
 * metadata.unpacked_from = id контейнера: менеджер видит файлы в письме,
 * позиция ссылается на своё фото. Повторный вызов берёт уже распакованное.
 */
class NestedAttachmentUnpacker
{
    /** Глубина вложенности: письмо в письме, архив в письме. */
    private const MAX_DEPTH = 2;

    /** Файлов из одного контейнера (архив скриншотов, письмо с десятком фото). */
    private const MAX_FILES_PER_CONTAINER = 30;

    private const MAX_FILE_BYTES = 15 * 1024 * 1024;

    /** Текст вложенного письма в промпте разбора. */
    private const MAX_TEXT_CHARS = 6000;

    /** Что достаём из контейнера: то, что умеет читать разбор позиций, и сами контейнеры. */
    private const KEEP_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'eml', 'zip'];

    /**
     * Вложения письма с раскрытыми контейнерами и текст вложенных писем.
     *
     * @return array{attachments: Collection<int, EmailAttachment>, text: string}
     */
    public function expand(EmailMessage $message): array
    {
        $attachments = $message->attachments;
        if (! config('mail.unpack_nested_attachments', true)
            || ! $attachments->contains(fn (EmailAttachment $a) => $this->containerKind($a) !== null)
        ) {
            return ['attachments' => $attachments, 'text' => ''];
        }

        $texts = [];
        $result = $this->expandList($message, $attachments, $texts, 0);

        return ['attachments' => $result, 'text' => trim(implode("\n\n", $texts))];
    }

    /**
     * @param  Collection<int, EmailAttachment>  $list
     * @param  list<string>  $texts
     * @return Collection<int, EmailAttachment>
     */
    private function expandList(EmailMessage $message, Collection $list, array &$texts, int $depth): Collection
    {
        $out = collect();
        foreach ($list as $att) {
            $kind = $this->containerKind($att);
            if ($kind === null) {
                $out->push($att);

                continue;
            }
            if ($depth >= self::MAX_DEPTH) {
                continue;
            }

            if ($kind === 'eml') {
                $text = $this->emlText($att);
                if ($text !== '') {
                    $texts[] = '— вложенное письмо '.$att->display_filename.":\n".$text;
                }
            }

            $children = $this->children($message, $att, $kind);
            $out = $out->merge($this->expandList($message, $children, $texts, $depth + 1));
        }

        return $out->values();
    }

    /** 'eml' | 'zip' | null */
    public function containerKind(EmailAttachment $att): ?string
    {
        $ext = strtolower(pathinfo((string) $att->filename, PATHINFO_EXTENSION));
        $mime = strtolower((string) $att->mime_type);

        return match (true) {
            $ext === 'eml' || str_contains($mime, 'message/rfc822') => 'eml',
            $ext === 'zip' || in_array($mime, ['application/zip', 'application/x-zip-compressed'], true) => 'zip',
            default => null,
        };
    }

    /** @return Collection<int, EmailAttachment> */
    private function children(EmailMessage $message, EmailAttachment $container, string $kind): Collection
    {
        $existing = EmailAttachment::query()
            ->where('email_message_id', $message->id)
            ->where('metadata->unpacked_from', (string) $container->id)
            ->orderBy('id')
            ->get();
        if ($existing->isNotEmpty() || data_get($container->metadata, 'unpacked_at')) {
            return $existing;
        }

        $files = [];
        try {
            $files = $kind === 'eml' ? $this->emlFiles($container) : $this->zipFiles($container);
        } catch (\Throwable $e) {
            Log::warning('NestedAttachmentUnpacker: unpack failed', [
                'attachment_id' => $container->id,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }

        $dir = trim(dirname((string) $container->file_path), './');
        $created = collect();
        foreach (array_slice($files, 0, self::MAX_FILES_PER_CONTAINER) as [$name, $content]) {
            $name = Str::limit($name, 250, '');
            $path = ($dir !== '' ? $dir.'/' : '').Str::random(8).'_'.$this->safeFilename($name);
            Storage::disk($container->disk ?: 'local')->put($path, $content);
            $created->push(EmailAttachment::create([
                'email_message_id' => $message->id,
                'filename' => $name,
                'mime_type' => $this->mimeOf($name, $content),
                'size_bytes' => strlen($content),
                'file_path' => $path,
                'disk' => $container->disk ?: 'local',
                'is_inline' => false,
                'metadata' => ['unpacked_from' => $container->id],
            ]));
        }

        // Пустой контейнер (письмо без вложений, архив без нужных файлов) не открываем заново.
        $container->forceFill(['metadata' => array_merge($container->metadata ?? [], ['unpacked_at' => now()->toIso8601String()])])->save();

        return $created;
    }

    /** @return list<array{0: string, 1: string}> */
    private function emlFiles(EmailAttachment $att): array
    {
        $msg = $this->emlMessage($att);
        if ($msg === null) {
            return [];
        }
        $files = [];
        foreach ($msg->getAttachments() as $i => $part) {
            $content = (string) $part->getContent();
            $name = $this->decodeName((string) ($part->getName() ?? ''));
            if ($name === '') {
                $ext = $part->getExtension() ?: 'bin';
                $name = 'file-'.($i + 1).'.'.$ext;
            }
            if ($this->keep($name, $content)) {
                $files[] = [$name, $content];
            }
        }

        return $files;
    }

    /** @return list<array{0: string, 1: string}> */
    private function zipFiles(EmailAttachment $att): array
    {
        $path = Storage::disk($att->disk ?: 'local')->path((string) $att->file_path);
        if (! is_file($path)) {
            return [];
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return [];
        }
        $files = [];
        try {
            for ($i = 0; $i < $zip->numFiles && count($files) < self::MAX_FILES_PER_CONTAINER; $i++) {
                $stat = $zip->statIndex($i, \ZipArchive::FL_ENC_RAW);
                $raw = (string) ($stat['name'] ?? '');
                if ($raw === '' || str_ends_with($raw, '/') || str_contains($raw, '__MACOSX/')
                    || ($stat['size'] ?? 0) > self::MAX_FILE_BYTES
                ) {
                    continue;
                }
                // Имена в zip из Windows — в CP866, без флага UTF-8.
                $name = mb_check_encoding($raw, 'UTF-8') ? $raw : (string) @mb_convert_encoding($raw, 'UTF-8', 'CP866');
                $name = basename(str_replace('\\', '/', $name));
                $content = $zip->getFromIndex($i);
                if (is_string($content) && $this->keep($name, $content)) {
                    $files[] = [$name, $content];
                }
            }
        } finally {
            $zip->close();
        }

        return $files;
    }

    /** Новый текст вложенного письма: тело без цитаты, тема и отправитель для контекста. */
    private function emlText(EmailAttachment $att): string
    {
        $msg = $this->emlMessage($att);
        if ($msg === null) {
            return '';
        }
        $text = trim((string) $msg->getTextBody());
        if ($text === '' && $msg->hasHTMLBody()) {
            $text = app(EmailTextCleanerService::class)->htmlToText((string) $msg->getHTMLBody());
        }
        $text = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $text)) ?? $text);
        if ($text === '') {
            return '';
        }
        $subject = trim($this->decodeName((string) ($msg->getSubject()?->first() ?? '')));
        $head = $subject !== '' ? 'Тема: '.$subject."\n" : '';

        return mb_substr($head.$text, 0, self::MAX_TEXT_CHARS);
    }

    private function emlMessage(EmailAttachment $att): ?ImapMessage
    {
        $disk = Storage::disk($att->disk ?: 'local');
        $path = (string) $att->file_path;
        if ($path === '' || ! $disk->exists($path)) {
            return null;
        }
        try {
            $raw = (string) $disk->get($path);

            return $raw !== '' ? ImapMessage::fromString($raw) : null;
        } catch (\Throwable $e) {
            Log::warning('NestedAttachmentUnpacker: .eml parse failed', [
                'attachment_id' => $att->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function keep(string $name, string $content): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $content !== ''
            && strlen($content) <= self::MAX_FILE_BYTES
            && in_array($ext, self::KEEP_EXTENSIONS, true);
    }

    private function decodeName(string $name): string
    {
        $persister = app(MessagePersister::class);

        return trim($persister->recoverMojibake($persister->decodeMimeHeader($name)));
    }

    private function mimeOf(string $name, string $content): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';
        if ($mime === '' || $mime === 'application/octet-stream' || $mime === 'text/plain') {
            $byExt = [
                'eml' => 'message/rfc822', 'zip' => 'application/zip', 'pdf' => 'application/pdf',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel', 'doc' => 'application/msword',
            ];
            $mime = $byExt[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? ($mime ?: 'application/octet-stream');
        }

        return $mime;
    }

    private function safeFilename(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';
    }
}
