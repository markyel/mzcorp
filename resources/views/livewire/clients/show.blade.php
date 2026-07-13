<div class="space-y-4">
    @php $inputCls = 'h-[30px] w-full px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500'; @endphp

    {{-- Заголовок --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('clients.index') }}" wire:navigate class="text-[12px] text-sky-700 hover:underline">← Клиенты</a>
        <h2 class="text-[16px] font-semibold text-fg-1">{{ $organization->name }}</h2>
        @if($organization->isCostPlus())
            <span class="chip chip-sky text-[11px]">спеццена: себестоимость + {{ $this->costPlusMarkup }}%</span>
        @elseif($organization->discount_percent > 0)
            <span class="chip chip-neutral text-[11px]">скидка {{ rtrim(rtrim(number_format($organization->discount_percent, 2, '.', ''), '0'), '.') }}%</span>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Реквизиты --}}
        <div class="lg:col-span-2 ds-card">
            <div class="ds-card-header"><h3>Реквизиты и скидка</h3></div>
            <div class="ds-card-body space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="sm:col-span-2">
                        <label class="block text-[11.5px] text-fg-3 mb-1">Название</label>
                        <input type="text" wire:model="name" class="{{ $inputCls }}">
                        @error('name') <div class="text-[11px] text-red-600 mt-0.5">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">ИНН</label>
                        <input type="text" wire:model="inn" class="{{ $inputCls }} mono">
                        @error('inn') <div class="text-[11px] text-red-600 mt-0.5">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">КПП</label>
                        <input type="text" wire:model="kpp" class="{{ $inputCls }} mono">
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Скидка, %</label>
                        <input type="number" step="0.01" min="0" max="100" wire:model="discount_percent" class="{{ $inputCls }} mono">
                        @error('discount_percent') <div class="text-[11px] text-red-600 mt-0.5">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Адрес</label>
                        <input type="text" wire:model="address" class="{{ $inputCls }}">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-[11.5px] text-fg-3 mb-1">Режим цены</label>
                        <select wire:model="pricing_mode" class="{{ $inputCls }}">
                            @foreach($this->pricingModes as $mode)
                                <option value="{{ $mode->value }}">{{ $mode->label() }}</option>
                            @endforeach
                        </select>
                        @error('pricing_mode') <div class="text-[11px] text-red-600 mt-0.5">{{ $message }}</div> @enderror
                        <div class="text-[11px] text-fg-4 mt-0.5">
                            «Себестоимость + наценка»: цена = закупочная × (1 + {{ $this->costPlusMarkup }}%), без минималки и без скидки. Скидка выше игнорируется.
                        </div>
                    </div>
                </div>
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Реквизиты для КП/счёта <span class="text-fg-4">(банк, р/с, к/с, БИК, ОГРН)</span></label>
                    <textarea wire:model="requisites_text" rows="4" class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                </div>
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Заметки</label>
                    <textarea wire:model="notes" rows="2" class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                </div>
                <div class="flex gap-2 pt-1">
                    <button type="button" wire:click="save" class="btn btn-sm btn-primary">Сохранить</button>
                </div>
            </div>
        </div>

        {{-- Статистика --}}
        <div class="ds-card">
            <div class="ds-card-header"><h3>Статистика</h3><span class="text-[12px] text-fg-3 ml-2">по заявкам организации</span></div>
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

    {{-- Контакты --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Контакты</h3>
            <span class="text-[12px] text-fg-3 ml-2">e-mail'ы заказчика (один email может быть у нескольких организаций)</span>
        </div>
        <div class="ds-card-body space-y-3">
            {{-- Добавить контакт --}}
            <div class="flex flex-wrap items-start gap-2">
                <div class="flex-1 min-w-[200px]">
                    <input type="email" wire:model="newContactEmail" placeholder="email@client.ru" class="{{ $inputCls }}">
                    @error('newContactEmail') <div class="text-[11px] text-red-600 mt-0.5">{{ $message }}</div> @enderror
                </div>
                <input type="text" wire:model="newContactName" placeholder="ФИО (необязательно)" class="{{ $inputCls }} flex-1 min-w-[160px]">
                <input type="text" wire:model="newContactPhone" placeholder="Телефон" class="{{ $inputCls }} w-[150px]">
                <button type="button" wire:click="addContact" class="btn btn-sm btn-primary shrink-0">Добавить</button>
            </div>

            {{-- Список контактов --}}
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="text-left px-3 py-2">E-mail</th>
                            <th class="text-left px-3 py-2">ФИО</th>
                            <th class="text-left px-3 py-2">Телефон</th>
                            <th class="text-right px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->contacts as $c)
                            <tr wire:key="contact-{{ $c->id }}" class="border-b border-border-subtle hover:bg-hover">
                                @if($editingContactId === $c->id)
                                    <td class="px-3 py-2 mono text-fg-2">{{ $c->email }}</td>
                                    <td class="px-3 py-2"><input type="text" wire:model="editName" class="{{ $inputCls }}"></td>
                                    <td class="px-3 py-2"><input type="text" wire:model="editPhone" class="{{ $inputCls }} max-w-[150px]"></td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        <button type="button" wire:click="saveContact" class="btn btn-sm btn-primary">OK</button>
                                        <button type="button" wire:click="cancelEditContact" class="btn btn-sm">×</button>
                                    </td>
                                @else
                                    <td class="px-3 py-2 mono text-fg-1">{{ $c->email }}</td>
                                    <td class="px-3 py-2 text-fg-2">{{ $c->full_name ?: '—' }}</td>
                                    <td class="px-3 py-2 text-fg-2 mono">{{ $c->phone ?: '—' }}</td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        @php $supCnt = count($this->notificationOptouts[mb_strtolower($c->email)] ?? []); @endphp
                                        <button type="button" wire:click="toggleNotifPanel({{ $c->id }})"
                                                class="text-[11.5px] hover:underline mr-2 {{ $supCnt > 0 ? 'text-amber-700' : 'text-fg-3' }}"
                                                title="Персональные автоуведомления">🔔@if($supCnt > 0) <span class="mono">−{{ $supCnt }}</span>@endif</button>
                                        <button type="button" wire:click="startEditContact({{ $c->id }})" class="text-[11.5px] text-sky-700 hover:underline mr-2">✎</button>
                                        <button type="button" wire:click="detachContact({{ $c->id }})" wire:confirm="Отвязать {{ $c->email }} от организации?" class="text-[11.5px] text-red-600 hover:underline">отвязать</button>
                                    </td>
                                @endif
                            </tr>
                            @if($openNotifContactId === $c->id)
                                @php $sup = $this->notificationOptouts[mb_strtolower($c->email)] ?? []; @endphp
                                <tr wire:key="notif-{{ $c->id }}" class="bg-surface-2 border-b border-border-subtle">
                                    <td colspan="4" class="px-3 py-3">
                                        <div class="text-[11.5px] text-fg-3 mb-2">
                                            🔔 Автоуведомления для <span class="mono text-fg-2">{{ $c->email }}</span>
                                            <span class="text-fg-4">— снимите галочку, чтобы этот тип письма НЕ слать данному клиенту (по умолчанию все включены).</span>
                                        </div>
                                        <div class="grid gap-1.5" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr))">
                                            @foreach($this->notificationTypes as $t)
                                                @php $enabled = ! in_array($t->value, $sup, true); @endphp
                                                <button type="button"
                                                        wire:click="toggleContactNotification(@js($c->email), '{{ $t->value }}')"
                                                        wire:key="notif-{{ $c->id }}-{{ $t->value }}"
                                                        class="flex items-start gap-2 px-2 py-1.5 rounded border text-left w-full transition-colors {{ $enabled ? 'border-border hover:bg-hover' : 'border-amber-300 bg-amber-50' }}">
                                                    <span class="mt-0.5 w-4 h-4 shrink-0 rounded border flex items-center justify-center text-[10px] leading-none {{ $enabled ? 'bg-emerald-500 border-emerald-500 text-white' : 'border-border text-transparent' }}">✓</span>
                                                    <span class="flex-1 min-w-0">
                                                        <span class="text-[12.5px] font-medium {{ $enabled ? 'text-fg-1' : 'text-fg-3 line-through' }}">{{ $t->label() }}</span>
                                                        @unless($enabled)<span class="text-[10px] text-amber-700 ml-1">не слать</span>@endunless
                                                        <span class="block text-[10.5px] text-fg-4 leading-snug mt-0.5">{{ \Illuminate\Support\Str::limit($t->description(), 110) }}</span>
                                                    </span>
                                                </button>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-fg-3">Контактов пока нет — добавьте email выше.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Заявки организации --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Заявки организации</h3>
            <span class="text-[12px] text-fg-3 ml-2">последние 25 · точная привязка + ещё не привязанные по e-mail</span>
        </div>
        <div class="ds-card-body overflow-x-auto">
            @if($this->recentRequests->isEmpty())
                <div class="text-sm text-fg-3">Заявок этой организации пока нет.</div>
            @else
                <table class="w-full text-[12px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="text-left px-3 py-2">Заявка</th>
                            <th class="text-left px-3 py-2">Тема</th>
                            <th class="text-left px-3 py-2">E-mail</th>
                            <th class="text-left px-3 py-2">Статус</th>
                            <th class="text-left px-3 py-2">Менеджер</th>
                            <th class="text-left px-3 py-2">Создана</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->recentRequests as $r)
                            <tr wire:key="org-req-{{ $r->id }}" class="border-b border-border-subtle hover:bg-hover">
                                <td class="px-3 py-2 whitespace-nowrap"><a href="{{ route('requests.show', $r->id) }}" wire:navigate class="mono text-sky-700 hover:underline">{{ $r->internal_code }}</a></td>
                                <td class="px-3 py-2 text-fg-2"><span class="truncate inline-block max-w-[240px] align-bottom">{{ $r->subject ?: '—' }}</span></td>
                                <td class="px-3 py-2 text-fg-3 mono"><span class="truncate inline-block max-w-[180px] align-bottom">{{ $r->client_email ?: '—' }}</span></td>
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

    {{-- Удаление --}}
    <div class="ds-card">
        <div class="ds-card-body flex items-center justify-between gap-3 flex-wrap">
            <div class="text-[12px] text-fg-3">Удалить организацию из реестра. Контакты и заявки не удаляются — только сама организация и её связи.</div>
            @if($confirmingDelete)
                <div class="flex items-center gap-2">
                    <span class="text-[12px] text-red-700">Точно удалить «{{ $organization->name }}»?</span>
                    <button type="button" wire:click="deleteOrganization" class="btn btn-sm" style="background:var(--red-600,#dc2626);color:#fff">Удалить</button>
                    <button type="button" wire:click="$set('confirmingDelete', false)" class="btn btn-sm">Отмена</button>
                </div>
            @else
                <button type="button" wire:click="$set('confirmingDelete', true)" class="btn btn-sm text-red-600">Удалить организацию</button>
            @endif
        </div>
    </div>
</div>
