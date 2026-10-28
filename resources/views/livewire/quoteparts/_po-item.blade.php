{{-- Позиция «Актуализации цен» и её предложения (дизайн «Актуализация цен», 10.2026). Ожидает $it, $section:
     new — сбор цен завершён, пришло новое предложение; ready — готово к актуализации; wait — ждём ответа и отказы.
     Статус позиции — цвет шапки и полоса слева; строки предложений всегда белые. Справа в шапке — «+ В выгрузку»
     той же ширины, что колонка решений (не прыгает). --}}
@php
    $tz = config('app.timezone');
    $card = $it['cid'] ? ($this->openCards[$it['cid']] ?? null) : null;
    $last = $it['cid'] ? $this->lastPurchases->get($it['cid']) : null;
    $pos = $this->positions->get($it['sku']);
    ['chosen' => $chosen] = $this->stateOf($it);
    $live = $it['offers']->reject(fn ($r) => $r['stale'] || ($r['ignored'] ?? false));
    // «Лучшее» — самое дешёвое из оригиналов, когда их хотя бы два.
    $originals = $live->filter(fn ($r) => ($r['kind'] ?? 'original') === 'original' && $r['rub'] !== null);
    $best = $originals->count() > 1 ? $originals->sortBy('rub')->first()['id'] : null;
    $only = $live->count() === 1;
    $suppliers = $it['offers']->pluck('key')->filter()->unique()->count();
    $refusedAll = $it['offers']->isEmpty() && $it['declines']->isNotEmpty() && ($it['waiting'] ?? []) === [];
    $status = match (true) {
        $section === 'new' => 'new',
        $section === 'ready' => 'ready',
        $refusedAll => 'refused',
        default => 'wait',
    };
    $answered = $it['asked'] !== null
        ? 'ответили '.$it['offered'].' из '.$it['asked'].($it['declined'] ? ' · отказ '.$it['declined'] : '').($it['offers']->count() > $suppliers && $suppliers > 0 ? ' · предложений '.$it['offers']->count() : '')
        : 'без запроса · предложений '.$it['offers']->count();
    $rub = fn ($v) => number_format((float) $v, 0, ',', ' ');
