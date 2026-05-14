<?php

namespace App\Console\Commands;

use App\Models\EmailAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Backfill пустых `email_attachments.filename` — синтезирует читаемое
 * имя по MIME-типу для записей, где filename IS NULL или ''.
 *
 * Phase 2.4a: исторически `MessagePersister::persistAttachment` фоллбэкал
 * только когда `getName()` возвращал null. Если клиент шлёт фото без
 * name=/filename= в Content-Type/Content-Disposition (типично iPhone),
 * webklex отдавал '' — fallback не срабатывал, в БД попадал пустой string.
 *
 * После hotfix новый сохранённые имена будут типа `attachment-a3b2c1d4.jpg`.
 * Эта команда чинит уже накопленные исторические записи.
 *
 * Usage:
 *   php artisan mail:backfill-attachment-names               # apply
 *   php artisan mail:backfill-attachment-names --dry-run     # только показать
 */
class MailBackfillAttachmentNamesCommand extends Command
{
    protected $signature = 'mail:backfill-attachment-names
        {--dry-run : Показать что будет переименовано без записи}
        {--chunk=200 : Размер батча для chunkById}';

    protected $description = 'Заполнить пустые email_attachments.filename синтезированным именем (Phase 2.4a).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(50, (int) $this->option('chunk'));

        $base = EmailAttachment::query()
            ->where(function ($q) {
                $q->whereNull('filename')->orWhere('filename', '');
            });

        $total = (clone $base)->count();
        if ($total === 0) {
            $this->info('Нет вложений с пустым filename.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Найдено вложений с пустым filename: %d.', $total));

        $processed = 0;
        $previewSample = [];

        $base->orderBy('id')->chunkById($chunk, function ($items) use (&$processed, &$previewSample, $dryRun) {
            foreach ($items as $att) {
                $ext = $this->guessExtension((string) $att->mime_type) ?: 'bin';
                $disposition = $att->is_inline ? 'inline' : 'attachment';
                $synthesized = $disposition . '-' . Str::random(8) . '.' . $ext;

                if (! $dryRun) {
                    $att->forceFill(['filename' => $synthesized])->save();
                }
                $processed++;

                if (count($previewSample) < 5) {
                    $previewSample[] = [
                        'id' => $att->id,
                        'mime' => $att->mime_type,
                        'inline' => $att->is_inline ? 'yes' : 'no',
                        'new_name' => $synthesized,
                    ];
                }
            }
        });

        $this->table(
            ['id', 'mime', 'inline', 'new_name'],
            $previewSample,
        );

        if ($dryRun) {
            $this->warn(sprintf('--dry-run: %d записей было бы переименовано.', $processed));
        } else {
            $this->info(sprintf('Переименовано: %d записей.', $processed));
        }

        return self::SUCCESS;
    }

    /**
     * Та же таблица что в MessagePersister::guessExtension — DRY нарушено
     * сознательно, чтобы CLI не зависела от приватного метода.
     */
    private function guessExtension(string $mime): ?string
    {
        $mime = strtolower(trim($mime));
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/msword' => 'doc',
            'application/zip' => 'zip',
            'application/x-rar-compressed' => 'rar',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'text/html' => 'html',
        ];

        return $map[$mime] ?? null;
    }
}
