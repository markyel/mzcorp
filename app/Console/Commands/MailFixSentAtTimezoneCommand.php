<?php

namespace App\Console\Commands;

use App\Enums\MailDirection;
use App\Models\EmailMessage;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Бэкфилл sent_at для inbound-писем, сохранённых до фикса таймзоны
 * (MessagePersister::extractDate). Источник истины — headers['date'] (ISO8601
 * с оригинальным offset, напр. «+00:00»): парсим, приводим к app.timezone и
 * перезаписываем sent_at, если wall-clock отличается. Идемпотентно.
 *
 *   php artisan mail:fix-sent-at-tz            # dry-run
 *   php artisan mail:fix-sent-at-tz --apply
 */
class MailFixSentAtTimezoneCommand extends Command
{
    protected $signature = 'mail:fix-sent-at-tz {--apply : Применить (без флага — dry-run)} {--limit=0 : Ограничить число обработанных (0 = все)}';

    protected $description = 'Backfill EmailMessage.sent_at from headers[date] using the app timezone (fixes pre-fix UTC desync)';

    public function handle(): int
    {
        $tz = (string) config('app.timezone', 'Europe/Moscow');
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        $checked = 0;
        $wouldFix = 0;
        $fixed = 0;
        $samples = [];

        EmailMessage::query()
            ->where('direction', MailDirection::Inbound->value)
            ->whereNotNull('headers')
            ->orderBy('id')
            ->chunkById(500, function ($emails) use ($tz, $apply, $limit, &$checked, &$wouldFix, &$fixed, &$samples) {
                foreach ($emails as $e) {
                    if ($limit > 0 && $checked >= $limit) {
                        return false;
                    }
                    $checked++;

                    $raw = is_array($e->headers ?? null) ? ($e->headers['date'] ?? $e->headers['Date'] ?? null) : null;
                    if (! is_string($raw) || trim($raw) === '') {
                        continue;
                    }

                    try {
                        $correct = Carbon::parse($raw)->setTimezone($tz);
                    } catch (\Throwable) {
                        continue;
                    }

                    $storedWall = $e->sent_at?->format('Y-m-d H:i:s');
                    $correctWall = $correct->format('Y-m-d H:i:s');
                    if ($storedWall === $correctWall) {
                        continue;
                    }

                    $wouldFix++;
                    if (count($samples) < 8) {
                        $samples[] = sprintf('#%d: %s → %s', $e->id, $storedWall ?? 'null', $correctWall);
                    }

                    if ($apply) {
                        $e->forceFill(['sent_at' => $correct])->saveQuietly();
                        $fixed++;
                    }
                }

                return true;
            });

        foreach ($samples as $s) {
            $this->line('  ' . $s);
        }
        $this->info(sprintf(
            '%s | checked=%d, %s=%d',
            $apply ? 'APPLIED' : 'DRY-RUN',
            $checked,
            $apply ? 'fixed' : 'would_fix',
            $apply ? $fixed : $wouldFix,
        ));

        return self::SUCCESS;
    }
}
