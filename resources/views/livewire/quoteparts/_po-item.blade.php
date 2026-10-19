{{-- Позиция и все предложения на неё (вкладка «Актуализация цен», вид «по позициям»). Ожидает $it, $ready. --}}
@php
    $money = fn ($v, $d = 0) => $v === null ? '—' : number_format((float) $v, $d, ',', ' ');
    $card = $it['cid'] ? ($this->openCards[$it['cid']] ?? null) : null;
    $best = $it['best'];
    $bestDelta = $best && $it['purchase'] ? ($best['rub'] - $it['purchase']) / $it['purchase'] * 100 : null;
@endphp
<div wire:key="poi-{{ $ready ? 'r' : 'w' }}-{{ $it['sku'] }}" class="border-t border-border-subtle first:border-t-0">
    <div class="flex flex-wrap items-start gap-x-6 gap-y-1 px-4 py-2.5 {{ $ready ? 'bg-emerald-50/50' : '' }}">
        <div class="min-w-[260px] flex-1">
            @if($it['cid'])
                <button type="button" wire:click="toggleItem({{ $it['cid'] }})" class="text-left group" title="Карточка позиции">
                    <span class="mono font-semibold text-sky-700 group-hover:underline">{{ $it['sku'] }}</span>
                    <span class="text-fg-4 text-[11px]">{{ $card ? '▾ свернуть' : '▸ карточка' }}</span>
                </button>
            @else
                <span class="mono font-semibold">{{ $it['sku'] ?: '—' }}</span>
            @endif
            @if($it['name'])<div class="text-[12.5px] text-fg-2">{{ $it['name'] }}</div>@endif
        </div>
        <div class="text-[12px] text-fg-3 whitespace-nowrap">
            @if($it['asked'] !== null)
                <div><span class="{{ $it['offered'] * 2 > $it['asked'] ? 'text-emerald-700 font-semibold' : 'text-fg-1' }}">ответили {{ $it['offered'] }} из {{ $it['asked'] }}</span>@if($it['declined'])<span class="text-fg-4"> · отказ {{ $it['declined'] }}</span>@endif</div>
                <div class="text-[11px]">запрос {{ $it['requested_at']->timezone(config('app.timezone'))->format('d.m H:i') }}
                    @if(! $ready && $it['wait_until'])· ждём до {{ $it['wait_until']->timezone(config('app.timezone'))->format('d.m H:i') }}@endif
                </div>
                @if($it['rfqs'])<div class="mono text-[10.5px] text-fg-4">{{ implode(' ', $it['rfqs']) }}</div>@endif
            @else
                <div class="text-fg-4">без запроса: кабинет, прайс</div>
            @endif
        </div>
        <div class="text-right text-[12px] whitespace-nowrap">
            <div class="text-fg-3">лучшее</div>
            <div class="mono font-semibold text-fg-1">{{ $best ? $money($best['rub']).' ₽' : '—' }}</div>
            @if($bestDelta !== null)<div class="text-[11px] {{ $bestDelta > 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ $bestDelta > 0 ? '+' : '' }}{{ number_format($bestDelta, 0, ',', ' ') }}% к закупке</div>@endif
        </div>
        <div class="text-right text-[12px] whitespace-nowrap">
            <div class="text-fg-3">закупка · продажа</div>
            <div class="mono text-fg-2">{{ $it['purchase'] !== null ? $money($it['purchase']).' ₽' : '—' }} · {{ $it['sale'] !== null ? $money($it['sale']).' ₽' : '—' }}</div>
            <div class="text-[11px] {{ $it['actual'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $it['actual'] ? 'цена актуальна' : 'к актуализации' }}</div>
        </div>
    </div>
    @if($card)
        <div class="px-4 py-2.5 bg-surface-2 border-t border-border-subtle">
            @include('livewire.catalog._catalog-item-detail', ['cat' => $card['cat'], 'pc' => $card['pc'], 'iqp' => null, 'canIqot' => false, 'allowIqotAnalyze' => false, 'supplyHistoryOpen' => true])
        </div>
    @endif
    <div class="pl-4">
        @include('livewire.quoteparts._po-table', ['rows' => $it['offers'], 'showRequest' => true, 'showItem' => false])
    </div>
</div>
