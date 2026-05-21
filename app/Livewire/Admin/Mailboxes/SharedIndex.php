<?php

namespace App\Livewire\Admin\Mailboxes;

use App\Enums\MailboxAuthType;
use App\Enums\MailboxType;
use App\Models\Mailbox;
use App\Services\Mail\MailboxConnector;
use App\Services\Mail\YandexOAuthService;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Управление shared-ящиками (общими, без owner_user_id).
 *
 * Аналог `App\Livewire\Admin\Managers\MailboxOauth`, но для общих ящиков
 * (mail@myzip.ru, info@myzip.ru и т.п.). Доступ только у head_of_sales/director.
 *
 * Функционал:
 *   · Список shared-ящиков со статусами (active, last_sync, error, tokens)
 *   · Создание нового ящика — выбор auth_type (oauth / password)
 *   · OAuth-flow: показать authorize URL, ввести verification code, сохранить
 *   · Password-flow: ввести app-password Yandex 360
 *   · Активация/деактивация (is_active)
 *   · Переподключение (reset credentials)
 *   · Тест IMAP-соединения (открыть и SELECT INBOX)
 *
 * Не удаляем mailbox физически — на нём могут висеть FK email_messages.
 * Деактивация (is_active=false) безопасна; sync пропустит.
 */
class SharedIndex extends Component
{
    /** Активный ящик для OAuth-flow / password-update (row-level state). */
    #[Url]
    public ?int $activeId = null;

    // Поля формы создания
    public string $newEmail = '';
    public string $newName = '';
    public string $newAuth = 'oauth'; // oauth | password
    public string $newPassword = '';

    // Поля row-level: ввод кода / пароля для активного ящика
    public string $verificationCode = '';
    public string $passwordInput = '';

    /** Сообщение от testConnection (показывается в UI). */
    public ?string $testResult = null;
    public ?int $testResultId = null;

    public function create(): void
    {
        $email = mb_strtolower(trim($this->newEmail));
        $name = trim($this->newName);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError('newEmail', 'Некорректный email.');
            return;
        }
        if (mb_strlen($name) < 2) {
            $this->addError('newName', 'Имя ящика обязательно.');
            return;
        }
        if (! in_array($this->newAuth, ['oauth', 'password'], true)) {
            $this->addError('newAuth', 'Выберите тип авторизации.');
            return;
        }
        if (Mailbox::where('email', $email)->exists()) {
            $this->addError('newEmail', 'Ящик с таким email уже зарегистрирован.');
            return;
        }
        if ($this->newAuth === 'password' && trim($this->newPassword) === '') {
            $this->addError('newPassword', 'Введите app-пароль.');
            return;
        }

        $mailbox = new Mailbox();
        $mailbox->fill([
            'name' => $name,
            'email' => $email,
            'type' => MailboxType::Shared,
            'owner_user_id' => null,
            'imap_host' => 'imap.yandex.ru',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $email,
            'smtp_host' => 'smtp.yandex.ru',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => $email,
            'auth_type' => MailboxAuthType::from($this->newAuth),
            // Для password — активируем сразу. Для OAuth — после ввода кода.
            'is_active' => $this->newAuth === 'password',
        ]);
        $mailbox->writeCredentials([]); // NOT NULL колонка

        if ($this->newAuth === 'password') {
            $mailbox->setPassword(trim($this->newPassword));
        }
        $mailbox->save();

        $this->reset(['newEmail', 'newName', 'newPassword']);
        $this->newAuth = 'oauth';

