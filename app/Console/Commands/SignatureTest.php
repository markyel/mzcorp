<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Mail\EmailSignatureService;
use Illuminate\Console\Command;

/**
 * Диагностика email-подписи: показывает какой путь к лого resolveLogoSrc()
 * нашёл, существует ли файл, сколько весит, какой src получился в итоге.
 * Плюс полный plain + длина html.
 *
 * Использование:
 *   php artisan signature:test {user_id}
 */
class SignatureTest extends Command
{
    protected $signature = 'signature:test {user_id}';

    protected $description = 'Диагностика EmailSignatureService для конкретного пользователя';

    public function handle(EmailSignatureService $service): int
    {
        $userId = (int) $this->argument('user_id');
        $user = User::find($userId);
        if (! $user) {
            $this->error("User #{$userId} не найден");
            return self::FAILURE;
        }

        $this->line("=== User #{$user->id} ===");
        $this->line('  name: '.var_export($user->name, true));
        $this->line('  name_en: '.var_export($user->name_en, true));
        $this->line('  email: '.var_export($user->email, true));
        $this->line('  phone: '.var_export($user->phone, true));
        $this->line('  phone_extension: '.var_export($user->phone_extension, true));
        $this->line('  mobile_phone: '.var_export($user->mobile_phone, true));
        $this->line('  email_signature legacy: '.(empty($user->email_signature) ? '(пусто)' : 'заполнен — будет legacy override'));
        $this->line('');

        $configured = (string) (config('services.company.signature.logo_url') ?? '');
        $this->line('=== Config logo_url ===');
        $this->line('  '.var_export($configured, true));
        $this->line('');

        $this->line('=== Поиск файла логотипа ===');
        $candidates = [];
        if ($configured !== '') {
            $path = parse_url($configured, PHP_URL_PATH) ?: $configured;
            $path = ltrim($path, '/');
            if ($path !== '') {
                $candidates[] = public_path($path);
            }
        }
        $candidates[] = public_path('assets/logo-myzip-email.png');
        $candidates[] = public_path('assets/logo-myzip-email.svg');

        foreach ($candidates as $absPath) {
            $exists = is_file($absPath);
            $readable = $exists && is_readable($absPath);
            $size = $exists ? @filesize($absPath) : 0;
            $this->line(sprintf(
                '  %s  exists=%s  readable=%s  size=%d',
                $absPath,
                $exists ? 'yes' : 'NO',
                $readable ? 'yes' : 'NO',
                $size
            ));
        }
        $this->line('');

        $this->line('=== Рендер подписи ===');
        $result = $service->render($user);
        $plain = $result['plain'];
        $html = $result['html'];

        $this->line('plain ('.mb_strlen($plain).' chars):');
        $this->line('-----');
        $this->line($plain);
        $this->line('-----');
        $this->line('');

        $this->line('html length: '.strlen($html).' chars');

        // Извлекаем src из html (если есть <img>).
        if (preg_match('/<img\s[^>]*src="([^"]*)"/', $html, $m)) {
            $src = $m[1];
            $srcLen = strlen($src);
            $isDataUri = str_starts_with($src, 'data:');
            $this->line('  <img src>: '.($isDataUri ? 'data:URI ('.$srcLen.' chars)' : $src));
            if ($isDataUri && preg_match('/^data:([^;]+);base64,/', $src, $mm)) {
                $this->info('  ✓ MIME: '.$mm[1].' — логотип встроен как base64');
            } elseif (! $isDataUri) {
                $this->warn('  ⚠ Внешний URL — может блокироваться почтовыми клиентами');
            }
        } else {
            $this->warn('  ✗ <img> в html НЕ НАЙДЕН — логотип-колонка не отрендерена');
        }

        return self::SUCCESS;
    }
}
