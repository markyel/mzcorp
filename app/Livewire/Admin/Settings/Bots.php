<?php

namespace App\Livewire\Admin\Settings;

use App\Enums\MessengerChannel;
use App\Enums\Role;
use App\Models\MessengerChat;
use App\Services\Bot\BotMessengers;
use App\Services\Bot\BotSettings;
use Livewire\Component;

/**
 * «Настройки» → «Боты заявок»: ключи ботов Telegram и MAX, подключение
 * вебхука и проверка связи. Ключ хранится зашифрованным (BotSettings) и в
 * форму не возвращается.
 */
class Bots extends Component
{
    /** @var array<string, string> новый ключ по каналу; пусто — оставить сохранённый */
    public array $token = ['telegram' => '', 'max' => ''];

    /** @var array<string, array{ok: bool, text: string}> */
    public array $notice = [];

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function saveToken(string $channel): void
    {
        $this->authorizeAccess();
        $settings = app(BotSettings::class);
        $messengers = app(BotMessengers::class);
        $ch = MessengerChannel::from($channel);
        $token = trim($this->token[$channel] ?? '');
        if ($token === '') {
            $this->notice[$channel] = ['ok' => false, 'text' => 'Вставьте ключ бота.'];

            return;
        }

        $settings->saveToken($ch, $token, auth()->id());
        $me = $messengers->for($ch)?->me() ?? ['ok' => false, 'username' => null, 'message' => 'нет ключа'];
        if (! $me['ok']) {
            $settings->forgetToken($ch);
            $this->notice[$channel] = ['ok' => false, 'text' => 'Ключ не принят: '.$me['message']];

            return;
        }
        $settings->saveUsername($ch, $me['username']);
        $this->token[$channel] = '';
        $this->notice[$channel] = ['ok' => true, 'text' => $me['message'].' Теперь нажмите «Подключить».'];
    }

    public function connect(string $channel): void
    {
        $this->authorizeAccess();
        $settings = app(BotSettings::class);
        $messengers = app(BotMessengers::class);
        $ch = MessengerChannel::from($channel);
        $messenger = $messengers->for($ch);
        if ($messenger === null) {
            $this->notice[$channel] = ['ok' => false, 'text' => 'Сначала сохраните ключ бота.'];

            return;
        }
        $res = $messenger->connect($this->webhookUrl($ch), $settings->webhookSecret($ch));
        $this->notice[$channel] = ['ok' => $res['ok'], 'text' => $res['message']];
    }

    public function disconnect(string $channel): void
    {
        $this->authorizeAccess();
        $settings = app(BotSettings::class);
        $messengers = app(BotMessengers::class);
        $ch = MessengerChannel::from($channel);
        $res = $messengers->for($ch)?->disconnect($this->webhookUrl($ch)) ?? ['ok' => false, 'message' => 'Ключ не задан.'];
        $this->notice[$channel] = ['ok' => $res['ok'], 'text' => $res['message']];
    }

    public function check(string $channel): void
    {
        $this->authorizeAccess();
        $settings = app(BotSettings::class);
        $messengers = app(BotMessengers::class);
        $me = $messengers->for(MessengerChannel::from($channel))?->me() ?? ['ok' => false, 'message' => 'Ключ не задан.'];
        $this->notice[$channel] = ['ok' => $me['ok'], 'text' => $me['message']];
    }

    public function forget(string $channel): void
    {
        $this->authorizeAccess();
        $settings = app(BotSettings::class);
        $messengers = app(BotMessengers::class);
        $ch = MessengerChannel::from($channel);
        $messengers->for($ch)?->disconnect($this->webhookUrl($ch));
        $settings->forgetToken($ch);
        $this->notice[$channel] = ['ok' => true, 'text' => 'Ключ удалён, бот отключён.'];
    }

    private function webhookUrl(MessengerChannel $channel): string
    {
        return route($channel === MessengerChannel::Telegram ? 'api.bot.telegram' : 'api.bot.max');
    }

    private function authorizeAccess(): void
    {
        abort_unless(auth()->user()?->hasAnyRole([Role::HeadOfSales->value, Role::Director->value, Role::Admin->value]), 403);
    }

    public function render()
    {
        $settings = app(BotSettings::class);
        $channels = [];
        foreach (MessengerChannel::cases() as $ch) {
            $chats = MessengerChat::query()->where('channel', $ch->value);
            $channels[$ch->value] = [
                'label' => $ch->label(),
                'has_token' => $settings->hasToken($ch),
                'username' => $settings->username($ch),
                'clients' => (clone $chats)->count(),
                'verified' => (clone $chats)->whereNotNull('email_verified_at')->count(),
                'requests' => (int) (clone $chats)->sum('requests_count'),
            ];
        }

        return view('livewire.admin.settings.bots', ['channels' => $channels]);
    }
}
