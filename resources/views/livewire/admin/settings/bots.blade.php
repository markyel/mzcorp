<div class="ds-card mb-3">
    <div class="ds-card-header">
        <h3>Боты заявок</h3>
    </div>

    <div class="px-[18px] py-3 text-[11.5px] text-fg-3 leading-snug border-b border-[var(--border-subtle)]">
        Клиент регистрируется в боте (e-mail с кодом, имя, телефон, организация), смотрит цену по артикулу M…
        и оформляет заявку — она появляется в mzCorp как обычная, с пометкой источника, и распределяется менеджеру.
        Цена — организации клиента, если его адрес к ней привязан; иначе розничная; неактуальную цену бот не называет.
        Ключ бота: Telegram — у <span class="mono">@BotFather</span>, MAX — на платформе для партнёров («Чат-боты → ⋮ → Настройки»).
        Ключ хранится зашифрованным и сюда не возвращается.
    </div>

    <div class="divide-y divide-[var(--border-subtle)]">
        @foreach($channels as $key => $c)
            <div class="px-[18px] py-3" wire:key="bot-{{ $key }}">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <b class="text-[13px] text-fg-1">{{ $c['label'] }}</b>
                    @if($c['has_token'])
                        <span class="chip chip-ok">ключ сохранён</span>
                        @if($c['username'])
                            <span class="mono text-[11.5px] text-fg-3">{{ '@'.$c['username'] }}</span>
                        @endif
                    @else
                        <span class="chip chip-neutral">не подключён</span>
                    @endif
                    <span class="flex-1"></span>
                    <span class="text-[11.5px] text-fg-3">клиентов {{ $c['clients'] }} · подтвердили e-mail {{ $c['verified'] }} · заявок {{ $c['requests'] }}</span>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <input type="password" wire:model="token.{{ $key }}" autocomplete="off"
                           placeholder="{{ $c['has_token'] ? 'новый ключ — заменить сохранённый' : 'ключ бота' }}"
                           class="h-8 px-2 rounded-md border border-[var(--border)] bg-surface text-[12.5px] w-[340px] max-w-full">
                    <button type="button" class="btn btn-sm" wire:click="saveToken('{{ $key }}')" wire:loading.attr="disabled">Сохранить ключ</button>
                    @if($c['has_token'])
                        <button type="button" class="btn btn-sm btn-primary" wire:click="connect('{{ $key }}')" wire:loading.attr="disabled">Подключить</button>
                        <button type="button" class="btn btn-sm" wire:click="check('{{ $key }}')" wire:loading.attr="disabled">Проверить связь</button>
                        <button type="button" class="btn btn-sm" wire:click="disconnect('{{ $key }}')"
                                wire:confirm="Отключить бота? Сообщения клиентов перестанут приходить.">Отключить</button>
                        <button type="button" class="btn btn-sm" wire:click="forget('{{ $key }}')"
                                wire:confirm="Удалить ключ и отключить бота?">Удалить ключ</button>
                    @endif
                </div>

                @if(isset($notice[$key]))
                    <div class="mt-2 text-[12px] {{ $notice[$key]['ok'] ? 'text-emerald-700' : 'text-amber-800' }}">{{ $notice[$key]['text'] }}</div>
                @endif
            </div>
        @endforeach
    </div>
</div>
