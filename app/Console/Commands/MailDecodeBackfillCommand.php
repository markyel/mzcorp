<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use Illuminate\Console\Command;

/**
 * Back-fill: декодирует MIME-encoded subject и from_name в уже сохранённых
 * EmailMessage. Запускается один раз после фикса декодинга в Phase 1.5.
 *
 *   php artisan mail:decode-backfill            # dry-run, показывает что бы изменилось
 *   php artisan mail:decode-backfill --apply    # реально записывает
 */
class MailDecodeBackfillCommand extends Command
{
    protected $signature = 'mail:decode-backfill {--apply : Actually write changes}';

    protected $description = 'Декодировать MIME-encoded subject и from_name в существующих письмах';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $count = 0;
        $changed = 0;

        EmailMessage::query()
            ->where(function ($q) {
                $q->where('subject', 'like', '%=?%?=%')
                    ->orWhere('from_name', 'like', '%=?%?=%');
            })
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use (&$count, &$changed, $apply) {
                foreach ($chunk as $email) {
                    $count++;

                    $newSubject = $email->subject ? $this->decode((string) $email->subject) : $email->subject;
                    $newFromName = $email->from_name ? $this->decode((string) $email->from_name) : $email->from_name;

                    if ($newSubject !== $email->subject || $newFromName !== $email->from_name) {
                        if ($apply) {
                            $email->forceFill([
                                'subject' => $newSubject,
                                'from_name' => $newFromName,
                            ])->saveQuietly();
                        }
                        $changed++;
                    }
                }
            });

        $verb = $apply ? 'updated' : 'would update';
        $this->info("Scanned: {$count}, {$verb}: {$changed}");

        if (! $apply) {
            $this->line('Run again with --apply to actually persist changes.');
        }

        return self::SUCCESS;
    }

    private function decode(string $value): string
    {
        if ($value === '' || ! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }

        $decoded = @mb_decode_mimeheader($value);
        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }

        return $value;
    }
}
