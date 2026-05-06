<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Services\Mail\MailboxConnector;
use Illuminate\Console\Command;

/**
 * Проверить IMAP-соединение для одного ящика. Быстрый health-check.
 *
 *   php artisan mail:test 3
 */
class MailTestCommand extends Command
{
    protected $signature = 'mail:test {mailbox : Mailbox id}';

    protected $description = 'Проверить IMAP-соединение и вывести список папок ящика';

    public function handle(MailboxConnector $connector): int
    {
        $mailbox = Mailbox::find($this->argument('mailbox'));
        if (! $mailbox) {
            $this->error('Mailbox not found.');

            return self::FAILURE;
        }

        $this->info("Testing {$mailbox->email}...");

        $result = $connector->testConnection($mailbox);

        if (! $result['ok']) {
            $this->error('Connection failed: ' . ($result['message'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->info('Connection OK.');
        $this->line('Folders:');
        foreach ($result['folders'] ?? [] as $f) {
            $this->line("  - {$f}");
        }

        $this->line('');
        $this->line('INBOX status:');
        $this->showStatus($result['inbox'] ?? []);
        $this->line('Sent status:');
        $this->showStatus($result['sent'] ?? []);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function showStatus(array $status): void
    {
        if (isset($status['error'])) {
            $this->warn('  ERROR: ' . $status['error']);

            return;
        }

        foreach ($status as $k => $v) {
            $this->line("  {$k} = " . (is_scalar($v) ? (string) $v : json_encode($v)));
        }
    }
}
