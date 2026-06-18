<div class="space-y-4">
    @php
        $items = $this->items;
        $opts = $this->supplierOptions;
        $atts = $this->requestAttachments;
        $staleCount = collect($items)->where('price_stale', true)->count();
        $selItems = collect($this->selectedItems)->filter()->count();
        $selSups = collect($this->selectedSuppliers)->filter()->count();
    @endphp

    @if(session('status'))
        <div class="ds-card"><div class="ds-card-body text-[13px] text-emerald-700">{{ session('status') }}</div></div>
    @endif

    {{-- 1. Позиции --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Позиции для запроса цен</h3>
            <span class="text-[12px] text-fg-3 ml-2">выбрано {{ $selItems }} из {{ count($items) }}</span>
            <span class="flex-1"></span>
            @if($staleCount > 0)
                <button type="button" wire:click="selectStale" class="btn btn-sm" title="Отметить только позиции с неактуальной ценой">⚠ Только неактуальные ({{ $staleCount }})</button>
            @endif
        </div>
        <div class="ds-card-body overflow-x-auto">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                    <tr>
                        <th class="px-2 py-2 w-[34px]"></th>
                        <th class="text-left px-2 py-2">Наименование</th>
                        <th class="text-left px-2 py-2">OEM / бренд</th>
                        <th class="text-right px-2 py-2">Кол-во</th>
                        <th class="text-left px-2 py-2">Цена</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $it)
                        <tr class="border-b border-border-subtle {{ $it['price_stale'] ? 'bg-amber-50' : '' }}">
                            <td class="px-2 py-2 text-center"><input type="checkbox" wire:model.live="selectedItems.{{ $it['id'] }}"></td>
                            <td class="px-2 py-2">
                                <div class="text-fg-1">{{ \Illuminate\Support\Str::limit($it['name'], 70) }}</div>
                                @if($it['client_name'])<div class="text-[11px] text-fg-4">клиент: {{ \Illuminate\Support\Str::limit($it['client_name'], 60) }}</div>@endif
                            </td>
                            <td class="px-2 py-2 text-fg-3">{{ trim(implode(' · ', array_filter([$it['oem'], $it['brand']]))) ?: '—' }}</td>
                            <td class="px-2 py-2 text-right mono">{{ $it['qty'] ?: '—' }}</td>
                            <td class="px-2 py-2">
                                @if(! $it['has_catalog'])
                                    <span class="text-[11px] text-fg-4">не в каталоге</span>
                                @elseif($it['price_stale'])
                                    <span class="chip chip-warn text-[10.5px]">неактуальна</span>
                                @else
                                    <span class="chip chip-ok text-[10.5px]">актуальна</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-fg-3">Нет активных позиций.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 2. Поставщики --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Поставщики</h3>
            <span class="text-[12px] text-fg-3 ml-2">подобраны по матрице под выбранные позиции · отмечено {{ $selSups }}</span>
        </div>
        <div class="ds-card-body space-y-3">
            @if($selItems === 0)
                <div class="text-[12.5px] text-fg-3">Сначала выберите позиции выше.</div>
            @else
                <div class="border border-border rounded-md divide-y divide-border-subtle">
                    @forelse($opts as $o)
                        <label class="flex items-start gap-2 px-3 py-2 cursor-pointer hover:bg-hover">
                            <input type="checkbox" wire:model.live="selectedSuppliers.{{ $o['id'] }}" class="mt-1">
                            <span class="flex-1">
                                <span class="text-[13px] text-fg-1 font-medium">{{ $o['name'] }}</span>
                                @if($o['matched'])
                                    <span class="chip chip-sky text-[10px] ml-1">подходит · {{ $o['item_count'] }} поз.</span>
                                @else
                                    <span class="chip chip-neutral text-[10px] ml-1">добавлен вручную</span>
                                @endif
                                @if($o['email'])<span class="block text-[11px] text-fg-4 mono">{{ $o['email'] }}</span>@endif
                            </span>
                        </label>
                    @empty
                        <div class="px-3 py-3 text-[12px] text-amber-700">По выбранным позициям нет подходящих поставщиков — добавьте вручную ниже.</div>
                    @endforelse
                </div>

                {{-- Ручное добавление любого поставщика --}}
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Добавить поставщика вручную</label>
                    <input type="search" wire:model.live.debounce.300ms="supplierSearch" placeholder="Поиск: название / email / домен"
                           class="h-[30px] w-full max-w-[420px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
                    @if($this->searchResults->isNotEmpty())
                        <div class="mt-1 border border-border rounded-md max-w-[420px] divide-y divide-border-subtle">
                            @foreach($this->searchResults as $s)
                                <button type="button" wire:click="addSupplier({{ $s->id }})" class="w-full text-left px-3 py-1.5 hover:bg-hover text-[12.5px]">
                                    {{ $s->name ?: $s->email }} <span class="text-fg-4 mono text-[11px]">{{ $s->email }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- 3. Превью письма + вложения --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>Письмо запроса</h3></div>
        <div class="ds-card-body space-y-3">
            {{-- Обращение (персональное per поставщик) --}}
            <div>
                <label class="block text-[11.5px] text-fg-3 mb-1">Обращение <span class="text-fg-4">— {поставщик} подставится для каждого поставщика</span></label>
                <input type="text" wire:model="greeting" placeholder="Здравствуйте, {поставщик}!"
                       class="w-full px-2 h-[30px] border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
            </div>

            {{-- Превью позиций — РЕДАКТИРУЕМЫЕ названия --}}
            <div class="border border-border rounded-md p-3 bg-surface-2">
                <div class="text-[11px] uppercase tracking-wider text-fg-3 mb-2">Позиции в письме (название можно править; по умолчанию — из каталога при сматченном M-артикуле)</div>
                @php $selectedRows = collect($items)->filter(fn ($i) => ($this->selectedItems[$i['id']] ?? false)); @endphp
                @if($selectedRows->isEmpty())
                    <div class="text-[12px] text-fg-4">Выберите позиции выше — появится список.</div>
                @else
                    <div class="space-y-1.5">
                        @foreach($selectedRows as $i => $it)
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] text-fg-4 w-4 text-right">{{ $loop->iteration }}.</span>
                                <input type="text" wire:model.lazy="editedNames.{{ $it['id'] }}"
                                       class="flex-1 px-2 h-[28px] border border-border rounded bg-surface text-[12.5px] outline-none focus:border-sky-500">
                                <span class="text-[11px] text-fg-4 whitespace-nowrap">{{ trim(implode(' · ', array_filter([$it['oem'], $it['qty']]))) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                <label class="block text-[11.5px] text-fg-3 mb-1">Примечание <span class="text-fg-4">(необязательно)</span></label>
                <textarea wire:model="note" rows="2" placeholder="Напр.: срочно; нужен аналог; уточните срок доставки"
                          class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
            </div>

            {{-- Вложения заявки --}}
            @if($atts->isNotEmpty())
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Файлы из заявки</label>
                    <div class="flex flex-wrap gap-3">
                        @foreach($atts as $a)
                            <label class="flex items-center gap-1.5 text-[12px] cursor-pointer">
                                <input type="checkbox" wire:model="selectedAttachments.{{ $a->id }}">
                                <span class="mono text-fg-2">{{ \Illuminate\Support\Str::limit($a->filename, 40) }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Файлы с диска --}}
            <div>
                <label class="block text-[11.5px] text-fg-3 mb-1">Прикрепить файлы с диска</label>
                <input type="file" wire:model="newFiles" multiple class="text-[12px]">
                @error('newFiles.*') <div class="text-[11px] text-red-600 mt-0.5">{{ $message }}</div> @enderror
                @if(count($newFiles) > 0)
                    <div class="flex flex-wrap gap-2 mt-1.5">
                        @foreach($newFiles as $idx => $f)
                            <span class="inline-flex items-center gap-1 text-[11.5px] bg-surface border border-border rounded px-2 py-0.5">
                                {{ \Illuminate\Support\Str::limit($f->getClientOriginalName(), 30) }}
                                <button type="button" wire:click="removeNewFile({{ $idx }})" class="text-red-600">×</button>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            @error('send') <div class="text-[12px] text-red-600">{{ $message }}</div> @enderror

            <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send,newFiles"
                        class="btn btn-primary" @disabled($selItems === 0 || $selSups === 0)>
                    <span wire:loading.remove wire:target="send">Отправить запросы ({{ $selSups }})</span>
                    <span wire:loading wire:target="send">Отправляю…</span>
                </button>
                <span class="text-[11.5px] text-fg-3">каждому поставщику — отдельное письмо с его позициями</span>
            </div>
        </div>
    </div>
</div>
