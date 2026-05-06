<?php

namespace App\Console\Commands;

use App\Enums\MailboxType;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Добавить (или обновить) почтовый ящик в систему через CLI.
 *
 * До появления админ-UI (Phase 1.5+) — единственный способ конфигурировать
 * ящики на проде.
 *
 *   php artisan mail:add \
 *     --email=mail@myzip.ru \
 *     --name="Mail (общий)" \
 *     --type=shared \
 *     --owner=manager@mylift.test  (для personal)
 *
 * Пароль app-password запрашивается интерактивно (secret prompt).
 */
class MailAddMailboxCommand extends Command
{
    protected $signature = 'mail:add
        {--email= : Email address of the mailbox}
        {--name= : Display name for UI}
        {--type=shared : shared | personal}
        {--owner= : User email (for personal type)}
        {--imap-host=imap.yandex.ru}
        {--imap-port=993}
        {--smtp-host=smtp.yandex.ru}
        {--smtp-port=465}';

    protected $description = 'Добавить или обновить почтовый ящик с app-password';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email');
        $name = $this->option('name') ?: $this->ask('Display name', $email);
        $type = $this->option('type');

        if (! in_array($type, ['shared', 'personal'], true)) {
            $this->error('--type must be "shared" or "personal".');

            return self::INVALID;
        }

        $ownerId = null;
        if ($type === 'personal') {
            $ownerEmail = $this->option('owner') ?: $this->ask('Owner user email');
            $owner = User::where('email', $ownerEmail)->first();
            if (! $owner) {
                $this->error("User not found: {$ownerEmail}");

                return self::FAILURE;
            }
            $ownerId = $owner->id;
        }

        $password = $this->secret('App-password');
        if ($password === '' || $password === null) {
            $this->error('Password cannot be empty.');

            return self::INVALID;
        }

        $mailbox = Mailbox::firstOrNew(['email' => $email]);
        $mailbox->fill([
            'name' => $name,
            'type' => MailboxType::from($type),
            'owner_user_id' => $ownerId,
            'imap_host' => $this->option('imap-host'),
            'imap_port' => (int) $this->option('imap-port'),
            'imap_encryption' => 'ssl',
            'imap_username' => $email,
            'smtp_host' => $this->option('smtp-host'),
            'smtp_port' => (int) $this->option('smtp-port'),
            'smtp_encryption' => 'ssl',
            'smtp_username' => $email,
            'is_active' => true,
        ]);
        $mailbox->setPassword($password);
        $mailbox->save();

        $this->info("Mailbox saved: id={$mailbox->id} email={$email} type={$type}");
        $this->line('Test connection: php artisan mail:test ' . $mailbox->id);

        return self::SUCCESS;
    }
}
