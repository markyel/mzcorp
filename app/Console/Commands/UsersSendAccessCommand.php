<?php

namespace App\Console\Commands;

use App\Mail\AccessLinkMail;
use App\Models\User;
use App\Services\Mail\SystemNotificationMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;

/**
 * Разослать сотрудникам письмо «Доступ в MyLift» со ссылкой, по которой каждый сам задаёт
 * пароль (токен сброса пароля). Пароли не генерируются и не передаются. Адресаты — только
 * явно перечисленные адреса учёток; архивные пропускаются.
 *
 * Usage:
 *   php artisan users:send-access ivan.ivanov@example.com petr.petrov@example.com --dry-run
 *   php artisan users:send-access ivan.ivanov@example.com
 */
class UsersSendAccessCommand extends Command
{
    protected $signature = 'users:send-access
        {emails* : Адреса учёток сотрудников}
        {--dry-run : Показать, кому ушло бы письмо, ничего не отправляя}';

    protected $description = 'Отправить сотрудникам ссылку для входа в MyLift (они сами задают пароль).';

    public function handle(SystemNotificationMailer $mailer): int
    {
        $hours = max(1, intdiv((int) config('auth.passwords.users.expire', 60), 60));
        $failed = 0;

        foreach (array_unique(array_map(fn ($e) => mb_strtolower(trim($e)), $this->argument('emails'))) as $email) {
            $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            if ($user === null) {
                $this->error("{$email}: учётки нет");
                $failed++;

                continue;
            }
            if ($user->archived_at !== null) {
                $this->warn("{$email}: учётка в архиве — пропущена");

                continue;
            }
            if ($this->option('dry-run')) {
                $this->line("{$email}: {$user->name} — ушло бы письмо (dry-run)");

                continue;
            }

            $token = Password::broker()->createToken($user);
            $link = route('password.reset', ['token' => $token, 'email' => $user->email]);
            $sent = $mailer->sendMailable($user->email, new AccessLinkMail($user->name, $user->email, $link, $hours, welcome: true));

            Log::info('users:send-access', ['user_id' => $user->id, 'sent' => $sent]);
            if ($sent) {
                $this->info("{$email}: {$user->name} — письмо отправлено");
            } else {
                $this->error("{$email}: {$user->name} — письмо НЕ отправлено (см. laravel.log)");
                $failed++;
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
