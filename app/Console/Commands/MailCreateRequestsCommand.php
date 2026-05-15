<?php

namespace App\Console\Commands;

use App\Enums\EmailCategory;
use App\Models\EmailMessage;
use App\Services\Mail\IncomingMailProcessor;
use Illuminate\Console\Command;

/**
 * Backfill: создать Request из всех писем с category=client_request,
 * у которых ещё нет привязанного Request.
 *
 *   php artisan mail:create-requests              # dry-run
 *   php artisan mail:create-requests --apply      # реально создаёт
 *   php artisan mail:create-requests --apply --limit=20
 */
class MailCreateRequestsCommand extends Command
{
    protected $signature = 'mail:create-requests
        {--apply : Actually create requests}
        {--limit=500 : Max emails to process per run}';

    protected $description = 'Создать Request из писем-заявок, у которых ещё нет связанного Request';

    public function handle(IncomingMailProcessor $processor): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $query = EmailMessage::query()
            ->where('direction', 'inbound')
            ->where('category', EmailCategory::ClientRequest->value)
            ->whereNull('related_request_id')
            ->orderBy('id')
            ->limit($limit);

        $messages = $query->get();
        if ($messages->isEmpty()) {
            $this->info('Нечего обрабатывать.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->info("Будут созданы Request для {$messages->count()} писем (dry-run).");
            foreach ($messages->take(10) as $m) {
                $this->line(sprintf(
                    '  email#%d  %s  →  «%s»',
                    $m->id,
                    $m->from_email,
                    mb_substr((string) $m->subject, 0, 60),
                ));
            }
            if ($messages->count() > 10) {
                $this->line('  ... и ещё ' . ($messages->count() - 10));
            }
            $this->line('');
            $this->line('Запустите с --apply для реального создания.');

            return self::SUCCESS;
        }

        $this->info("Создаю Request для {$messages->count()} писем...");
        $progress = $this->output->createProgressBar($messages->count());
        $progress->start();

        $created = 0;
        $failed = 0;
        foreach ($messages as $msg) {
            try {
                $req = $processor->processIfRequest($msg);
                if ($req) {
                    $created++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                \Illuminate\Support\Facades\Log::error('mail:create-requests failure', [
                    'email_message_id' => $msg->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $progress->advance();
        }

        $progress->finish();
        $this->newLine();
        $this->info("Создано: {$created}, ошибок: {$failed}.");

        // Сводка по менеджерам
        $this->line('');
        $this->line('Распределение заявок по менеджерам:');
        $rows = \DB::select("
            SELECT u.name, COUNT(r.id) AS c
            FROM users u
            LEFT JOIN requests r ON r.assigned_user_id = u.id
            WHERE u.id IN (SELECT model_id FROM model_has_roles WHERE role_id IN (
                SELECT id FROM roles WHERE name = 'manager'
            ))
            GROUP BY u.id, u.name
            ORDER BY c DESC
        ");
        foreach ($rows as $r) {
            $this->line(sprintf('  %-30s %d', $r->name, $r->c));
        }

        return self::SUCCESS;
    }
}
