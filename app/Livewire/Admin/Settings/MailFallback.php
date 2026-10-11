<?php

namespace App\Livewire\Admin\Settings;

use App\Enums\Role;
use App\Models\Mailbox;
use App\Services\Mail\MailFallbackSettings;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * «Настройки» → «Резервный канал отправки»: SMTP-сервис доставки (Unisender
 * Go, потом Postbox), через который уходят письма менеджеров, когда SMTP
 * Яндекса не отвечает (OutgoingSendRetryService). Хранение и шифрование
 * ключа — MailFallbackSettings; ключ в форму не возвращается.
 */
class MailFallback extends Component
{
    public bool $enabled = false;
    public string $name = '';
    public string $host = '';
    public int $port = 587;
    public string $encryption = 'tls';
    public string $username = '';
    /** Новый ключ API; пусто — оставить сохранённый. */
    public string $password = '';
    public int $afterAttempts = 3;
    public bool $disableTracking = true;
    public string $testTo = '';

    public bool $hasPassword = false;
    public bool $managedInUi = false;
    public ?bool $testOk = null;
    public string $testMessage = '';

    public function mount(MailFallbackSettings $settings): void
    {
        $this->authorizeAccess();
        $this->fillFrom($settings);
        $this->testTo = (string) auth()->user()?->email;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', 'in:tls,ssl'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'afterAttempts' => ['required', 'integer', 'between:1,11'],
            'disableTracking' => ['boolean'],
            'enabled' => ['boolean'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => 'название',
            'host' => 'SMTP-сервер',
            'port' => 'порт',
            'username' => 'логин',
            'password' => 'ключ API',
            'afterAttempts' => 'порог попыток',
        ];
    }

    public function applyPreset(string $key): void
    {
        $preset = MailFallbackSettings::PRESETS[$key] ?? null;
        if ($preset === null) {
            return;
        }
        $this->name = $preset['name'];
        $this->host = $preset['host'];
        $this->port = $preset['port'];
        $this->encryption = $preset['encryption'];
        // Заголовки отключения трекинга понимает только Unisender Go.
        $this->disableTracking = $key === 'unisender';
    }

    /** Стандартная пара: 465 — SSL сразу, 587 — STARTTLS; иначе соединение висит. */
    public function updatedPort(): void
    {
        $this->encryption = match ((int) $this->port) {
            465 => 'ssl',
            587, 25, 2525 => 'tls',
            default => $this->encryption,
        };
    }

    public function save(MailFallbackSettings $settings): void
    {
        $this->authorizeAccess();
        $this->validate();

        if ($this->enabled && ! $this->hasPassword && trim($this->password) === '') {
            $this->addError('password', 'Чтобы включить канал, введите ключ API.');

            return;
        }

        $settings->save([
            'enabled' => $this->enabled,
            'name' => trim($this->name),
            'host' => trim($this->host),
            'port' => $this->port,
            'encryption' => $this->encryption,
            'username' => trim($this->username),
            'after_attempts' => $this->afterAttempts,
            'disable_tracking' => $this->disableTracking,
        ], $this->password, auth()->id());

        Log::info('MailFallback: settings saved', [
            'user_id' => auth()->id(),
            'enabled' => $this->enabled,
            'host' => $this->host,
            'key_changed' => trim($this->password) !== '',
        ]);

        $this->password = '';
        $this->fillFrom($settings);
        $this->testOk = null;
        $this->dispatch('toast', message: 'Резервный канал сохранён.', type: 'success');
    }

    /** Соединиться и авторизоваться по СОХРАНЁННЫМ настройкам, ничего не отправляя. */
    public function testConnection(MailFallbackSettings $settings): void
    {
        $this->authorizeAccess();
        if (! $this->savedReady($settings)) {
            return;
        }
        try {
            $settings->testConnection();
            $this->testOk = true;
            $this->testMessage = 'Подключение и авторизация прошли.';
        } catch (\Throwable $e) {
            $this->testOk = false;
            $this->testMessage = 'Не удалось подключиться: '.$e->getMessage();
        }
    }

    /** Тестовое письмо через сохранённый канал — проверить домен отправителя и доставку. */
    public function sendTest(MailFallbackSettings $settings): void
    {
        $this->authorizeAccess();
        $this->validate(['testTo' => ['required', 'email:rfc']], [], ['testTo' => 'адрес']);
        if (! $this->savedReady($settings)) {
            return;
        }

        $user = auth()->user();
        $from = Mailbox::query()->where('owner_user_id', $user->id)->where('is_active', true)->value('email')
            ?: (string) config('services.mail_outbound.shared_email');

        $email = (new Email())
            ->from(new Address($from, (string) $user->name))
            ->to($this->testTo)
            ->subject('MyLift: проверка резервного канала отправки')
            ->text("Это тестовое письмо из MyLift.\n\n"
                ."Оно ушло через «{$settings->name()}» — резервный канал, которым CRM отправляет письма менеджеров, "
                ."когда почтовый сервер Яндекса не отвечает.\n\n"
                ."Проверьте, что письмо не попало в спам и отправитель отображается как {$from}.");
        foreach ($settings->headers() as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        try {
            (new Mailer($settings->transport()))->send($email);
            $this->testOk = true;
            $this->testMessage = "Письмо отправлено на {$this->testTo} от {$from}. Проверьте входящие и папку «Спам».";
            Log::info('MailFallback: test letter sent', ['user_id' => $user->id, 'to' => $this->testTo, 'from' => $from]);
        } catch (\Throwable $e) {
            $this->testOk = false;
            $this->testMessage = 'Не отправилось: '.$e->getMessage();
        }
    }

    public function forgetPassword(MailFallbackSettings $settings): void
    {
        $this->authorizeAccess();
        $settings->forgetPassword();
        $settings->save(['enabled' => false] + $this->currentValues(), null, auth()->id());
        $this->fillFrom($settings);
        $this->dispatch('toast', message: 'Ключ удалён, резервный канал выключен.', type: 'success');
    }

    private function savedReady(MailFallbackSettings $settings): bool
    {
        if (! $settings->managedInUi() || ! $settings->hasPassword()) {
            $this->testOk = false;
            $this->testMessage = 'Сначала сохраните настройки с ключом API.';

            return false;
        }

        return true;
    }

    /** @return array{enabled: bool, name: string, host: string, port: int, encryption: string, username: string, after_attempts: int, disable_tracking: bool} */
    private function currentValues(): array
    {
        return [
            'enabled' => $this->enabled,
            'name' => $this->name,
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username,
            'after_attempts' => $this->afterAttempts,
            'disable_tracking' => $this->disableTracking,
        ];
    }

    private function fillFrom(MailFallbackSettings $settings): void
    {
        $v = $settings->formValues();
        $this->enabled = $v['enabled'];
        $this->name = $v['name'];
        $this->host = $v['host'];
        $this->port = $v['port'];
        $this->encryption = $v['encryption'];
        $this->username = $v['username'];
        $this->afterAttempts = $v['after_attempts'];
        $this->disableTracking = $v['disable_tracking'];
        $this->hasPassword = $settings->hasPassword();
        $this->managedInUi = $settings->managedInUi();
    }

    private function authorizeAccess(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole([Role::HeadOfSales->value, Role::Director->value, Role::Admin->value]),
            403,
        );
    }

    public function render()
    {
        return view('livewire.admin.settings.mail-fallback');
    }
}
