<div class="space-y-4">
    @php
        $fmtQty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ' '), '0'), '.');
        $rows = $this->rows;
    @endphp

    {{-- Header + фильтры --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Топ позиций · продажи и потери</h3>
            <span class="text-[12px] text-fg-3 ml-2">по закрытым заявкам · период: {{ $this->periodLabel }}</span>
            <span class="flex-1"></span>
            <a href="{{ route('analytics.index') }}" wire:navigate class="text-[12px] text-sky-700 hover:underline">← Аналитика</a>
        </div>
        <div class="px-4 pb-3 flex items-center gap-2 gap-y-2 flex-wrap text-[12px]">
            {{-- Период --}}
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
                @foreach(['30' => '30 дн.', '90' => '90 дн.', '365' => 'Год', 'all' => 'Всё время'] as $k => $label)
                    @php $on = $period === $k; @endphp
                    <button type="button" wire:click="setPeriod('{{ $k }}')"
                            class="h-[26px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <span class="text-fg-4">|</span>

            {{-- Сортировка --}}
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
                @foreach(['won' => '🏆 По продажам', 'lost' => '✕ По потерям'] as $k => $label)
                    @php $on = $sort === $k; @endphp
                    <button type="button" wire:click="setSort('{{ $k }}')"
                            class="h-[26px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <span class="flex-1"></span>
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Поиск: название / M-SKU / артикул"
                   class="h-[30px] px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500 w-[280px]">
        </div>
    </div>

    {{-- Таблица --}}
    <div class="ds-card">
        <div class="ds-card-body overflow-x-auto">
            @if($rows->isEmpty())
                <div class="text-center text-fg-3 py-8 text-[13px]">Нет закрытых заявок с позициями за период.</div>
            @else
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                        <tr>
                            <th class="px-2 py-2 text-center w-10">#</th>
                            <th class="px-2 py-2 text-left">Позиция</th>
                            <th class="px-2 py-2 text-right text-emerald-700">Продано, ед.</th>
                            <th class="px-2 py-2 text-right">Сделок (успех)</th>
                            <th class="px-2 py-2 text-right text-red-700">Проиграно, ед.</th>
                            <th class="px-2 py-2 text-right">Сделок (потеря)</th>
                        </tr>
                    </thead>
                    @php $rank = ($rows->currentPage() - 1) * $rows->perPage(); @endphp
                    @foreach($rows as $r)
                        @php
                            $rank++;
                            $iqp = $this->iqotByCatalogId->get($r->catalog_item_id);
                            $hasIqot = $iqp && $iqp->offers() !== [];
                        @endphp
                        <tbody wire:key="pos-{{ $r->catalog_item_id }}" x-data="{ open: false }">
                            <tr class="border-b border-border-subtle hover:bg-hover">
                                <td class="px-2 py-1.5 text-center mono text-fg-3 align-top">{{ $rank }}</td>
                                <td class="px-2 py-1.5">
                                    <div class="text-fg-1">{{ \Illuminate\Support\Str::limit($r->name ?? '—', 70) }}</div>
                                    <div class="mono text-[11px] text-fg-3">{{ $r->sku ?? '—' }}</div>
                                    @if($hasIqot)
                                        <button type="button" @click="open = !open"
                                                class="mt-1 inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-[10.5px] font-medium hover:bg-emerald-100">
                                            IQOT@if($iqp->report_min_price !== null): мин. {{ number_format((float) $iqp->report_min_price, 0, ',', ' ') }} ₽@endif · {{ $iqp->report_offers_count ?? count($iqp->offers()) }} офф. <span x-text="open ? '▾' : '▸'"></span>
                                        </button>
                                    @endif
                                </td>
                                <td class="px-2 py-1.5 text-right mono align-top {{ (float) $r->won_units > 0 ? 'text-emerald-700 font-semibold' : 'text-fg-4' }}">{{ (float) $r->won_units > 0 ? $fmtQty($r->won_units) : '—' }}</td>
                                <td class="px-2 py-1.5 text-right mono text-fg-2 align-top">{{ (int) $r->won_deals ?: '—' }}</td>
                                <td class="px-2 py-1.5 text-right mono align-top {{ (float) $r->lost_units > 0 ? 'text-red-700 font-semibold' : 'text-fg-4' }}">{{ (float) $r->lost_units > 0 ? $fmtQty($r->lost_units) : '—' }}</td>
                                <td class="px-2 py-1.5 text-right mono text-fg-2 align-top">{{ (int) $r->lost_deals ?: '—' }}</td>
                            </tr>
                            @if($hasIqot)
                                <tr x-show="open" x-cloak class="border-b border-border-subtle bg-surface-2">
                                    <td colspan="6" class="px-4 py-2.5">
                                        @include('livewire.iqot._comparison', ['pos' => $iqp])
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    @endforeach
                </table>
                <div class="mt-3">{{ $rows->links() }}</div>
            @endif
        </div>
    </div>
</div>