        if ($mailbox->auth_type === MailboxAuthType::OAuth) {
            // Открываем OAuth-секцию для этого ящика
            $this->activeId = $mailbox->id;
            session()->flash('status', "Ящик «{$mailbox->email}» создан. Подключите OAuth ниже.");
        } else {
            session()->flash('status', "Ящик «{$mailbox->email}» создан и активирован (auth=password).");
        }
    }

    public function openOauth(int $id): void
    {
        $this->activeId = $id;
        $this->verificationCode = '';
    }

    public function closeOauth(): void
    {
        $this->activeId = null;
        $this->verificationCode = '';
        $this->passwordInput = '';
    }

    public function saveCode(YandexOAuthService $oauth): void
    {
        $mailbox = $this->resolveSharedMailbox($this->activeId);
        if (! $mailbox) {
            return;
        }
        $code = trim($this->verificationCode);
        if ($code === '') {
            $this->addError('verificationCode', 'Введите код подтверждения.');
            return;
        }

        try {
            $tokens = $oauth->exchangeCode($code);
        } catch (\Throwable $e) {
            $this->addError('verificationCode', 'Ошибка обмена кода: ' . $e->getMessage());
            return;
        }

        $mailbox->setOAuthTokens(
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'] ?? null,
            expiresAt: $tokens['expires_at'] ?? null,
            scope: $tokens['scope'] ?? null,
            tokenType: $tokens['token_type'] ?? null,
        );
        $mailbox->is_active = true;
        $mailbox->last_error_at = null;
        $mailbox->last_error_message = null;
        $mailbox->save();

        $this->verificationCode = '';
        $this->activeId = null;
        session()->flash('status', "OAuth-токены сохранены для «{$mailbox->email}». Sync запустится в ближайшие 2 минуты.");
    }

    public function updatePassword(int $id): void
    {
        $mailbox = $this->resolveSharedMailbox($id);
        if (! $mailbox) {
            return;
        }
        $password = trim($this->passwordInput);
        if ($password === '') {
            $this->addError('passwordInput', 'Введите app-пароль.');
            return;
        }
        $mailbox->setPassword($password);
        $mailbox->is_active = true;
        $mailbox->last_error_at = null;
        $mailbox->last_error_message = null;
        $mailbox->save();

        $this->passwordInput = '';
        $this->activeId = null;
        session()->flash('status', "App-пароль обновлён для «{$mailbox->email}». Sync продолжится.");
    }

    public function reconnect(int $id): void
    {
        $mailbox = $this->resolveSharedMailbox($id);
        if (! $mailbox) {
            return;
        }
        // Сбрасываем credentials. Юзер заведёт новые (код OAuth или пароль).
        $mailbox->writeCredentials([]);
        $mailbox->is_active = false;
        $mailbox->save();
        $this->activeId = $mailbox->id;
        session()->flash('status', "Креды сброшены для «{$mailbox->email}». Введите новые.");
    }

    public function toggleActive(int $id): void
    {
        $mailbox = $this->resolveSharedMailbox($id);
        if (! $mailbox) {
            return;
        }
        // Перед активацией проверим что есть креды (для password — пароль,
        // для OAuth — access_token). Иначе sync молча упадёт.
        if (! $mailbox->is_active) {
            $hasCreds = $mailbox->auth_type === MailboxAuthType::OAuth
                ? (bool) $mailbox->accessToken()
                : (bool) $mailbox->password();
            if (! $hasCreds) {
                session()->flash('error', "Нельзя активировать «{$mailbox->email}» — нет кредов. Введите OAuth-код или пароль.");
                return;
            }
        }
        $mailbox->is_active = ! $mailbox->is_active;
        if ($mailbox->is_active) {
            $mailbox->last_error_at = null;
            $mailbox->last_error_message = null;
        }
        $mailbox->save();
        $verb = $mailbox->is_active ? 'активирован' : 'деактивирован';
        session()->flash('status', "Ящик «{$mailbox->email}» {$verb}.");
    }

    public function testConnection(int $id, MailboxConnector $connector): void
    {
        $mailbox = $this->resolveSharedMailbox($id);
        if (! $mailbox) {
            return;
        }
        $this->testResultId = $id;
        try {
            $client = $connector->imapClient($mailbox);
            $folders = $client->getFolders(false);
            $count = is_countable($folders) ? count($folders) : 0;
            $client->disconnect();
            $this->testResult = "✓ Подключено. Папок видно: {$count}.";
        } catch (\Throwable $e) {
            $this->testResult = '✗ Ошибка: ' . mb_substr($e->getMessage(), 0, 200);
        }
    }

    /**
     * URL Yandex OAuth для активного ящика.
     */
    public function authorizeUrl(YandexOAuthService $oauth): ?string
    {
        if (! $this->activeId) {
            return null;
        }
        $mailbox = $this->resolveSharedMailbox($this->activeId);
        if (! $mailbox) {
            return null;
        }
        $state = 'ui:shared-mb-' . $mailbox->id;

        return $oauth->authorizationUrl($state, loginHint: $mailbox->email);
    }

    private function resolveSharedMailbox(?int $id): ?Mailbox
    {
        if (! $id) {
            return null;
        }

        return Mailbox::query()
            ->whereKey($id)
            ->where('type', MailboxType::Shared->value)
            ->first();
    }

    public function render()
    {
        $mailboxes = Mailbox::query()
            ->where('type', MailboxType::Shared->value)
            ->orderBy('is_active', 'desc')
            ->orderBy('email')
            ->get();

        $activeMailbox = $this->activeId ? $this->resolveSharedMailbox($this->activeId) : null;
        $authorizeUrl = null;
        if ($activeMailbox
            && $activeMailbox->auth_type === MailboxAuthType::OAuth
            && ! $activeMailbox->accessToken()
        ) {
            $authorizeUrl = $this->authorizeUrl(app(YandexOAuthService::class));
        }

        return view('livewire.admin.mailboxes.shared-index', [
            'mailboxes' => $mailboxes,
            'activeMailbox' => $activeMailbox,
            'authorizeUrl' => $authorizeUrl,
        ]);
    }
}
