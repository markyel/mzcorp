@php
    $expiresAt = $mailbox?->oauthExpiresAt();
    $expired = $mailbox && $hasTokens && $mailbox->isOAuthTokenExpired();
@endphp

<div class="ds-card p-5">
    <h3 class="text-[14.5px] font-semibold text-fg-1 mb-1">Личный почтовый ящик</h3>
    <div class="text-[12px] text-fg-3 mb-4">
        Yandex 360 через OAuth. После привязки sync запускается автоматически (раз в 2 минуты).
    </div>

    @if(! $mailbox)
        {{-- ───── State 1: NO_MAILBOX ───── --}}
        <div class="text-[13px] text-fg-2 mb-3">У этого менеджера ещё нет личного ящика. Создайте запись и подключите OAuth.</div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Email ящика</label>
                <input type="email" wire:model="mailboxEmail"
                       class="w-full h-[34px] px-3 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono">
                @error('mailboxEmail') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Имя ящика</label>
                <input type="text" wire:model="mailboxName"
                       class="w-full h-[34px] px-3 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]">
                @error('mailboxName') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="mt-3">
            <button type="button" wire:click="createMailbox" class="btn btn-primary">Создать запись о ящике</button>
        </div>

    @elseif(! $hasTokens)
        {{-- ───── State 2: NO_TOKENS ───── --}}
        <div class="text-[13px] mb-3">
            Запись о ящике <span class="mono text-fg-1 font-medium">{{ $mailbox->email }}</span> создана.
            Теперь подтвердите доступ через OAuth Yandex 360.
        </div>

        <ol class="text-[12.5px] text-fg-2 list-decimal pl-5 space-y-1.5 mb-4">
            <li>Нажмите кнопку <b>«Открыть авторизацию Yandex»</b> ниже — откроется новая вкладка.</li>
            <li>Войдите в Yandex под аккаунтом <span class="mono">{{ $mailbox->email }}</span> и нажмите «Разрешить».</li>
            <li>Yandex покажет 7-значный <b>verification code</b>. Скопируйте его.</li>
            <li>Вставьте код в поле ниже и нажмите «Сохранить токен».</li>
        </ol>

        <div class="flex items-center gap-2 mb-3">
            @if($authorizeUrl)
                <a href="{{ $authorizeUrl }}" target="_blank" rel="noopener" class="btn btn-primary">
                    Открыть авторизацию Yandex →
                </a>
            @else
                <span class="text-red-700 text-[12.5px]">Не настроены YANDEX_OAUTH_CLIENT_ID / SECRET в .env.</span>
            @endif
        </div>

        <div class="grid grid-cols-[1fr_auto] gap-2 items-end max-w-[480px]">
            <div>
                <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Verification code</label>
                <input type="text" wire:model="verificationCode" autocomplete="off"
                       class="w-full h-[34px] px-3 border border-border rounded-md bg-surface text-[14px] outline-none focus:border-[var(--sky-500)] mono tracking-wider">
                @error('verificationCode') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
            </div>
            <button type="button" wire:click="saveCode" class="btn btn-primary">Сохранить токен</button>
        </div>

        <div class="mt-4 pt-3 border-t border-border-subtle text-[12px] text-fg-3">
            Передумали? <button type="button" wire:click="detach"
                                wire:confirm="Отвязать ящик? sync будет приостановлен."
                                class="text-sky-700 hover:underline">Отвязать ящик</button>
        </div>

    @else
        {{-- ───── State 3: HAS_TOKENS ───── --}}
        <div class="grid grid-cols-2 gap-x-6 gap-y-2 text-[13px] mb-4">
            <div class="text-fg-3">Email</div>
            <div class="text-fg-1 mono">{{ $mailbox->email }}</div>

            <div class="text-fg-3">Auth</div>
            <div class="text-fg-1">OAuth 2.0 / XOAUTH2</div>

            <div class="text-fg-3">Токен истекает</div>
            <div>
                @if($expiresAt)
                    <span class="mono {{ $expired ? 'text-red-700' : 'text-fg-1' }}">{{ $expiresAt->toDateTimeString() }}</span>
                    @if($expired) <span class="ml-1 chip chip-attn"><span class="dot"></span>истёк</span> @endif
                @else
                    <span class="text-fg-3">—</span>
                @endif
            </div>

            <div class="text-fg-3">Refresh token</div>
            <div>{{ $mailbox->refreshToken() ? 'есть' : 'нет (потребуется реавторизация при истечении)' }}</div>

            <div class="text-fg-3">Последний sync</div>
            <div class="mono text-fg-2 text-[12px]">{{ $mailbox->last_synced_at?->toDateTimeString() ?? 'ещё не запускался' }}</div>

            @if($mailbox->last_error_at)
                <div class="text-fg-3">Последняя ошибка</div>
                <div class="text-red-700 text-[12px]">
                    <div class="mono">{{ $mailbox->last_error_at->toDateTimeString() }}</div>
                    <div>{{ \Illuminate\Support\Str::limit($mailbox->last_error_message ?? '', 200) }}</div>
                </div>
            @endif

            <div class="text-fg-3">Активен</div>
            <div>
                @if($mailbox->is_active)
                    <span class="chip chip-ok"><span class="dot"></span>да</span>
                @else
                    <span class="chip chip-paused"><span class="dot"></span>отвязан</span>
                @endif
            </div>
        </div>

        <div class="flex items-center gap-2 pt-3 border-t border-border-subtle">
            <button type="button" wire:click="reconnect"
                    wire:confirm="Сбросить OAuth-токены и подключить заново? Sync прервётся до ввода нового кода."
                    class="btn btn-sm">Переподключить</button>
            <button type="button" wire:click="detach"
                    wire:confirm="Отвязать ящик? Sync будет приостановлен; токены останутся в БД."
                    class="btn btn-sm btn-danger">Отвязать ящик</button>
        </div>
    @endif
</div>
