<?php

namespace App\Console\Commands;

use App\Enums\EmailCategory;
use App\Models\EmailMessage;
use App\Services\Mail\MailCategoryClassifier;
use Illuminate\Console\Command;

/**
 * Phase 1.8c: AI-категоризация входящих писем по новой схеме
 * (client_request | thread_reply | irrelevant).
 *
 *   php artisan mail:categorize 16              # одно письмо
 *   php artisan mail:categorize --all           # все ещё не категоризованные inbound
 *   php artisan mail:categorize --all --force   # пере-категоризовать ВСЕ
 *   php artisan mail:categorize --all --limit=50
 *   php artisan mail:categorize --all --where-confidence-below=0.7   # review-set
 */
class MailCategorizeCommand extends Command
{
    protected $signature = 'mail:categorize
        {message? : EmailMessage id (single mode)}
        {--all : Bulk: все inbound без category}
        {--limit=50 : Bulk: максимум писем за прогон}
        {--from-id=0 : Bulk: пропустить id ниже}
        {--force : Перезаписать существующую категорию}';

    protected $description = 'Phase 1.8c: AI-категоризация писем (client_request|thread_reply|irrelevant)';

    public function handle(MailCategoryClassifier $classifier): int
    {
        $force = (bool) $this->option('force');

        if ($id = $this->argument('message')) {
            return $this->processSingle((int) $id, $classifier, $force);
        }

        if (! $this->option('all')) {
            $this->error('Укажи id письма или --all для bulk режима.');

            return self::FAILURE;
        }

        return $this->processBulk($classifier, $force);
    }

    private function processSingle(int $id, MailCategoryClassifier $classifier, bool $force): int
    {
        $msg = EmailMessage::find($id);
        if (! $msg) {
            $this->error("EmailMessage #{$id} не найден.");

            return self::FAILURE;
        }

        $result = $classifier->categorize($msg, force: $force);
        $cat = $result['category'];

        $this->line('');
        $this->line(sprintf(
            'email#%d  %s  ←  %s',
            $msg->id,
            mb_substr((string) $msg->subject, 0, 60),
            $msg->from_email,
        ));
        $this->line(sprintf(
            '  category: %s  (conf=%s)  intent=%s',
            $cat?->value ?? '—',
            $result['confidence'] !== null ? sprintf('%.2f', $result['confidence']) : '—',
            $result['intent'] ?? '—',
        ));
        if ($result['reasoning']) {
            $this->line('  reasoning: ' . $result['reasoning']);
        }

        return self::SUCCESS;
    }

    private function processBulk(MailCategoryClassifier $classifier, bool $force): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $fromId = (int) $this->option('from-id');

        $query = EmailMessage::query()
            ->where('direction', 'inbound')
            ->where('id', '>=', $fromId)
            ->orderBy('id');

        if (! $force) {
            $query->whereNull('categorized_at');
        }

        $messages = $query->limit($limit)->get();
        if ($messages->isEmpty()) {
            $this->info('Нет писем для категоризации.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Обрабатываю %d писем (force: %s)...', $messages->count(), $force ? 'yes' : 'no'));

        $stats = ['categorized' => 0, 'failed' => 0];
        foreach (EmailCategory::cases() as $c) {
            $stats[$c->value] = 0;
        }
        $stats['low_confidence'] = 0;

        $bar = $this->output->createProgressBar($messages->count());
        $bar->start();

        foreach ($messages as $m) {
            try {
                $result = $classifier->categorize($m, force: $force);
                if ($result['category'] === null) {
                    $stats['failed']++;
                } else {
                    $stats['categorized']++;
                    $stats[$result['category']->value]++;
                    if (($result['confidence'] ?? 0) < 0.7) {
                        $stats['low_confidence']++;
                    }
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $rows = [];
        foreach ($stats as $k => $v) {
            $rows[] = [$k, (string) $v];
        }
        $this->table(['metric', 'value'], $rows);

        return self::SUCCESS;
    }
}
