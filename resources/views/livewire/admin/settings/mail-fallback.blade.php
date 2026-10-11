<div class="ds-card mb-3">
    <div class="ds-card-header">
        <h3>Резервный канал отправки</h3>
        @if($enabled && $hasPassword)
            <span class="chip chip-ok">включён</span>
        @else
            <span class="chip chip-neutral">выключен</span>
        @endif
    </div>

    <div class="px-[18px] py-3 text-[11.5px] text-fg-3 leading-snug border-b border-[var(--border-subtle)]">
        Если почтовый сервер Яндекса не отвечает, письмо менеджера ждёт в очереди и повторяется.
        После указанного числа неудачных попыток оно уходит через этот сервис, дальше каналы чередуются.
        Отправитель тот же (адрес менеджера), ответ клиента придёт в его ящик, копия ляжет в «Отправленные».
        Домен <span class="mono">myzip.ru</span> должен быть подтверждён у провайдера.
        @unless($managedInUi)
            <br>Сейчас действуют значения из <span class="mono">.env</span>; после сохранения здесь — только эти.
        @endunless
    </div>

    <form wire:submit.prevent="save" class="divide-y divide-[var(--border-subtle)]">
        <div class="px-[18px] py-3 grid items-center gap-4" style="grid-template-columns: 1fr 260px">
            <div class="text-[13px] text-fg-1 font-medium">Провайдер</div>
            <div class="flex items-center justify-end gap-2">
                <button type="button" wire:click="applyPreset('unisender')" class="btn btn-sm">Unisender Go</button>
                <button type="button" wire:click="applyPreset('postbox')" class="btn btn-sm">Postbox</button>
            </div>
        </div>

        @foreach([
            ['name', 'Название', 'Так канал подписывается в переписке: «Отправлено через …».', 'text'],
            ['host', 'SMTP-сервер', 'Unisender Go: smtp.go1.unisender.ru или smtp.go2.unisender.ru — как указано в кабинете. Postbox: проверьте адрес в консоли Yandex Cloud.', 'text'],
            ['port', 'Порт', '587 (STARTTLS) или 465 (SSL).', 'number'],
            ['username', 'Логин', 'Unisender Go: ID пользователя (число из кабинета).', 'text'],
        ] as [$field, $label, $help, $type])
            <div class="px-[18px] py-3 grid items-start gap-4" style="grid-template-columns: 1fr 260px">
                <div class="min-w-0">
                    <label for="fb-{{ $field }}" class="text-[13px] text-fg-1 font-medium">{{ $label }}</label>
                    <div class="text-[11.5px] text-fg-3 mt-1 leading-snug">{{ $help }}</div>
                </div>
                <div>
                    <input type="{{ $type }}" id="fb-{{ $field }}" wire:model="{{ $field }}" autocomplete="off"
                           class="w-full px-2.5 py-1.5 border border-border rounded-md text-[13px] focus:outline-none focus:ring-1 focus:ring-sky-500">
                    @error($field)<div class="text-[11.5px] text-red-700 mt-1">{{ $message }}</div>@enderror
                </div>
            </div>
        @endforeach

        <div class="px-[18px] py-3 grid items-start gap-4" style="grid-template-columns: 1fr 260px">
            <div class="min-w-0">
                <label for="fb-encryption" class="text-[13px] text-fg-1 font-medium">Шифрование</label>
            </div>
            <select id="fb-encryption" wire:model="encryption"
                    class="w-full px-2.5 py-1.5 border border-border rounded-md text-[13px] focus:outline-none focus:ring-1 focus:ring-sky-500">
                <option value="tls">STARTTLS (обычно порт 587)</option>
                <option value="ssl">SSL (обычно порт 465)</option>
            </select>
        </div>

        <div class="px-[18px] py-3 grid items-start gap-4" style="grid-template-columns: 1fr 260px">
            <div class="min-w-0">
                <label for="fb-password" class="text-[13px] text-fg-1 font-medium">Ключ API (пароль SMTP)</label>
                <div class="text-[11.5px] text-fg-3 mt-1 leading-snug">
                    Хранится зашифрованным и обратно в форму не показывается.
                    @if($hasPassword)
                        Ключ сохранён — оставьте поле пустым, чтобы не менять.
                        <button type="button" wire:click="forgetPassword" wire:confirm="Удалить ключ и выключить резервный канал?" class="underline text-red-700">Удалить ключ</button>
                    @endif
                </div>
            </div>
            <div>
                <input type="password" id="fb-password" wire:model="password" autocomplete="new-password"
                       placeholder="{{ $hasPassword ? '•••••••• сохранён' : 'не задан' }}"
                       class="w-full px-2.5 py-1.5 border border-border rounded-md text-[13px] focus:outline-none focus:ring-1 focus:ring-sky-500">
                @error('password')<div class="text-[11.5px] text-red-700 mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="px-[18px] py-3 grid items-start gap-4" style="grid-template-columns: 1fr 260px">
            <div class="min-w-0">
                <label for="fb-after" class="text-[13px] text-fg-1 font-medium">Переходить на резерв после N неудач Яндекса</label>
                <div class="text-[11.5px] text-fg-3 mt-1 leading-snug">3 попытки ≈ 20 минут (паузы 1, 5, 15 мин).</div>
            </div>
            <div>
                <input type="number" id="fb-after" wire:model="afterAttempts" min="1" max="11"
                       class="w-full px-2.5 py-1.5 border border-border rounded-md text-[13px] focus:outline-none focus:ring-1 focus:ring-sky-500">
                @error('afterAttempts')<div class="text-[11.5px] text-red-700 mt-1">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="px-[18px] py-3 grid items-start gap-4" style="grid-template-columns: 1fr 260px">
            <div class="min-w-0">
                <div class="text-[13px] text-fg-1 font-medium">Без отслеживания (Unisender Go)</div>
                <div class="text-[11.5px] text-fg-3 mt-1 leading-snug">Не подменять ссылки и не вставлять пиксель прочтения в письма менеджеров.</div>
            </div>
            <label class="inline-flex items-center gap-2 cursor-pointer justify-end">
                <input type="checkbox" wire:model="disableTracking" class="rounded border-border focus:ring-sky-500 text-sky-700">
                <span class="text-[12.5px] text-fg-1">отключить</span>
            </label>
        </div>

        <div class="px-[18px] py-3 grid items-start gap-4" style="grid-template-columns: 1fr 260px">
            <div class="min-w-0">
                <div class="text-[13px] text-fg-1 font-medium">Включить резервный канал</div>
            </div>
            <label class="inline-flex items-center gap-2 cursor-pointer justify-end">
                <input type="checkbox" wire:model="enabled" class="rounded border-border focus:ring-sky-500 text-sky-700">
                <span class="text-[12.5px] text-fg-1">включён</span>
            </label>
        </div>

        <div class="px-[18px] py-3 flex items-center justify-end gap-2">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Сохранить</span>
                <span wire:loading wire:target="save">Сохраняю...</span>
            </button>
        </div>
    </form>

    <div class="px-[18px] py-3 border-t border-[var(--border-subtle)]">
        <div class="text-[13px] text-fg-1 font-medium">Проверка (по сохранённым настройкам)</div>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <button type="button" wire:click="testConnection" class="btn btn-sm" wire:loading.attr="disabled" wire:target="testConnection,sendTest">
                <span wire:loading.remove wire:target="testConnection">Проверить подключение</span>
                <span wire:loading wire:target="testConnection">Проверяю...</span>
            </button>
            <input type="email" wire:model="testTo" placeholder="адрес для теста"
                   class="px-2.5 py-1 border border-border rounded-md text-[13px] focus:outline-none focus:ring-1 focus:ring-sky-500" style="width:240px;max-width:100%">
            <button type="button" wire:click="sendTest" class="btn btn-sm" wire:loading.attr="disabled" wire:target="testConnection,sendTest">
                <span wire:loading.remove wire:target="sendTest">Отправить тестовое письмо</span>
                <span wire:loading wire:target="sendTest">Отправляю...</span>
            </button>
        </div>
        @error('testTo')<div class="text-[11.5px] text-red-700 mt-1">{{ $message }}</div>@enderror
        @if($testOk !== null)
            <div class="mt-2 ds-card p-2 text-[12px]"
                 style="{{ $testOk ? 'background:var(--emerald-50);border-color:var(--emerald-300)' : 'background:var(--red-50);border-color:var(--red-300)' }}">
                {{ $testOk ? '✓' : '✕' }} {{ $testMessage }}
            </div>
        @endif
    </div>
</div>
