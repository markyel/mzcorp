<div class="space-y-4">
    @php $inputCls = 'h-[30px] w-full px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500'; @endphp

    {{-- Заголовок --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('clients.index') }}" wire:navigate class="text-[12px] text-sky-700 hover:underline">← Клиенты</a>
        <h2 class="text-[16px] font-semibold text-fg-1 mono">{{ $contact->email }}</h2>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Контакт --}}
        <div class="lg:col-span-2 ds-card">
            <div class="ds-card-header"><h3>Контактное лицо</h3></div>
            <div class="ds-card-body space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">ФИО</label>
                        <input type="text" wire:model="full_name" class="{{ $inputCls }}">
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Телефон</label>
                        <input type="text" wire:model="phone" class="{{ $inputCls }} mono">
                    </div>
                </div>
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Заметки</label>
                    <textarea wire:model="notes" rows="2" class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                </div>
                <div class="flex gap-2 pt-1">
                    <button type="button" wire:click="save" class="btn btn-sm btn-primary">Сохранить</button>
                </div>

                {{-- Организации контакта --}}
                <div class="pt-2 border-t border-border-subtle">
                    <div class="text-[11.5px] text-fg-3 mb-1.5">Организации этого e-mail:</div>
                    @if($this->organizations->isEmpty())
                        <div class="text-[12px] text-fg-4">Не привязан ни к одной организации. Привязать можно в карточке организации.</div>
                    @else
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($this->organizations as $o)
                                <a href="{{ route('clients.show', $o->id) }}" wire:navigate
                                   class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-border bg-surface text-[12px] text-fg-1 hover:bg-hover">
                                    {{ \Illuminate\Support\Str::limit($o->name, 40) }}
                                    @if($o->inn)<span class="mono text-fg-4">· {{ $o->inn }}</span>@endif
                                    @if($o->discount_percent > 0)<span class="text-emerald-700 font-medium">· {{ rtrim(rtrim(number_format($o->discount_percent,2,'.',''),'0'),'.') }}%</span>@endif
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Статистика --}}
        <div class="ds-card">
            <div class="ds-card-header"><h3>Статистика</h3></div>
            <div class="ds-card-body">
                @php $st = $this->stats; @endphp
                <div class="grid grid-cols-2 gap-2 text-center">
                    <div class="rounded-md border border-border bg-surface px-2 py-2">
                        <div class="text-[18px] font-semibold text-fg-1 mono">{{ $st['requests'] }}</div>
                        <div class="text-[10.5px] uppercase tracking-wider text-fg-3">Заявок</div>
                    </div>
                    <div class="rounded-md border border-border bg-surface px-2 py-2">
                        <div class="text-[18px] font-semibold text-fg-2 mono">{{ $st['active'] }}</div>
                        <div class="text-[10.5px] uppercase tracking-wider text-fg-3">Активных</div>
                    </div>
                    <div class="rounded-md border border-emerald-200 bg-emerald-50 px-2 py-2">
                        <div class="text-[18px] font-semibold text-emerald-700 mono">{{ $st['won'] }}</div>
                        <div class="text-[10.5px] uppercase tracking-wider text-emerald-700">Успех</div>
                    </div>
                    <div class="rounded-md border border-red-200 bg-red-50 px-2 py-2">
                        <div class="text-[18px] font-semibold text-red-700 mono">{{ $st['lost'] }}</div>
                        <div class="text-[10.5px] uppercase tracking-wider text-red-700">Потеря</div>
                    </div>
                    <div class="rounded-md border border-border bg-surface px-2 py-2">
                        <div class="text-[18px] font-semibold text-fg-1 mono">{{ $st['quotations'] }}</div>
                        <div class="text-[10.5px] uppercase tracking-wider text-fg-3">КП</div>
                    </div>
                    <div class="rounded-md border border-border bg-surface px-2 py-2">
                        <div class="text-[18px] font-semibold text-fg-1 mono">{{ $st['paid'] }}/{{ $st['invoices'] }}</div>
                        <div class="text-[10.5px] uppercase tracking-wider text-fg-3">Счета (опл.)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Персональные автоуведомления (по e-mail контакта) --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>🔔 Автоуведомления</h3>
            <span class="text-[12px] text-fg-3 ml-2">снимите галочку, чтобы этот тип письма НЕ слать на <span class="mono">{{ $contact->email }}</span> (по умолчанию все включены)</span>
        </div>
        <div class="ds-card-body">
            @php $sup = $this->suppressedTypes; @endphp
            <div class="grid gap-1.5" style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr))">
                @foreach($this->notificationTypes as $t)
                    @php $enabled = ! in_array($t->value, $sup, true); @endphp
                    <button type="button"
                            wire:click="toggleNotification('{{ $t->value }}')"
                            wire:key="notif-{{ $t->value }}"
                            class="flex items-start gap-2 px-2 py-1.5 rounded border text-left w-full transition-colors {{ $enabled ? 'border-border hover:bg-hover' : 'border-amber-300 bg-amber-50' }}">
                        <span class="mt-0.5 w-4 h-4 shrink-0 rounded border flex items-center justify-center text-[10px] leading-none {{ $enabled ? 'bg-emerald-500 border-emerald-500 text-white' : 'border-border text-transparent' }}">✓</span>
                        <span class="flex-1 min-w-0">
                            <span class="text-[12.5px] font-medium {{ $enabled ? 'text-fg-1' : 'text-fg-3 line-through' }}">{{ $t->label() }}</span>
                            @unless($enabled)<span class="text-[10px] text-amber-700 ml-1">не слать</span>@endunless
                            <span class="block text-[10.5px] text-fg-4 leading-snug mt-0.5">{{ \Illuminate\Support\Str::limit($t->description(), 120) }}</span>
                        </span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- История заявок --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>Заявки</h3><span class="text-[12px] text-fg-3 ml-2">последние 25</span></div>
        <div class="ds-card-body overflow-x-auto">
            @if($this->recentRequests->isEmpty())
                <div class="text-sm text-fg-3">Заявок по этому e-mail нет.</div>
            @else
                <table class="w-full text-[12px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="text-left px-3 py-2">Заявка</th>
                            <th class="text-left px-3 py-2">Тема</th>
                            <th class="text-left px-3 py-2">Статус</th>
                            <th class="text-left px-3 py-2">Менеджер</th>
                            <th class="text-left px-3 py-2">Создана</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->recentRequests as $r)
                            <tr wire:key="req-{{ $r->id }}" class="border-b border-border-subtle hover:bg-hover">
                                <td class="px-3 py-2 whitespace-nowrap"><a href="{{ route('requests.show', $r->id) }}" wire:navigate class="mono text-sky-700 hover:underline">{{ $r->internal_code }}</a></td>
                                <td class="px-3 py-2 text-fg-2"><span class="truncate inline-block max-w-[280px] align-bottom">{{ $r->subject ?: '—' }}</span></td>
                                <td class="px-3 py-2"><span class="chip {{ $r->status?->chipClass() ?? 'chip-neutral' }} text-[10.5px]">{{ $r->status?->label() ?? $r->status?->value }}</span></td>
                                <td class="px-3 py-2 text-fg-3 whitespace-nowrap">{{ $r->assignedUser?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-fg-3 mono whitespace-nowrap">{{ $r->created_at?->format('d.m.Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- Запросы поставщику (если контрагент бывает и поставщиком) --}}
    @if($this->supplierInquiries->isNotEmpty())
        <div class="ds-card">
            <div class="ds-card-header">
                <h3>Запросы поставщику</h3>
                <span class="text-[12px] text-fg-3 ml-2">переписка по нашим запросам расценки (не заявки клиента)</span>
            </div>
            <div class="ds-card-body overflow-x-auto">
                <table class="w-full text-[12px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="text-left px-3 py-2">Тема запроса</th>
                            <th class="text-right px-3 py-2">Писем</th>
                            <th class="text-left px-3 py-2">Статус</th>
                            <th class="text-left px-3 py-2">Создан</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->supplierInquiries as $si)
                            <tr wire:key="si-{{ $si->id }}" class="border-b border-border-subtle hover:bg-hover">
                                <td class="px-3 py-2"><a href="{{ route('suppliers.show', $si->id) }}" wire:navigate class="text-sky-700 hover:underline"><span class="truncate inline-block max-w-[320px] align-bottom">{{ $si->subject ?: '—' }}</span></a></td>
                                <td class="px-3 py-2 text-right mono text-fg-2">{{ $si->messages_count }}</td>
                                <td class="px-3 py-2"><span class="chip {{ $si->status === 'closed' ? 'chip-neutral' : 'chip-sky' }} text-[10.5px]">{{ $si->status === 'closed' ? 'закрыт' : 'открыт' }}</span></td>
                                <td class="px-3 py-2 text-fg-3 mono whitespace-nowrap">{{ $si->created_at?->format('d.m.Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
