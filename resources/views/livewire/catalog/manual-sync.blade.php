<div class="space-y-4">
    @php
        $sync = $this->sync;
        $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ');
        $statusLabel = [
            \App\Models\CatalogManualSync::STATUS_PARSED => ['ждёт «Применить»', 'chip-warn'],
            \App\Models\CatalogManualSync::STATUS_APPLIED => ['применена', 'chip-ok'],
            \App\Models\CatalogManualSync::STATUS_CANCELLED => ['отменена', 'chip-neutral'],
            \App\Models\CatalogManualSync::STATUS_FAILED => ['ошибка', 'chip-neutral'],
        ];
        $rowLabel = [
            'changed' => ['изменится', 'chip-sky'],
            'same' => ['без изменений', 'chip-neutral'],
            'unknown' => ['нет в каталоге', 'chip-warn'],
            'invalid' => ['ошибка в строке', 'chip-warn'],
        ];
    @endphp

    {{-- Загрузка --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h2 class="text-[16px] font-semibold text-fg-1">⟳ Синхронизация каталога</h2>
            <span class="text-[12px] text-fg-3 ml-2">актуализированные цены из 1С</span>
        </div>
        <div class="ds-card-body space-y-3">
            <div class="text-[12.5px] text-fg-3">
                Выгрузите из 1С список позиций с актуализированной ценой (Артикул, Наименование, Цена закупки, Маржа%,
                Цена продажи, Цена продажи (мин), Дата цены продажи…) и загрузите файл. Система покажет, что изменится, —
                цены и остатки попадут в каталог только после кнопки «Применить». Позиции станут «цена актуальна», в историю цен
                запишутся изменения, остаток на складе — из колонки «На складе &lt;Свободно&gt;». «В пути» берётся из основной
                выгрузки каталога: в файле 1С нет дат прихода.
            </div>
            @if($this->canManage)
            <form method="post" action="{{ route('catalog-sync.upload') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3">
                @csrf
                <input type="file" name="file" accept=".xlsx,.xls" required class="text-[13px]">
                <button type="submit" class="btn btn-primary">Загрузить и разобрать</button>
            </form>
            @else
                <div class="text-[12px] text-fg-4">Загружает и применяет выгрузку секретарь — здесь видно, что и когда обновлялось.</div>
            @endif
            @error('file')<div class="text-[12.5px] text-red-700">{{ $message }}</div>@enderror
        </div>
    </div>

    {{-- Разбор открытой загрузки --}}
    @if($sync)
        @php
            $rows = collect($sync->rows ?? []);
            $shown = $rowFilter === 'all' ? $rows : $rows->where('status', $rowFilter);
            $counts = $rows->countBy('status');
            $sharp = $rows->filter(fn ($r) => ($r['old']['price'] ?? null) && abs(($r['price'] - $r['old']['price']) / $r['old']['price']) >= 0.3)->count();
            $st = $statusLabel[$sync->status] ?? [$sync->status, 'chip-neutral'];
        @endphp
        <div class="ds-card">
            <div class="ds-card-header flex flex-wrap items-center gap-2">
                <h3 class="text-[14px] font-semibold text-fg-1">{{ $sync->original_name }}</h3>
                <span class="chip {{ $st[1] }} text-[11px]">{{ $st[0] }}</span>
                <span class="text-[12px] text-fg-3">загрузил {{ $sync->user?->name ?? '—' }} · {{ $sync->created_at->timezone('Europe/Moscow')->format('d.m.Y H:i') }}
                    @if($sync->applied_at) · применил {{ $sync->appliedBy?->name ?? '—' }} {{ $sync->applied_at->timezone('Europe/Moscow')->format('d.m H:i') }}@endif
                </span>
                @if($sync->status === \App\Models\CatalogManualSync::STATUS_PARSED && $this->canManage)
                    <span class="flex-1"></span>
                    <button type="button" wire:click="cancel" class="btn btn-sm" wire:confirm="Отменить эту загрузку? Каталог не изменится.">Отменить</button>
                    <button type="button" wire:click="apply" wire:loading.attr="disabled" wire:target="apply" class="btn btn-sm btn-primary"
                            wire:confirm="Применить: обновить цены и остатки у {{ $sync->rows_changed }} поз. и отметить цены актуальными?">
                        <span wire:loading.remove wire:target="apply">Применить ({{ $sync->rows_changed }})</span>
                        <span wire:loading wire:target="apply">Применяю…</span>
                    </button>
                @endif
            </div>
            <div class="ds-card-body space-y-3">
                @if($sharp > 0 && $sync->status === \App\Models\CatalogManualSync::STATUS_PARSED)
                    <div class="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-[12.5px] text-amber-900">⚠ У {{ $sharp }} поз. цена меняется больше чем на 30% — проверьте их перед «Применить» (отмечены в таблице).</div>
                @endif
                <div class="flex flex-wrap gap-2 text-[12px]">
                    @foreach(['all' => 'все '.$rows->count(), 'changed' => 'изменится '.($counts['changed'] ?? 0), 'same' => 'без изменений '.($counts['same'] ?? 0), 'unknown' => 'нет в каталоге '.($counts['unknown'] ?? 0), 'invalid' => 'ошибки '.($counts['invalid'] ?? 0)] as $k => $label)
                        <button type="button" wire:click="$set('rowFilter', '{{ $k }}')"
                                class="h-[26px] px-2.5 rounded-md border {{ $rowFilter === $k ? 'bg-[var(--accent)] text-fg-on-accent border-[var(--accent)]' : 'bg-surface text-fg-2 border-border hover:text-fg-1' }}">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px]">
                        <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                            <tr>
                                <th class="text-left px-2 py-1.5">Стр.</th>
                                <th class="text-left px-2 py-1.5">Артикул</th>
                                <th class="text-left px-2 py-1.5">Наименование</th>
                                <th class="text-right px-2 py-1.5">Цена продажи</th>
                                <th class="text-right px-2 py-1.5">Цена мин.</th>
                                <th class="text-right px-2 py-1.5">Закупка</th>
                                <th class="text-right px-2 py-1.5" title="На складе &lt;Свободно&gt;">Остаток</th>
                                <th class="text-left px-2 py-1.5">Дата цены</th>
                                <th class="text-left px-2 py-1.5"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($shown as $r)
                                @php
                                    $old = $r['old'] ?? null;
                                    $pct = $old && $old['price'] ? ($r['price'] - $old['price']) / $old['price'] * 100 : null;
                                    $cell = function ($was, $now) use ($money) {
                                        if ($now === null) {
                                            return '<span class="text-fg-4">'.$money($was).'</span>';
                                        }
                                        if ($was === null || abs($was - $now) < 0.005) {
                                            return e($money($now));
                                        }

                                        return '<span class="text-fg-4 line-through">'.e($money($was)).'</span><br><b>'.e($money($now)).'</b>';
                                    };
                                    $rl = $rowLabel[$r['status']] ?? [$r['status'], 'chip-neutral'];
                                @endphp
                                <tr class="border-b border-border-subtle align-top" wire:key="row-{{ $sync->id }}-{{ $r['line'] }}">
                                    <td class="px-2 py-1.5 text-fg-4 mono">{{ $r['line'] }}</td>
                                    <td class="px-2 py-1.5 mono whitespace-nowrap">
                                        @if($r['catalog_item_id'])<a href="{{ route('catalog.search', ['q' => $r['sku']]) }}" target="_blank" class="text-sky-700 hover:underline">{{ $r['sku'] }}</a>@else{{ $r['sku'] }}@endif
                                    </td>
                                    <td class="px-2 py-1.5 text-fg-2"><div class="max-w-[460px] truncate" title="{{ $r['name'] }}">{{ $r['name'] }}</div></td>
                                    <td class="px-2 py-1.5 text-right mono whitespace-nowrap">
                                        {!! $old ? $cell($old['price'], $r['price']) : e($money($r['price'])) !!}
                                        @if($pct !== null && abs($pct) >= 0.01)<div class="text-[10.5px] {{ $pct > 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ $pct > 0 ? '+' : '' }}{{ number_format($pct, 1, ',', ' ') }}%</div>@endif
                                        @if($pct !== null && abs($pct) >= 30)<div class="text-[10.5px] font-semibold text-amber-700" title="Цена меняется больше чем на 30% — проверьте, нет ли ошибки в выгрузке">⚠ резкое изменение</div>@endif
                                    </td>
                                    <td class="px-2 py-1.5 text-right mono whitespace-nowrap">{!! $old ? $cell($old['price_min'], $r['price_min']) : e($money($r['price_min'])) !!}</td>
                                    <td class="px-2 py-1.5 text-right mono whitespace-nowrap">{!! $old ? $cell($old['purchase_price'], $r['purchase_price']) : e($money($r['purchase_price'])) !!}</td>
                                    <td class="px-2 py-1.5 text-right mono whitespace-nowrap">
                                        @php $was = $old['stock'] ?? null; $now = $r['stock'] ?? null; @endphp
                                        @if($now === null){{ $was ?? '—' }}@elseif($old && $was !== $now)<span class="text-fg-4 line-through">{{ $was ?? '—' }}</span><br><b>{{ $now }}</b>@else{{ $now }}@endif
                                    </td>
                                    <td class="px-2 py-1.5 whitespace-nowrap text-fg-3">{{ $r['price_date'] ? \Illuminate\Support\Carbon::parse($r['price_date'])->format('d.m.Y') : '—' }}</td>
                                    <td class="px-2 py-1.5 whitespace-nowrap">
                                        <span class="chip {{ $rl[1] }} text-[10.5px]">{{ $rl[0] }}</span>
                                        @if($old && ! $old['actual'] && $r['status'] === 'changed')<span class="chip chip-ok text-[10.5px]">станет актуальной</span>@endif
                                        @if($r['error'])<div class="text-[11px] text-red-700">{{ $r['error'] }}</div>@endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-2 py-6 text-center text-fg-3">Строк с таким статусом нет.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- Журнал загрузок --}}
    @if($this->history->isNotEmpty())
        <div class="ds-card">
            <div class="ds-card-header"><h3 class="text-[14px] font-semibold text-fg-1">Загрузки</h3></div>
            <div class="ds-card-body overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                        <tr>
                            <th class="text-left px-2 py-1.5">Когда</th>
                            <th class="text-left px-2 py-1.5">Файл</th>
                            <th class="text-left px-2 py-1.5">Кто</th>
                            <th class="text-right px-2 py-1.5">Строк</th>
                            <th class="text-right px-2 py-1.5">Изменений</th>
                            <th class="text-right px-2 py-1.5">Нет в каталоге</th>
                            <th class="text-left px-2 py-1.5">Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->history as $h)
                            @php $hs = $statusLabel[$h->status] ?? [$h->status, 'chip-neutral']; @endphp
                            <tr class="border-b border-border-subtle hover:bg-hover cursor-pointer {{ $sync?->id === $h->id ? 'bg-surface-2' : '' }}" wire:click="open({{ $h->id }})" wire:key="h-{{ $h->id }}">
                                <td class="px-2 py-1.5 whitespace-nowrap text-fg-3">{{ $h->created_at->timezone('Europe/Moscow')->format('d.m.Y H:i') }}</td>
                                <td class="px-2 py-1.5 text-sky-700">{{ $h->original_name }}</td>
                                <td class="px-2 py-1.5">{{ $h->user?->name ?? '—' }}</td>
                                <td class="px-2 py-1.5 text-right mono">{{ $h->rows_total }}</td>
                                <td class="px-2 py-1.5 text-right mono">{{ $h->rows_changed }}</td>
                                <td class="px-2 py-1.5 text-right mono {{ $h->rows_unknown > 0 ? 'text-amber-700' : '' }}">{{ $h->rows_unknown }}</td>
                                <td class="px-2 py-1.5"><span class="chip {{ $hs[1] }} text-[10.5px]">{{ $hs[0] }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
