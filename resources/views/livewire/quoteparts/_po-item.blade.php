{{-- Позиция и все предложения на неё (вкладка «Актуализация цен», вид «по позициям»). Ожидает $it, $ready.
     Шапка — одной строкой: позиция, сколько ответили, запрос, текущая закупка и поставщик; ниже — предложения. --}}
@php
    $money = fn ($v, $d = 0) => $v === null ? '—' : number_format((float) $v, $d, ',', ' ');
    $card = $it['cid'] ? ($this->openCards[$it['cid']] ?? null) : null;
    $last = $it['cid'] ? $this->lastPurchases->get($it['cid']) : null;
    $choice = $it['cid'] ? $this->choices->get($it['cid']) : null;
    $tz = config('app.timezone');
@endphp
<div wire:key="poi-{{ $ready ? 'r' : 'w' }}-{{ $it['sku'] }}" class="border-t border-border-subtle">
    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 px-4 py-2 text-[12.5px] {{ $ready ? 'bg-emerald-50' : 'bg-surface-2' }}">
        @if($it['cid'])
            <button type="button" wire:click="toggleItem({{ $it['cid'] }})" class="mono font-semibold text-sky-700 hover:underline" title="Карточка позиции">{{ $it['sku'] }} <span class="text-fg-4 text-[10.5px] font-normal">{{ $card ? '▾' : '▸' }}</span></button>
        @else
            <span class="mono font-semibold">{{ $it['sku'] ?: '—' }}</span>
        @endif
        @if($it['name'])<span class="text-fg-1 truncate max-w-[520px]" title="{{ $it['name'] }}">{{ $it['name'] }}</span>@endif
        @if($it['asked'] !== null)
            <span class="text-fg-4">·</span>
            <span class="{{ $it['offered'] * 2 > $it['asked'] ? 'text-emerald-700 font-semibold' : 'text-fg-1' }}">ответили {{ $it['offered'] }} из {{ $it['asked'] }}</span>
            @if($it['declined'])<span class="text-fg-3">· отказ {{ $it['declined'] }}</span>@endif
            <span class="text-fg-3">· запрос {{ $it['requested_at']->timezone($tz)->format('d.m H:i') }}</span>
            @if(! $ready && $it['wait_until'])<span class="text-fg-4">· ждём до {{ $it['wait_until']->timezone($tz)->format('d.m H:i') }}</span>@endif
            @if($it['rfqs'])<span class="mono text-[11px] text-fg-4">{{ implode(' ', $it['rfqs']) }}</span>@endif
        @else
            <span class="text-fg-4">· без запроса: кабинет, прайс</span>
        @endif
        <span class="text-fg-4">·</span>
        <span class="text-fg-3" title="Закупочная цена из каталога (1С) — с логистикой и таможней">закупка <span class="mono text-fg-1">{{ $it['purchase'] !== null ? $money($it['purchase']).' ₽' : '—' }}</span>@if($last)<span> · {{ $last->supplier_name_1c }}{{ $last->priced_at ? ', '.$last->priced_at->format('d.m.Y') : '' }}</span>@endif</span>
        @if($choice)
            <span class="text-fg-4">·</span>
            <span class="text-sky-700" title="Выбор снабжения: у кого закупаем">закупаем у: <b>{{ $choice->supplier_name }}</b>{{ $choice->price !== null ? ' · '.number_format((float) $choice->price, 2, ',', ' ').' '.$choice->currency : '' }}</span>
        @endif
    </div>
    @if($card)
        <div class="px-4 py-2.5 bg-surface-2 border-t border-border-subtle">
            @include('livewire.catalog._catalog-item-detail', ['cat' => $card['cat'], 'pc' => $card['pc'], 'iqp' => null, 'canIqot' => false, 'allowIqotAnalyze' => false, 'supplyHistoryOpen' => true])
        </div>
    @endif
    @include('livewire.quoteparts._po-table', ['rows' => $it['offers'], 'showRequest' => true, 'showItem' => false])
</div>
