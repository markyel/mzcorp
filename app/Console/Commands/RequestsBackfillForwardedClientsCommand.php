<?php

namespace App\Console\Commands;

use App\Models\Request;
use App\Services\Mail\ForwardedRequestParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Бэкфилл реального клиента в ПЕРЕСЛАННЫХ заявках.
 *
 * До ForwardedRequestParser заявки, пересланные с noreply@myzip.ru,
 * сохранялись с client_email = noreply@myzip.ru (технический ящик), реальный
 * отправитель из блока пересылки в теле терялся. Команда перечитывает
 * origin-письмо таких заявок и проставляет настоящие client_email/name.
 *
 * По умолчанию — только НЕ закрытые заявки; --all захватывает и закрытые.
 * Без --apply — dry-run.
 *
 *   php artisan requests:backfill-forwarded-clients              # dry-run, open
 *   php artisan requests:backfill-forwarded-clients --apply
 *   php artisan requests:backfill-forwarded-clients --apply --all
 */
class RequestsBackfillForwardedClientsCommand extends Command
{
    protected $signature = 'requests:backfill-forwarded-clients
        {--apply : Применить изменения (без флага — dry-run)}
        {--all : Включая закрытые заявки}';

    protected $description = 'Проставить реального отправителя в пересланных заявках (noreply@myzip.ru → данные из блока пересылки).';

    public function handle(ForwardedRequestParser $parser): int
    {
        $apply = (bool) $this->option('apply');
        $forwarders = $parser->forwarderSenders();
        if (empty($forwarders)) {
            $this->error('services.mail.forwarder_senders пуст — нечего бэкфиллить.');

            return self::FAILURE;
        }

        $query = Request::with('emailMessage')
            ->whereIn(DB::raw('LOWER(client_email)'), $forwarders);
        if (! $this->option('all')) {
            $query->whereNotIn('status', ['closed_won', 'closed_lost']);
        }

        $requests = $query->orderBy('id')->get();
        $this->info(sprintf('Кандидатов: %d (mode: %s)', $requests->count(), $apply ? 'APPLY' : 'DRY-RUN'));

        $stats = ['updated' => 0, 'skipped' => 0];
        foreach ($requests as $req) {
            $msg = $req->emailMessage;
            if (! $msg) {
                $stats['skipped']++;
                $this->line(sprintf('  · %s — нет origin-письма, skip', $req->internal_code));

                continue;
            }

            $parsed = $parser->parse($msg);
            if ($parsed === null) {
                $stats['skipped']++;
                $this->line(sprintf('  · %s — блок пересылки не распарсился, skip', $req->internal_code));

                continue;
            }

            $this->line(sprintf(
                '  → %s: %s → %s  (%s)',
                $req->internal_code,
                $req->client_email,
                $parsed['email'],
                $parsed['name'] ?: '—',
            ));

            if (! $apply) {
                $stats['skipped']++;

                continue;
            }

            $req->forceFill([
                'client_email' => $parsed['email'],
                'client_name' => $parsed['name'] ?: $req->client_name,
            ])->save();
            $stats['updated']++;
        }

        $this->newLine();
        $this->info(sprintf('Готово. Обновлено: %d, пропущено: %d', $stats['updated'], $stats['skipped']));

        return self::SUCCESS;
    }
}
