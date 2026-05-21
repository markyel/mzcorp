@php
    use App\Enums\MailboxAuthType;
@endphp

<div class="space-y-6">
    {{-- Сообщения --}}
    @if(session('status'))
        <div class="ds-card p-3 text-[13px] text-emerald-800 bg-emerald-50 border-emerald-200 border">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div class="ds-card p-3 text-[13px] text-red-800 bg-red-50 border-red-200 border">
            {{ session('error') }}
        </div>
    @endif

    {{-- ────────── Форма создания нового shared-ящика ────────── --}}
    <div class="ds-card p-5">
        <h3 class="text-[14.5px] font-semibold text-fg-1 mb-1">Добавить общий ящик</h3>
        <div class="text-[12px] text-fg-3 mb-4">
            Yandex 360. OAuth — для аккаунтов с включённым OAuth-доступом. Password — app-пароль из настроек Yandex 360.
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div>
                <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Email</label>
                <input type="email" wire:model="newEmail" placeholder="info@myzip.ru"
                       class="w-full h-[34px] px-3 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono">
                @error('newEmail') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Имя</label>
                <input type="text" wire:model="newName" placeholder="Info (общий)"
                       class="w-full h-[34px] px-3 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]">
                @error('newName') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
            </div>
            <div>
                <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Авторизация</label>
                <select wire:model.live="newAuth"
                        class="w-full h-[34px] px-3 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]">
                    <option value="oauth">OAuth (Yandex)</option>
                    <option value="password">App-пароль</option>
                </select>
                @error('newAuth') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
            </div>
        </div>

        @if($newAuth === 'password')
            <div class="mt-3 max-w-md">
                <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">App-пароль</label>
                <input type="password" wire:model="newPassword" autocomplete="new-password"
                       class="w-full h-[34px] px-3 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono">
                @error('newPassword') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                <div class="text-[11px] text-fg-3 mt-1">Генерируется в Yandex 360 → Настройки безопасности → Пароли приложений.</div>
            </div>
        @endif

        <div class="mt-4">
            <button type="button" wire:click="create" class="btn btn-primary">Создать ящик</button>
        </div>
    </div>

    {{-- ────────── Список существующих shared-ящиков ────────── --}}
    <div>
        <h3 class="text-[14.5px] font-semibold text-fg-1 mb-3">Существующие общие ящики ({{ $mailboxes->count() }})</h3>

        @if($mailboxes->isEmpty())
            <div class="ds-card p-5 text-[13px] text-fg-3">Пока нет ни одного общего ящика. Добавьте через форму выше.</div>
        @else
            <div class="space-y-3">
                @foreach($mailboxes as $mb)
                    @php
                        $isOauth = $mb->auth_type === MailboxAuthType::OAuth;
                        $hasTokens = $isOauth ? (bool) $mb->accessToken() : (bool) $mb->password();
                        $expiresAt = $isOauth ? $mb->oauthExpiresAt() : null;
                        $expired = $isOauth && $hasTokens && $mb->isOAuthTokenExpired();
                        $isActiveRow = $activeMailbox && $activeMailbox->id === $mb->id;
                    @endphp

                    <div class="ds-card p-4" wire:key="mb-{{ $mb->id }}">
                        <div class="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-3 items-start">
                            {{-- Левая колонка: метаданные --}}
                            <div class="grid grid-cols-2 gap-x-6 gap-y-1.5 text-[13px]">
                                <div class="text-fg-3">Email</div>
                                <div class="mono text-fg-1 font-medium">{{ $mb->email }}</div>

                                <div class="text-fg-3">Имя</div>
                                <div class="text-fg-2">{{ $mb->name }}</div>

                                <div class="text-fg-3">Авторизация</div>
                                <div>
                                    @if($isOauth)
                                        <span class="chip chip-ok">OAuth</span>
                                        @if($hasTokens && $expiresAt)
                                            <span class="mono text-[11px] text-fg-3 ml-1">истекает {{ $expiresAt->toDateTimeString() }}</span>
                                            @if($expired) <span class="chip chip-attn ml-1"><span class="dot"></span>истёк</span> @endif
                                        @elseif(! $hasTokens)
                                            <span class="chip chip-attn ml-1"><span class="dot"></span>нет токенов</span>
                                        @endif
                                    @else
                                        <span class="chip">app-password</span>
                                        @if(! $hasTokens)
                                            <span class="chip chip-attn ml-1"><span class="dot"></span>нет пароля</span>
                                        @endif
                                    @endif
                                </div>

                                <div class="text-fg-3">Статус</div>
                                <div>
                                    @if($mb->is_active)
                                        <span class="chip chip-ok"><span class="dot"></span>активен</span>
                                    @else
                                        <span class="chip chip-paused"><span class="dot"></span>деактивирован</span>
                                    @endif
                                </div>

                                <div class="text-fg-3">Последний sync</div>
                                <div class="mono text-[12px] text-fg-2">{{ $mb->last_synced_at?->toDateTimeString() ?? '—' }}</div>

                                @if($mb->last_error_at)
                                    <div class="text-fg-3">Последняя ошибка</div>
                                    <div class="text-red-700 text-[12px]">
                                        <div class="mono">{{ $mb->last_error_at->toDateTimeString() }}</div>
                                        <div>{{ \Illuminate\Support\Str::limit($mb->last_error_message ?? '', 220) }}</div>
                                    </div>
                                @endif

                                @if($testResultId === $mb->id && $testResult)
                                    <div class="text-fg-3">Тест</div>
                                    <div class="text-[12px] {{ str_starts_with($testResult, '✓') ? 'text-emerald-700' : 'text-red-700' }}">
                                        {{ $testResult }}
                                    </div>
                                @endif
                            </div>

                            {{-- Правая колонка: действия --}}
                            <div class="flex flex-col gap-2 min-w-[210px]">
                                <button type="button" wire:click="testConnection({{ $mb->id }})"
                                        class="btn btn-sm">⊕ Тест соединения</button>

                                <button type="button" wire:click="toggleActive({{ $mb->id }})"
                                        wire:confirm="{{ $mb->is_active
                                            ? 'Деактивировать ящик? Sync прервётся.'
                                            : 'Активировать ящик? Sync начнёт работать.' }}"
                                        class="btn btn-sm {{ $mb->is_active ? 'btn-danger' : 'btn-primary' }}">
                                    {{ $mb->is_active ? '⏸ Деактивировать' : '▶ Активировать' }}
                                </button>

                                <button type="button" wire:click="reconnect({{ $mb->id }})"
                                        wire:confirm="Сбросить креды? Sync прервётся до ввода новых."
                                        class="btn btn-sm">↻ Переподключить</button>

                                @if($isActiveRow)
                                    <button type="button" wire:click="closeOauth"
                                            class="btn btn-sm">✕ Скрыть форму</button>
                                @else
                                    <button type="button" wire:click="openOauth({{ $mb->id }})"
                                            class="btn btn-sm">⚙ Обновить креды…</button>
                                @endif
                            </div>
                        </div>

                        {{-- Inline-форма OAuth/password (только когда раскрыт этот ящик) --}}
                        @if($isActiveRow)
                            <div class="mt-4 pt-4 border-t border-border-subtle">
                                @if($isOauth)
                                    @if($hasTokens && ! $expired)
                                        <div class="text-[13px] text-fg-2">
                                            OAuth-токены валидны до {{ $expiresAt?->toDateTimeString() }}.
                                            Чтобы заменить — сначала «Переподключить» (сбросит токены),
                                            затем введите новый код ниже.
                                        </div>
                                    @else
                                        <div class="text-[13px] mb-3">
                                            Подтвердите доступ к ящику <span class="mono">{{ $mb->email }}</span> через Yandex OAuth.
                                        </div>
                                        <ol class="text-[12.5px] text-fg-2 list-decimal pl-5 space-y-1.5 mb-3">
                                            <li>Нажмите «Открыть авторизацию Yandex».</li>
                                            <li>Войдите в Yandex под <span class="mono">{{ $mb->email }}</span>, нажмите «Разрешить».</li>
                                            <li>Скопируйте 7-значный код.</li>
                                            <li>Вставьте код ниже и нажмите «Сохранить токен».</li>
                                        </ol>
                                        @if($authorizeUrl)
                                            <a href="{{ $authorizeUrl }}" target="_blank" rel="noopener" class="btn btn-primary mb-3 inline-flex">
                                                Открыть авторизацию Yandex →
                                            </a>
                                        @else
                                            <div class="text-red-700 text-[12.5px] mb-3">Не настроены YANDEX_OAUTH_CLIENT_ID / SECRET в .env.</div>
                                        @endif
                                        <div class="grid grid-cols-[1fr_auto] gap-2 items-end max-w-[480px]">
                                            <div>
                                                <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Verification code</label>
                                                <input type="text" wire:model="verificationCode" autocomplete="off"
                                                       class="w-full h-[34px] px-3 border border-border rounded-md bg-surface text-[14px] outline-none focus:border-[var(--sky-500)] mono tracking-wider">
                                                @error('verificationCode') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                                            </div>
                                            <button type="button" wire:click="saveCode" class="btn btn-primary">Сохранить токен</button>
                                        </div>
                                    @endif
                                @else
                                    {{-- password --}}
                                    <div class="text-[13px] mb-3">
                                        Введите новый app-пароль для <span class="mono">{{ $mb->email }}</span>.
                                        Берётся в Yandex 360 → Настройки безопасности → Пароли приложений.
                                    </div>
                                    <div class="grid grid-cols-[1fr_auto] gap-2 items-end max-w-[480px]">
                                        <div>
                                            <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">App-пароль</label>
                                            <input type="password" wire:model="passwordInput" autocomplete="new-password"
                                                   class="w-full h-[34px] px-3 border border-border rounded-md bg-surface text-[14px] outline-none focus:border-[var(--sky-500)] mono">
                                            @error('passwordInput') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                                        </div>
                                        <button type="button" wire:click="updatePassword({{ $mb->id }})" class="btn btn-primary">Сохранить пароль</button>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
