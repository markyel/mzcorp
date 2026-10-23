{{-- Нижняя панель вкладки «Поставщики» (как в «Актуализации цен»): сколько позиций с выбранной ценой и выгрузка в 1С.
     Стоит в конце вкладки, чтобы прилипать к низу при прокрутке всей вкладки. --}}
@php
    $ov = $this->overview;
    $cmp = $ov->comparison();
    $canPick = $this->canDispatch;
    $rubf = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $plural = fn (int $n, string $one, string $few, string $many) => $n % 10 === 1 && $n % 100 !== 11 ? $one : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) ? $few : $many);
@endphp
@if($cmp !== [] && $canPick)
    @php $bar = $ov->bar(); @endphp
    <div style="position: sticky; bottom: 0; z-index: 10; display: flex; align-items: center; gap: 16px; flex-wrap: wrap; padding: 10px 16px; background: var(--bg-surface); border: 1px solid var(--border); border-radius: 8px 8px 0 0; box-shadow: 0 -4px 12px -6px rgba(15,18,23,0.08)">
        <div style="display: flex; flex-direction: column; gap: 6px; min-width: 220px">
            <span style="font-size: 13px"><b class="tnum">Выбрано {{ $bar['chosen'] }} из {{ $bar['total'] }}</b> <span class="rs-hint" style="font-size: 13px">· {{ $plural($bar['total'], 'позиция', 'позиции', 'позиций') }}@if($bar['chosen'] > 0) на ≈ {{ $rubf($bar['sum']) }} ₽@endif</span></span>
            <div style="height: 4px; border-radius: 999px; background: var(--bg-surface-2); overflow: hidden; width: 280px; max-width: 100%"><div style="height: 100%; width: {{ $bar['total'] > 0 ? round($bar['chosen'] / $bar['total'] * 100) : 0 }}%; background: var(--emerald-600); transition: width 180ms"></div></div>
        </div>
        <div style="flex: 1"></div>
        @if($exportNote)
            <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 500; color: var(--status-ok-fg)">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>{{ $exportNote }}</span>
        @elseif($bar['chosen'] > $bar['exportable'])
            <span class="rs-hint">позиции без M-артикула в 1С не выгружаются</span>
        @endif
        <button type="button" wire:click="exportPrices" wire:loading.attr="disabled" wire:target="exportPrices" @disabled($bar['exportable'] === 0)
                class="rs-btn" style="height: 32px; padding: 0 14px; font-size: 13px; font-weight: 600"
                title="{{ $bar['exportable'] ? 'Excel для импорта в 1С: все цены поставщиков по позициям с выбранной ценой, выбранная — 1 в колонке «Выбор»' : 'Сначала выберите цену хотя бы в одной позиции' }}">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
            <span wire:loading.remove wire:target="exportPrices">Выгрузить в 1С{{ $bar['exportable'] ? ' · '.$bar['exportable'] : '' }}</span>
            <span wire:loading wire:target="exportPrices">Формирую…</span>
        </button>
    </div>
@endif
