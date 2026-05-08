<?php

namespace App\Livewire\Admin\Managers;

use App\Enums\MailboxAuthType;
use App\Enums\MailboxType;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Mail\YandexOAuthService;
use Livewire\Component;

/**
 * Привязка/перепривязка личного Yandex 360 ящика менеджера через UI
 * (Phase 1.13). Переносит CLI-flow `mail:oauth url + code` в браузер.
 *
 * Состояния:
 *   1) NO_MAILBOX  — у юзера нет owned mailbox. Форма «email ящика»+«name»
 *      → POST createMailbox() создаёт пустую запись.
 *   2) NO_TOKENS   — Mailbox создан, но access_token пустой. Кнопка-ссылка
 *      на Yandex authorize URL (target=_blank) + поле «Код подтверждения»
 *      + submit saveCode().
 *   3) HAS_TOKENS  — токены есть. Статус-блок (срок истечения, индикатор
 *      isOAuthTokenExpired) + кнопки «Переподключить» (обнуляет credentials)
 *      и «Отвязать ящик» (мягко: is_active=false, токены не трогаем).
 */
class MailboxOauth extends Component
{
    public int $userId;

    public ?int $mailboxId = null;
    public string $mailboxEmail = '';
    public string $mailboxName = '';
    public string $verificationCode = '';

    public function mount(User $user): void
    {
        if (! $user->exists) {
            // Защита: компонент рендерится только в edit-flow для сохранённого
            // юзера. На create-странице edit.blade.php вообще не подключает
            // MailboxOauth (`@if($user->exists)`).
            abort(500, 'MailboxOauth requires a persisted user.');
        }

        $this->userId = $user->id;
        $mailbox = $this->resolveMailbox();
        if ($mailbox) {
            $this->mailboxId = $mailbox->id;
            $this->mailboxEmail = $mailbox->email;
            $this->mailboxName = $mailbox->name;
        } else {
            $this->mailboxEmail = $user->email;
            $this->mailboxName = $user->name . ' (личный)';
        }
    }

    private function user(): User
    {
        return User::findOrFail($this->userId);
    }

    private function resolveMailbox(): ?Mailbox
    {
        // Берём первый личный ящик. Сейчас «один менеджер — один личный ящик»
        // (Foundation §1). Multi-mailbox — отдельная фича Phase 2+.
        return Mailbox::query()
            ->where('owner_user_id', $this->userId)
            ->where('type', MailboxType::Personal->value)
            ->orderBy('id')
            ->first();
    }

    public function createMailbox(): void
    {
        if (! filter_var($this->mailboxEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addError('mailboxEmail', 'Некорректный email.');

            return;
        }
        if (strlen(trim($this->mailboxName)) < 2) {
            $this->addError('mailboxName', 'Имя ящика обязательно.');

            return;
        }

        if (Mailbox::where('email', $this->mailboxEmail)->exists()) {
            $this->addError('mailboxEmail', 'Ящик с таким email уже зарегистрирован в системе.');

            return;
        }

        // mailboxes.encrypted_credentials NOT NULL — нужно записать пустой
        // зашифрованный JSON через writeCredentials([]) до save(). Иначе
        // INSERT упадёт на NOT NULL constraint. Тот же приём в `mail:add` CLI.
        $mailbox = new Mailbox();
        $mailbox->fill([
            'name' => $this->mailboxName,
            'email' => $this->mailboxEmail,
            'type' => MailboxType::Personal,
            'owner_user_id' => $this->userId,
            'imap_host' => 'imap.yandex.ru',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $this->mailboxEmail,
            'smtp_host' => 'smtp.yandex.ru',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_username' => $this->mailboxEmail,
            'auth_type' => MailboxAuthType::OAuth,
            'is_active' => true,
        ]);
        $mailbox->writeCredentials([]);
        $mailbox->save();

        $this->mailboxId = $mailbox->id;
        session()->flash('status', "Ящик «{$mailbox->email}» создан. Подключите OAuth ниже.");
    }

    public function saveCode(YandexOAuthService $oauth): void
    {
        $mailbox = $this->mailboxId ? Mailbox::find($this->mailboxId) : null;
        if (! $mailbox || $mailbox->owner_user_id !== $this->userId) {
            $this->addError('verificationCode', 'Ящик не найден.');

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
        session()->flash('status', "OAuth-токены сохранены. Сync запустится по расписанию (раз в 2 мин).");
    }

    public function reconnect(): void
    {
        $mailbox = $this->mailboxId ? Mailbox::find($this->mailboxId) : null;
        if (! $mailbox || $mailbox->owner_user_id !== $this->userId) {
            return;
        }
        // Сбрасываем credentials → переходим в NO_TOKENS state, юзер снова
        // получает auth URL и вставляет новый verification code. Пишем пустой
        // зашифрованный JSON, потому что колонка NOT NULL.
        $mailbox->writeCredentials([]);
        $mailbox->save();
        session()->flash('status', 'Токены сброшены. Получите новый verification code и сохраните.');
    }

    public function detach(): void
    {
        $mailbox = $this->mailboxId ? Mailbox::find($this->mailboxId) : null;
        if (! $mailbox || $mailbox->owner_user_id !== $this->userId) {
            return;
        }
        // Не удаляем mailbox физически — на нём висят email_messages с FK.
        // Гасим: is_active=false → SyncMailboxJob его пропустит.
        $mailbox->is_active = false;
        $mailbox->save();
        session()->flash('status', "Ящик «{$mailbox->email}» отвязан (sync приостановлен). Удалить можно вручную из БД, если нужно.");
    }

    public function authorizeUrl(YandexOAuthService $oauth): ?string
    {
        if (! $this->mailboxId) {
            return null;
        }

        // State носит mailbox id чтобы отличить flow в логах. На return из
        // verification_code Yandex его не вернёт — это OK, мы используем код,
        // а не state.
        $state = 'ui:mb-' . $this->mailboxId;

        return $oauth->authorizationUrl($state, loginHint: $this->mailboxEmail);
    }

    public function render()
    {
        $mailbox = $this->mailboxId ? Mailbox::find($this->mailboxId) : null;
        $hasTokens = $mailbox && $mailbox->accessToken();

        return view('livewire.admin.managers.mailbox-oauth', [
            'mailbox' => $mailbox,
            'hasTokens' => (bool) $hasTokens,
            'authorizeUrl' => $hasTokens ? null : $this->authorizeUrl(app(YandexOAuthService::class)),
        ]);
    }
}
