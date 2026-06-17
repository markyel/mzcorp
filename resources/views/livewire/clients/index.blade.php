<div class="space-y-4">
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Клиенты</h3>
            {{-- Вкладки --}}
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden ml-3 text-[12px]">
                @foreach(['contacts' => 'Контакты', 'organizations' => 'Организации'] as $k => $label)
                    @php $on = $tab === $k; @endphp
                    <button type="button" wire:click="setTab('{{ $k }}')"
                            class="h-[26px] px-3 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <span class="flex-1"></span>
            @if($tab === 'organizations')
                <button type="button" wire:click="startCreate" class="btn btn-sm btn-primary mr-3">+ Организация</button>
                <span class="text-[11.5px] text-fg-3 mono">{{ $this->organizations->total() }}</span>
            @else
                <span class="text-[11.5px] text-fg-3 mono">{{ $this->contacts->total() }}</span>
            @endif
        </div>

        <div class="px-4 pb-3">
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="{{ $tab === 'organizations' ? 'Поиск: название / ИНН / КПП / контакт' : 'Поиск: email / ФИО / телефон' }}"
                   class="h-[30px] w-full max-w-[440px] px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
        </div>

        @if($creating && $tab === 'organizations')
            <div class="px-4 pb-3">
                <div class="border border-border rounded-md p-3 bg-surface-2 max-w-[560px] space-y-2">
                    <div class="text-[12px] text-fg-2 font-medium">Новая организация</div>
                    <div>
                        <input type="text" wire:model="newName" placeholder="Название (ООО «…» / ИП …)" class="h-[30px] w-full px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
                        @error('newName') <div class="text-[11px] text-red-600 mt-0.5">{{ $message }}</div> @enderror
                    </div>
                    <input type="text" wire:model="newInn" placeholder="ИНН (необязательно)" class="h-[30px] w-full px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
                    <div class="flex gap-2 pt-1">
                        <button type="button" wire:click="createOrganization" class="btn btn-sm btn-primary">Создать</button>
                        <button type="button" wire:click="cancelCreate" class="btn btn-sm">Отмена</button>
                    </div>
                </div>
            </div>
        @endif

        <div class="overflow-x-auto">
            @if($tab === 'contacts')
                @php $reqCounts = $this->contactRequestCounts; @endphp
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="text-left px-3 py-2">E-mail</th>
                            <th class="text-left px-3 py-2">ФИО</th>
                            <th class="text-left px-3 py-2">Телефон</th>
                            <th class="text-right px-3 py-2">Организаций</th>
                            <th class="text-right px-3 py-2">Заявок</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->contacts as $c)
                            <tr wire:key="contact-{{ $c->id }}" class="border-b border-border-subtle hover:bg-hover">
                                <td class="px-3 py-2">
                                    <a href="{{ route('clients.contact', $c->id) }}" wire:navigate class="mono text-sky-700 hover:underline">{{ $c->email }}</a>
                                </td>
                                <td class="px-3 py-2 text-fg-2">{{ $c->full_name ?: '—' }}</td>
                                <td class="px-3 py-2 text-fg-2 mono">{{ $c->phone ?: '—' }}</td>
                                <td class="px-3 py-2 text-right mono text-fg-3">{{ $c->organizations_count ?: '—' }}</td>
                                <td class="px-3 py-2 text-right mono text-fg-2">{{ $reqCounts[mb_strtolower($c->email)] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-10 text-center text-fg-3 text-[13px]">{{ trim($search) !== '' ? 'Ничего не найдено.' : 'Контактов пока нет.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $this->contacts->links() }}</div>
            @else
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="text-left px-3 py-2">Организация</th>
                            <th class="text-left px-3 py-2">ИНН · КПП</th>
                            <th class="text-right px-3 py-2">Контактов</th>
                            <th class="text-right px-3 py-2">Скидка</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->organizations as $o)
                            <tr wire:key="org-{{ $o->id }}" class="border-b border-border-subtle hover:bg-hover">
                                <td class="px-3 py-2">
                                    <a href="{{ route('clients.show', $o->id) }}" wire:navigate class="text-sky-700 hover:underline font-medium">{{ $o->name }}</a>
                                </td>
                                <td class="px-3 py-2 mono text-fg-2 whitespace-nowrap">{{ $o->inn ?: '—' }}@if($o->kpp) · {{ $o->kpp }}@endif</td>
                                <td class="px-3 py-2 text-right mono text-fg-2">{{ $o->contacts_count }}</td>
                                <td class="px-3 py-2 text-right mono {{ $o->discount_percent > 0 ? 'text-emerald-700 font-semibold' : 'text-fg-4' }}">{{ $o->discount_percent > 0 ? rtrim(rtrim(number_format($o->discount_percent, 2, '.', ''), '0'), '.') . '%' : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-10 text-center text-fg-3 text-[13px]">{{ trim($search) !== '' ? 'Ничего не найдено.' : 'Пока нет организаций.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $this->organizations->links() }}</div>
            @endif
        </div>
    </div>
</div>