@endphp
<div wire:key="poi-{{ $section }}-{{ $it['sku'] }}" class="pa-pos is-{{ $status }}">
    <div class="pa-head">
        <div class="pa-head-main">
            @if($it['cid'])
                <button type="button" wire:click="toggleItem({{ $it['cid'] }})" class="pa-sku" title="Карточка каталога">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="transition: transform 120ms; transform: rotate({{ $card ? 90 : 0 }}deg)"><path d="m9 18 6-6-6-6"/></svg>{{ $it['sku'] }}</button>
            @else
                <span class="pa-sku" style="cursor: default">{{ $it['sku'] ?: '—' }}</span>
            @endif
            @if($it['name'])<span class="pa-name">{{ $it['name'] }}</span>@endif
            <span class="pa-answered">{{ $answered }}</span>
            @if($it['requested_at'])<span class="pa-dim">запрос {{ $it['requested_at']->timezone($tz)->format('d.m H:i') }}</span>@endif
            @if($section === 'wait' && $it['wait_until'])<span style="color: var(--status-attn-fg); white-space: nowrap">· ждём до {{ $it['wait_until']->timezone($tz)->format('d.m H:i') }}</span>@endif
            @if($section === 'new')<span class="pa-pill pa-pill-new" style="font-size: 12px; padding: 1px 8px">новое предложение</span>@endif
            <span class="pa-last" title="Закупочная цена из каталога (1С) — с логистикой и таможней, с ценой поставщика напрямую не сравнивается">
                <span class="pa-dim">Последняя закупка</span>
                <b class="tnum">{{ $it['purchase'] !== null ? $rub($it['purchase']).' ₽' : '—' }}</b>
                <span class="pa-dim">@if($last)· {{ $last->supplier_name_1c }}{{ $last->priced_at ? ', '.$last->priced_at->format('d.m.Y') : '' }}@elseif($it['purchase'] === null)закупок не было@endif</span>
            </span>
        </div>
        <div class="pa-head-side">
            @if($section !== 'wait' && $it['cid'])
                @if($pos?->inExportOf(auth()->id()))
                    <span class="pa-export is-in">
                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>{{ $pos->exported_at ? 'выгружено '.$pos->exported_at->timezone($tz)->format('d.m H:i') : 'в выгрузке 1С' }}</span>
                    <button type="button" wire:click="removeFromExport('{{ $it['sku'] }}')" class="pa-btn pa-btn-link" style="align-self: flex-end; height: 20px">убрать</button>
                @else
                    {{-- В списке выгрузки коллеги: выгрузит он; «+ В выгрузку» перенесёт позицию в мой список. --}}
                    @if($pos?->in_export_at)
                        <span class="pa-dim" style="font-size: 12px; align-self: flex-end">в выгрузке у {{ $pos->inExportBy?->name ?? 'коллеги' }}</span>
                    @endif
                    <button type="button" @if($chosen) wire:click="addToExport('{{ $it['sku'] }}')" wire:loading.attr="disabled" @else disabled @endif class="pa-export"
                            title="{{ ! $chosen ? 'Сначала выберите предложение — «Закупаем здесь»' : ($pos?->in_export_at ? 'Перенести в мою выгрузку 1С — из списка '.($pos->inExportBy?->name ?? 'коллеги') : 'Добавить в мою выгрузку 1С: все цены позиции, выбранная — «закупаем здесь»') }}">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>В выгрузку</button>
                @endif
            @endif
        </div>
    </div>

    @if($section === 'new' && $pos?->closed_at)
        @php $co = $pos->closed_offer ?? []; @endphp
        <div class="pa-reopen">Работа завершена {{ $pos->closed_at->timezone($tz)->format('d.m H:i') }}{{ ($co['supplier'] ?? null) ? ', в 1С выгружено: '.$co['supplier'].(isset($co['price']) ? ' · '.number_format((float) $co['price'], 2, ',', ' ').' '.($co['currency'] ?? '') : '') : '' }}. Пришло новое предложение — решите, менять ли поставщика, и выгрузите снова.</div>
    @endif

    @if($card)
        <div class="pa-card">
            @include('livewire.catalog._catalog-item-detail', ['cat' => $card['cat'], 'pc' => $card['pc'], 'iqp' => null, 'canIqot' => false, 'allowIqotAnalyze' => false, 'supplyHistoryOpen' => true])
        </div>
    @endif

    @foreach($it['offers'] as $r)
        @include('livewire.quoteparts._po-offer', ['r' => $r, 'it' => $it, 'chosen' => $chosen, 'best' => $best, 'only' => $only, 'pos' => $pos, 'reopened' => $section === 'new'])
    @endforeach

    {{-- Отказы: цены не дал — с причиной; переписка по запросу. --}}
    @foreach($it['declines'] as $d)
        <div wire:key="po-{{ $d['id'] }}" class="pa-offer {{ $declineDialog === $d['id'] ? 'is-open' : '' }}">
            <div class="pa-grid pa-row">
                <div class="pa-col">
                    <div class="pa-who"><span class="pa-sup">{{ $d['supplier'] }}</span><span class="pa-pill pa-pill-over">отказ</span></div>
                    <span class="pa-meta">{{ $d['at']?->timezone($tz)->format('d.m H:i') }} · отказ</span>
                    <button type="button" wire:click="openDeclineDialog('{{ $d['id'] }}')" class="pa-link">{{ $declineDialog === $d['id'] ? '▾' : '▸' }} переписка</button>
                </div>
                <div class="pa-col pa-price"><span class="pa-meta" style="font-size: 13px">цену не дал</span></div>
                <div class="pa-col"></div>
                <div class="pa-col">@if($d['reason'])<span class="pa-note">Поставщик: «{{ $d['reason'] }}»</span>@endif</div>
                <div class="pa-col pa-decide">
                    <button type="button" wire:click="openDeclineDialog('{{ $d['id'] }}')" class="pa-btn pa-btn-secondary {{ $declineDialog === $d['id'] ? 'is-on' : '' }}" title="Переспросить или уточнить — ответ уйдёт в ветку запроса">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>Написать</button>
                </div>
            </div>
            @if($declineDialog === $d['id'])
                <div class="pa-thread">
                    @if($rfq = $this->declineRfq)
                        @include('livewire.quote-parts._rfq-dialog', ['rfq' => $rfq, 'supplierNames' => app(\App\Services\QuoteParts\QuotePartsRfqService::class)->supplierNames([$rfq]), 'refreshAction' => 'refreshQuotePartsRfq', 'onlySupplier' => $d['key']])
                    @else
                        <div class="pa-meta">Запрос не найден.</div>
                    @endif
                </div>
            @endif
        </div>
    @endforeach

    {{-- Спросили — ни цены, ни отказа. --}}
    @foreach(($it['waiting'] ?? []) as $w)
        <div wire:key="po-w-{{ $it['sku'] }}-{{ $loop->index }}" class="pa-offer">
            <div class="pa-grid pa-row">
                <div class="pa-col">
                    <div class="pa-who"><span class="pa-sup">{{ $w }}</span><span class="pa-pill pa-pill-attn">ждём ответ</span></div>
                    @if($it['requested_at'])<span class="pa-meta">запрос {{ $it['requested_at']->timezone($tz)->format('d.m H:i') }}</span>@endif
                </div>
                <div class="pa-col pa-price"><span class="pa-meta" style="font-size: 13px">—</span></div>
                <div class="pa-col"></div><div class="pa-col"></div><div class="pa-col"></div>
            </div>
        </div>
    @endforeach
</div>
