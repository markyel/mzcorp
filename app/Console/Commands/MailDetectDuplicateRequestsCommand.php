<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Models\Request;
use Illuminate\Console\Command;

/**
 * Детектор дублей: один external-маркер (LZ-REQ-NNNN) → несколько MyLift-Request.
 *
 * Появляется, когда партнёрская система рассылает одно письмо на несколько
 * наших адресов (LZ-REQ-1208 пример: 6 копий → 4 Request + 2 NULL),
 * либо когда напоминания партнёра прицепились к не-тому open Request клиента
 * (Level 4 fallback до Phase ext-codes).
 *
 * Команда только ОТЧИТЫВАЕТСЯ — слияние Request руками через UI (action
 * «Закрыть как дубликат» / переподчинение / etc).
 *
 *   php artisan mail:detect-duplicate-requests              # текстовый список
 *   php artisan mail:detect-duplicate-requests --csv=out.csv  # дамп в CSV
 */
class MailDetectDuplicateRequestsCommand extends Command
{
    protected $signature = 'mail:detect-duplicate-requests
        {--csv= : Записать отчёт в CSV (опц.)}';

    protected $description = 'Найти случаи где один external-маркер привязан к нескольким Request';

    public function handle(): int
    {
        $patterns = (array) config('services.mail.external_codes', []);
        if (empty($patterns)) {
            $this->warn('Нет паттернов в config(services.mail.external_codes).');

            return self::SUCCESS;
        }

        // Собираем «code → set of related_request_id».
        $byCode = [];
        EmailMessage::query()
            ->where(function ($q) {
                $q->where('subject', 'ilike', '%LZ-REQ-%')
                    ->orWhere('body_plain', 'ilike', '%LZ-REQ-%');
            })
            ->whereNotNull('related_request_id')
            ->orderBy('id')
            ->chunkById(200, function ($messages) use (&$byCode, $patterns) {
                foreach ($messages as $m) {
                    $h = (string) $m->subject . "\n" . (string) $m->body_plain;
                    foreach ($patterns as $p) {
                        if (preg_match_all($p, $h, $mm)) {
                            foreach (array_unique($mm[0]) as $code) {
                                $byCode[$code][$m->related_request_id] = ($byCode[$code][$m->related_request_id] ?? 0) + 1;
                            }
                        }
                    }
                }
            });

        // Фильтруем только дубли: где больше одного Request на маркер.
        $dupes = array_filter($byCode, fn ($reqs) => count($reqs) > 1);
        ksort($dupes);

        if (empty($dupes)) {
            $this->info('Дублей не найдено: каждый external-маркер привязан к одной Request.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Найдено %d external-маркеров с дублями:', count($dupes)));
        $this->newLine();

        $csvRows = [['code', 'request_id', 'internal_code', 'status', 'email_count', 'assigned_to']];

        foreach ($dupes as $code => $reqCounts) {
            arsort($reqCounts);
            $this->line($code . ':');

            foreach ($reqCounts as $reqId => $cnt) {
                $req = Request::query()
                    ->with('assignedUser:id,name')
                    ->find($reqId, ['id', 'internal_code', 'status', 'assigned_user_id']);

                if ($req === null) {
                    $this->line(sprintf('    → #%s [DELETED] × %d', $reqId, $cnt));

                    continue;
                }

                $assigned = $req->assignedUser?->name ?? '—';
                $statusStr = $req->status?->value ?? '-';

                $this->line(sprintf(
                    '    → %s (#%d) %s · %s × %d писем',
                    $req->internal_code,
                    $req->id,
                    $statusStr,
                    $assigned,
                    $cnt,
                ));

                $csvRows[] = [
                    $code,
                    $reqId,
                    $req->internal_code,
                    $statusStr,
                    $cnt,
                    $assigned,
                ];
            }
        }

        $csvPath = (string) ($this->option('csv') ?: '');
        if ($csvPath !== '') {
            if (! str_starts_with($csvPath, '/')) {
                $csvPath = base_path($csvPath);
            }
            $fp = fopen($csvPath, 'w');
            if ($fp !== false) {
                fwrite($fp, "\xEF\xBB\xBF");
                foreach ($csvRows as $row) {
                    fputcsv($fp, $row);
                }
                fclose($fp);
                $this->newLine();
                $this->info("CSV-отчёт записан: {$csvPath}");
            } else {
                $this->error("Не могу открыть {$csvPath} на запись.");
            }
        }

        $this->newLine();
        $this->line('Слияние Request — вручную через UI (action «Закрыть как дубликат»).');
        $this->line('После закрытия дублей запустите: php artisan mail:reassign-by-external-code --apply');

        return self::SUCCESS;
    }
}
