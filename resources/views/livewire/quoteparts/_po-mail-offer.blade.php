{{-- Цена из письма мимо QuoteParts в позиции «Актуализации цен» (MailPriceOffers, 12.11.2026): запрос ушёл обычным
     письмом, ответ разобрал старый разборщик. Ожидает $m (строка письма), $it (позиция), $chosen (выбор позиции:
     «m{id}» — эта цена), $pos. Уточнить и «Игнорировать» — только у предложений QuoteParts; переписка — в заявке. --}}
@php
    $tz = config('app.timezone');
    $isChosen = $chosen === 'm'.$m['id'];
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
@endphp
<div wire:key="po-mail-{{ $m['id'] }}" class="pa-offer">
    <div class="pa-grid pa-row">
        <div class="pa-col">
            <div class="pa-who"><span class="pa-sup">{{ $m['supplier'] }}</span><span class="pa-pill pa-pill-neutral" title="Запрос ушёл обычным письмом, не через QuoteParts">письмом</span></div>
            <span class="pa-meta">{{ $m['at']?->timezone($tz)->format('d.m H:i') }} · {{ $m['email'] }}</span>
            @if($m['request_id'])
                <a href="{{ route('requests.show', ['request' => $m['request_id'], 'tab' => 'suppliers']) }}" target="_blank" rel="noopener" class="pa-link"
                   title="Переписка с поставщиком — во вкладке «Поставщики» заявки">▸ переписка · {{ $m['request_code'] }}</a>
            @endif
        </div>
        <div class="pa-col pa-price">
            <span class="pa-kind {{ $m['deviation'] ? 'is-dev' : 'is-orig' }}" title="{{ $m['deviation'] ? 'В письме — аналог / копия / совместимое' : '' }}">{{ $m['deviation'] ? 'не оригинал?' : 'цена' }}</span>
            <span class="pa-amount">{{ $money($m['price']) }} {{ $m['currency'] }}</span>
            @if($m['rub'] !== null)<span class="pa-meta">≈ {{ number_format($m['rub'], 0, ',', ' ') }} ₽</span>@endif
        </div>
        <div class="pa-col pa-terms">
            @if($m['valid'])<span>{{ $m['valid'] }}</span>@else<span style="color: var(--fg-4)">—</span>@endif
        </div>
        <div class="pa-col" style="gap: 6px">
            @if($m['quote'])<span class="pa-note">Из письма: «{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', (string) $m['quote'])), 300) }}»</span>@endif
        </div>
        <div class="pa-col pa-decide">
            @if($isChosen)
                <span class="pa-state is-ok"><span class="dot"></span>{{ $pos?->in_export_at && $pos?->exported_at ? 'закупаем · выгружено' : 'закупаем' }}</span>
                <button type="button" wire:click="clearChoice({{ $m['cid'] }})" class="pa-btn pa-btn-link" title="Снять выбор поставщика">вернуть</button>
            @elseif($m['key'])
                <button type="button" wire:click="chooseMail('{{ $m['sku'] }}', {{ $m['id'] }})" class="pa-btn pa-btn-secondary"
                        title="Закупаем у этого поставщика — идёт в выгрузку 1С как выбранный">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>Закупаем здесь</button>
            @endif
        </div>
    </div>
</div>
