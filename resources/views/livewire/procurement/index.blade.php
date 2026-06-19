<div class="space-y-4">
    @if(session('procurement_status'))
        <div class="ds-card"><div class="ds-card-body text-[13px] text-emerald-700">{{ session('procurement_status') }}</div></div>
    @endif

    {{-- Заголовок + сводка --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h2 class="text-[16px] font-semibold text-fg-1">⛏ Снабжение</h2>
            <span class="text-[12px] text-fg-3 ml-2">позиции, сдерживающие выдачу КП</span>
        </div>
        <div class="ds-card-body">
            @php $sum = $this->summary; @endphp
            <div class="flex flex-wrap gap-6 text-[13px]">
                <div><span class="text-[22px] font-semibold text-fg-1 mono">{{ $sum['positions'] }}</span> <span class="text-fg-3">M-позиций с неактуальной ценой</span></div>
                <div><span class="text-[22px] font-semibold text-fg-1 mono">{{ $sum['requests'] }}</span> <span class="text-fg-3">заявок ждут актуализацию (до-КП)</span></div>
            </div>
            <div class="text-[11.5px] text-fg-4 mt-2">
                Считаем заявки в статусах <b>Новая / Назначена / В работе / Уточнение</b> со сматченными позициями (M-артикул), у которых цена в каталоге неактуальна. Чем в большем числе заявок зависла позиция — тем выше приоритет обновить её цену.
            </div>
        </div>
    </div>

    {{-- Поиск --}}
    <div>
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Поиск: артикул / наименование / бренд"
               class="h-[32px] w-full max-w-[440px] px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
    </div>

    {{-- Топ позиций-блокеров --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>Топ позиций-блокеров</h3></div>
        <div class="ds-card-body overflow-x-auto">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                    <tr>
                        <th class="px-2 py-2" style="width:30px"></th>
                        <th class="text-right px-2 py-2" style="width:40px">#</th>
                        <th class="text-left px-2 py-2" style="width:130px">Артикул</th>
                        <th class="text-left px-2 py-2">Наименование</th>
                        <th class="text-left px-2 py-2" style="width:120px">Бренд</th>
                        <th class="text-right px-2 py-2" style="width:90px">Заявок</th>
                        <th class="text-left px-2 py-2">Заявки</th>
                        <th class="text-right px-2 py-2" style="width:110px">Цена (ст.)</th>
                        <th class="text-left px-2 py-2" style="width:150px">IQOT (конкуренты)</th>
                        <th class="text-left px-2 py-2" style="width:90px">Статус</th>
                    </tr>
                </thead>
                @forelse($this->positions as $i => $p)
                    @php
                        $iqp = $this->iqotByCatalogId->get($p['cid']);
                        $hasIqot = $iqp !== null;
                    @endphp
                    <tbody x-data="{ open: false }" wire:key="blk-{{ $p['cid'] }}">
                        <tr class="border-b border-border-subtle hover:bg-hover align-top">
                            <td class="px-2 py-2 text-center"><input type="checkbox" wire:model.live="selected.{{ $p['cid'] }}"></td>
                            <td class="px-2 py-2 text-right mono text-fg-4">{{ $this->positions->firstItem() + $i }}</td>
                            <td class="px-2 py-2 mono text-fg-2">{{ $p['sku'] }}</td>
                            <td class="px-2 py-2 text-fg-1">{{ \Illuminate\Support\Str::limit($p['name'], 64) }}</td>
                            <td class="px-2 py-2 text-fg-3">{{ $p['brand'] ?: '—' }}</td>
                            <td class="px-2 py-2 text-right"><span class="chip chip-warn text-[11px] mono">{{ $p['req_count'] }}</span></td>
                            <td class="px-2 py-2">
                                <span class="flex flex-wrap gap-1">
                                    @foreach($p['codes'] as $c)
                                        <a href="{{ route('requests.show', $c['id']) }}" wire:navigate
                                           class="text-sky-700 hover:underline mono text-[11px]">{{ $c['code'] }}</a>
                                    @endforeach
                                    @if($p['req_count'] > count($p['codes']))<span class="text-fg-4 text-[11px]">+{{ $p['req_count'] - count($p['codes']) }}</span>@endif
                                </span>
                            </td>
                            <td class="px-2 py-2 text-right mono text-fg-3">{{ $p['price'] !== null ? number_format((float)$p['price'], 2, '.', ' ') : '—' }}</td>
                            <td class="px-2 py-2">
                                @if($hasIqot)
                                    <button type="button" @click="open = !open"
                                            class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-[10.5px] font-medium hover:bg-emerald-100">
                                        <span>IQOT</span>
                                        @if($iqp->report_min_price !== null)<span>: мин. {{ number_format((float) $iqp->report_min_price, 0, ',', ' ') }} ₽</span>@endif
                                        <span>· {{ $iqp->report_offers_count ?? count($iqp->offers()) }} офф.</span>
                                        <span x-text="open ? '▾' : '▸'"></span>
                                    </button>
                                @else
                                    <span class="text-fg-4 text-[11px]">нет данных</span>
                                @endif
                            </td>
                            <td class="px-2 py-2">
                                @if($p['in_flight'])
                                    <span class="chip chip-sky text-[10.5px]">⏳ запрошено</span>
                                @else
                                    <span class="text-fg-4 text-[11px]">—</span>
                                @endif
                            </td>
                        </tr>
                        @if($hasIqot)
                            <tr x-show="open" x-cloak class="border-b border-border-subtle bg-surface-2">
                                <td colspan="10" class="px-4 py-2.5">
                                    @include('livewire.iqot._comparison', ['pos' => $iqp])
                                </td>
                            </tr>
                        @endif
                    </tbody>
                @empty
                    <tbody>
                        <tr><td colspan="10" class="px-3 py-10 text-center text-fg-3 text-[13px]">{{ trim($search) !== '' ? 'Ничего не найдено.' : 'Нет позиций с неактуальной ценой в заявках до выдачи КП.' }}</td></tr>
                    </tbody>
                @endforelse
            </table>
        </div>
        <div class="px-4 py-3">{{ $this->positions->links() }}</div>
    </div>

    {{-- Панель запроса поставщикам (по выбранным позициям) --}}
    @php $selPos = $this->selectedPositions; @endphp
    @if($selPos->isNotEmpty())
        <div class="ds-card">
            <div class="ds-card-header"><h3>Запрос поставщикам</h3><span class="text-[12px] text-fg-3 ml-2">выбрано позиций: {{ $selPos->count() }}</span></div>
            <div class="ds-card-body space-y-3">
                {{-- Позиции (название редактируемое) --}}
                <div class="border border-border rounded-md p-3 bg-surface-2">
                    <div class="text-[11px] uppercase tracking-wider text-fg-3 mb-2">Позиции в письме (название можно править; по умолчанию — из каталога)</div>
                    <div class="space-y-1.5">
                        @foreach($selPos as $i => $ci)
                            <div class="flex items-center gap-2" wire:key="selpos-{{ $ci->id }}">
                                <span class="text-[11px] text-fg-4 w-4 text-right">{{ $i + 1 }}.</span>
                                <input type="text" wire:model.lazy="editedNames.{{ $ci->id }}"
                                       class="flex-1 px-2 h-[28px] border border-border rounded bg-surface text-[12.5px] outline-none focus:border-sky-500">
                                <span class="text-[11px] text-fg-4 whitespace-nowrap mono">{{ trim(implode(' · ', array_filter([$ci->sku, $ci->brand_article]))) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Поставщики --}}
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Поставщики <span class="text-fg-4">— подобраны по матрице под выбранные позиции</span></label>
                    <div class="border border-border rounded-md divide-y divide-border-subtle">
                        @forelse($this->supplierOptions as $o)
                            <label class="flex items-start gap-2 px-3 py-2 cursor-pointer hover:bg-hover">
                                <input type="checkbox" wire:model.live="selectedSuppliers.{{ $o['id'] }}" class="mt-1">
                                <span class="flex-1">
                                    <span class="text-[13px] text-fg-1 font-medium">{{ $o['name'] }}</span>
                                    @if($o['matched'])<span class="chip chip-sky text-[10px] ml-1">подходит · {{ $o['item_count'] }} поз.</span>
                                    @else<span class="chip chip-neutral text-[10px] ml-1">добавлен вручную</span>@endif
                                    @if($o['email'])<span class="block text-[11px] text-fg-4 mono">{{ $o['email'] }}</span>@endif
                                </span>
                            </label>
                        @empty
                            <div class="px-3 py-3 text-[12px] text-amber-700">По выбранным позициям нет подходящих поставщиков — добавьте вручную ниже.</div>
                        @endforelse
                    </div>
                    <div class="mt-2">
                        <input type="search" wire:model.live.debounce.300ms="supplierSearch" placeholder="Добавить поставщика: название / email / домен"
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
                </div>

                {{-- Обращение + примечание --}}
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Обращение <span class="text-fg-4">— {поставщик} подставится; для англоязычных — «Hello …»</span></label>
                    <input type="text" wire:model="greeting" class="w-full px-2 h-[30px] border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
                </div>
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Примечание <span class="text-fg-4">(необязательно)</span></label>
                    <textarea wire:model="note" rows="2" class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                </div>

                @error('send') <div class="text-[12px] text-red-600">{{ $message }}</div> @enderror

                <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                    @php $selSups = collect($this->selectedSuppliers)->filter()->count(); @endphp
                    <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send"
                            class="btn btn-primary" @disabled($selSups === 0)>
                        <span wire:loading.remove wire:target="send">Отправить запросы ({{ $selSups }})</span>
                        <span wire:loading wire:target="send">Отправляю…</span>
                    </button>
                    <span class="text-[11.5px] text-fg-3">по M-артикулу; цена обновится в каталоге → заявки получат сигнал «💰»</span>
                </div>
            </div>
        </div>
    @endif
</div>
