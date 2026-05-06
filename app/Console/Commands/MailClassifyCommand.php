<?php

namespace App\Console\Commands;

use App\Models\EmailMessage;
use App\Services\Mail\MailClassifierService;
use Illuminate\Console\Command;

/**
 * AI-классификация писем (Phase 1.6).
 *
 *   php artisan mail:classify {id}            # одно письмо
 *   php artisan mail:classify --all           # все, у которых classified_at=NULL (inbound)
 *   php artisan mail:classify --all --force   # все inbound, перезаписывая
 *   php artisan mail:classify --limit=20      # cap на --all
 *
 * Использует gpt-4o-mini, ~$0.0002/письмо. На 178 писем — ~$0.04.
 */
class MailClassifyCommand extends Command
{
    protected $signature = 'mail:classify
        {id? : Email message id (one-shot)}
        {--all : Classify all unclassified inbound messages}
        {--force : Re-classify even if classified_at is set}
        {--limit=100 : Max messages per --all run}';

    protected $description = 'AI-классификация входящих писем (gpt-4o-mini)';

    public function handle(MailClassifierService $classifier): int
    {
        if ($id = $this->argument('id')) {
            return $this->classifyOne((int) $id, $classifier);
        }

        if (! $this->option('all')) {
            $this->error('Укажите id письма или --all.');

            return self::INVALID;
        }

        return $this->classifyAll($classifier);
    }

    private function classifyOne(int $id, MailClassifierService $classifier): int
    {
        $message = EmailMessage::find($id);
        if (! $message) {
            $this->error('Email message not found.');

            return self::FAILURE;
        }

        $result = $classifier->classify($message, force: (bool) $this->option('force'));

        if ($result === null) {
            $this->warn('Классификация пропущена (см. лог).');

            return self::FAILURE;
        }

        $message->refresh();
        $this->info("classified: {$result->value} ({$result->label()})");
        $this->line("confidence: " . ($message->ai_classification_confidence ?? 'n/a'));
        $this->line("subject: " . mb_substr((string) $message->subject, 0, 80));

        return self::SUCCESS;
    }

    private function classifyAll(MailClassifierService $classifier): int
    {
        $force = (bool) $this->option('force');
        $limit = max(1, (int) $this->option('limit'));

        $query = EmailMessage::query()
            ->where('direction', 'inbound')
            ->orderBy('id');

        if (! $force) {
            $query->whereNull('classified_at');
        }

        $messages = $query->limit($limit)->get();

        if ($messages->isEmpty()) {
            $this->info('Нечего классифицировать.');

            return self::SUCCESS;
        }

        $this->info("Классифицирую {$messages->count()} писем...");
        $progress = $this->output->createProgressBar($messages->count());
        $progress->start();

        $stats = ['ok' => 0, 'fail' => 0];
        foreach ($messages as $msg) {
            $result = $classifier->classify($msg, force: $force);
            if ($result === null) {
                $stats['fail']++;
            } else {
                $stats['ok']++;
            }
            $progress->advance();
        }

        $progress->finish();
        $this->newLine();
        $this->info("Готово. ok={$stats['ok']}, fail={$stats['fail']}.");

        // Распределение по классам
        $this->line('');
        $this->line('Распределение:');
        $rows = \DB::select("
            SELECT ai_classification AS class, COUNT(*) AS c
            FROM email_messages
            WHERE direction = 'inbound' AND ai_classification IS NOT NULL
            GROUP BY ai_classification
            ORDER BY c DESC
        ");
        foreach ($rows as $r) {
            $this->line(sprintf('  %-20s %d', $r->class, $r->c));
        }

        return self::SUCCESS;
    }
}
