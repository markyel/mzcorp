<?php

namespace App\Console\Commands;

use App\Enums\MailboxAuthType;
use App\Enums\MailboxType;
use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Добавить (или обновить) почтовый ящик через CLI.
 *
 * До появления админ-UI (Phase 1.5+) — основной способ конфигурации ящиков.
 *
 * По умолчанию создаётся ящик с auth_type=oauth (без пароля). После создания
 * команда печатает URL для OAuth-авторизации:
 *
 *   php artisan mail:add --email=mail@myzip.ru --type=shared --name="Mail (общий)"
 *
 * Если хочется через app-password (Yandex 360 для бизнеса часто их отключает):
 *   php artisan mail:add --auth=password --email=...
 */
class MailAddMailboxCommand extends Command
{
    protected $signature = 'mail:add
        {--email= : Email address of the mailbox}
        {--name= : Display name for UI}
        {--type=shared : shared | personal}
        {--owner= : User email (required for personal)}
        {--auth=oauth : oauth | password}
        {--imap-host=imap.yandex.ru}
        {--imap-port=993}
        {--smtp-host=smtp.yandex.ru}
        {--smtp-port=465}';

    protected $description = 'Добавить или обновить почтовый ящик MyLift';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email');
        $name = $this->option('name') ?: $this->ask('Display name', $email);
        $type = $this->option('type');
        $auth = $this->option('auth');

        if (! in_array($type, ['shared', 'personal'], true)) {
            $this->error('--type must be "shared" or "personal".');

            return self::INVALID;
        }
        if (! in_array($auth, ['oauth', 'password'], true)) {
            $this->error('--auth must be "oauth" or "password".');

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

        $mailbox = Mailbox::firstOrNew(['email' => $email]);
        $mailbox->fill([
            'name' => $name,
            'type' => MailboxType::from($type),
            'auth_type' => MailboxAuthType::from($auth),
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

        if ($auth === 'password') {
            $password = $this->secret('App-password');
            if ($password === '' || $password === null) {
                $this->error('Password cannot be empty.');

                return self::INVALID;
            }
            $mailbox->setPassword($password);
        } else {
            // Для OAuth токены проставятся через /oauth/yandex/callback.
            // Сохраняем ящик с пустыми кредами на этом шаге.
            if (! $mailbox->encrypted_credentials) {
                $mailbox->writeCredentials([]);
            }
        }

        $mailbox->save();

        $this->info("Mailbox saved: id={$mailbox->id} email={$email} type={$type} auth={$auth}");

        if ($auth === 'oauth') {
            $url = config('app.url') . '/oauth/yandex/authorize?mailbox=' . $mailbox->id;
            $this->line('');
            $this->line('Откройте URL в браузере (под учёткой РОПа в MyLift), потом залогиньтесь в Yandex под адресом ' . $email . ':');
            $this->line('  ' . $url);
        }

        $this->line('Test connection: php artisan mail:test ' . $mailbox->id);

        return self::SUCCESS;
    }
}
