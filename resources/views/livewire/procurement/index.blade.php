<div class="space-y-4">
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
                        <th class="text-right px-2 py-2" style="width:40px">#</th>
                        <th class="text-left px-2 py-2" style="width:130px">Артикул</th>
                        <th class="text-left px-2 py-2">Наименование</th>
                        <th class="text-left px-2 py-2" style="width:120px">Бренд</th>
                        <th class="text-right px-2 py-2" style="width:90px">Заявок</th>
                        <th class="text-left px-2 py-2">Заявки</th>
                        <th class="text-right px-2 py-2" style="width:110px">Цена (ст.)</th>
                        <th class="text-left px-2 py-2" style="width:90px">Статус</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->positions as $i => $p)
                        <tr wire:key="blk-{{ $p['cid'] }}" class="border-b border-border-subtle hover:bg-hover align-top">
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
                                @if($p['in_flight'])
                                    <span class="chip chip-sky text-[10.5px]">⏳ запрошено</span>
                                @else
                                    <span class="text-fg-4 text-[11px]">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-10 text-center text-fg-3 text-[13px]">{{ trim($search) !== '' ? 'Ничего не найдено.' : 'Нет позиций с неактуальной ценой в заявках до выдачи КП.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3">{{ $this->positions->links() }}</div>
    </div>

    <div class="text-[11.5px] text-fg-4">
        Формирование запросов поставщикам по выбранным позициям (по M-артикулу) — в следующем шаге раздела.
    </div>
</div>
