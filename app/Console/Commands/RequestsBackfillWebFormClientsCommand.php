<?php

namespace App\Console\Commands;

use App\Models\Request;
use App\Services\Mail\WebFormSubmissionParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Бэкфилл реального клиента в заявках с сайта.
 *
 * До WebFormSubmissionParser заявки с order@myzip.ru сохранялись с
 * client_email = order@myzip.ru (технический ящик формы), реальный клиент из
 * тела терялся. Команда перечитывает origin-письмо таких заявок и
 * проставляет настоящие client_email/name/phone/company/address.
 *
 * По умолчанию — только НЕ закрытые заявки (с ними ещё ведётся переписка);
 * --all захватывает и закрытые. Без --apply — dry-run.
 *
 *   php artisan requests:backfill-web-form-clients              # dry-run, open
 *   php artisan requests:backfill-web-form-clients --apply
 *   php artisan requests:backfill-web-form-clients --apply --all
 */
class RequestsBackfillWebFormClientsCommand extends Command
{
    protected $signature = 'requests:backfill-web-form-clients
        {--apply : Применить изменения (без флага — dry-run)}
        {--all : Включая закрытые заявки}';

    protected $description = 'Проставить реального клиента в заявках с сайта (order@myzip.ru → данные из тела).';

    public function handle(WebFormSubmissionParser $parser): int
    {
        $apply = (bool) $this->option('apply');
        $relay = $parser->relaySenders();
        if (empty($relay)) {
            $this->error('services.mail.web_form_senders пуст — нечего бэкфиллить.');

            return self::FAILURE;
        }

        $query = Request::with('emailMessage')
            ->whereIn(DB::raw('LOWER(client_email)'), $relay);
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
                $this->line(sprintf('  · %s — не распарсилось тело, skip', $req->internal_code));
                continue;
            }

            $this->line(sprintf(
                '  → %s: %s → %s  (%s, %s, %s)',
                $req->internal_code,
                $req->client_email,
                $parsed['email'],
                $parsed['name'] ?: '—',
                $parsed['phone'] ?: '—',
                $parsed['company'] ?: '—',
            ));

            if (! $apply) {
                $stats['skipped']++;
                continue;
            }

            $req->forceFill([
                'client_email' => $parsed['email'],
                'client_name' => $parsed['name'] ?: $parsed['company'] ?: $req->client_name,
                'client_phone' => $parsed['phone'],
                'client_company' => $parsed['company'],
                'client_address' => $parsed['address'],
            ])->save();
            $stats['updated']++;
        }

        $this->newLine();
        $this->info(sprintf('Готово. Обновлено: %d, пропущено: %d', $stats['updated'], $stats['skipped']));

        return self::SUCCESS;
    }
}
