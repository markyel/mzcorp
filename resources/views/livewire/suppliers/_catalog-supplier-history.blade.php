{{--
    История поставщиков по позиции каталога: кого спрашивали и что ответили (рассылка и QuoteParts),
    плюс первая и последняя закупка из 1С. Данные — CatalogSupplierHistoryService.
    Блок стоит в узкой колонке карточки — одна строка на предложение: поставщик, дата, цена.
    Ожидает: $history — array{asked, onec, quoteparts, asked_suppliers, quoted_suppliers}|null
--}}
@php
    $h = ($history ?? []) + ['asked' => [], 'onec' => [], 'quoteparts' => [], 'asked_suppliers' => 0, 'quoted_suppliers' => 0];
    $money = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
@endphp
<div class="mt-3 text-[12px]">
    <div class="flex flex-wrap items-baseline gap-x-2 mb-1">
        <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">История поставщиков</span>
        @if($h['asked_suppliers'] > 0)
            <span class="text-[11.5px] text-fg-3">спрашивали {{ $h['asked_suppliers'] }}, цену дали {{ $h['quoted_suppliers'] }}</span>
        @endif
    </div>

    {{-- Закупки 1С --}}
    @foreach($h['onec'] as $p)
        <div class="flex items-baseline gap-2 py-0.5">
            <span class="flex-1 min-w-0 text-fg-1 break-words" title="{{ $p['supplier'] }}">
                <span class="chip {{ $p['kind'] === 'last' ? 'chip-ok' : 'chip-neutral' }} text-[10px]" title="Цена закупки из 1С">1С · {{ $p['kind'] === 'last' ? 'последняя' : 'первая' }}</span>
                {{ $p['supplier'] }}
            </span>
            <span class="text-fg-3 whitespace-nowrap">{{ $p['priced_at']?->format('d.m.Y') }}</span>
            <span class="mono tnum text-fg-1 whitespace-nowrap text-right">{{ $money($p['price']) }} {{ $p['currency'] }}</span>
        </div>
    @endforeach

    {{-- Через QuoteParts --}}
    @if($h['quoteparts'] !== [])
        <div class="text-[10px] uppercase tracking-wider text-fg-4 mt-1.5 mb-0.5">Через QuoteParts</div>
        @foreach($h['quoteparts'] as $q)
            <div @class(['flex items-baseline gap-2 py-0.5 border-t border-border-subtle', 'opacity-60' => ! $q['current']])>
                <span class="flex-1 min-w-0 text-fg-1 break-words">
                    {{ $q['supplier'] }}
                    @if($q['kind'] !== 'original')<span class="chip chip-warn text-[10px]" title="Не оригинал{{ $q['product'] !== '' ? ': '.$q['product'] : '' }}">{{ \App\Models\QuotePartsOffer::kindLabel($q['kind']) }}</span>@endif
                </span>
                <span class="text-fg-3 whitespace-nowrap">{{ $q['at']?->format('d.m.Y') }}</span>
                <span class="mono tnum font-semibold whitespace-nowrap text-right {{ $q['current'] ? 'text-emerald-700' : 'text-fg-2' }}">{{ $q['price'] !== null ? $money($q['price']).' '.$q['currency'] : '—' }}</span>
            </div>
        @endforeach
    @endif

    {{-- Рассылка из mzCorp --}}
    @if($h['asked'] !== [])
        @if($h['onec'] !== [] || $h['quoteparts'] !== [])<div class="text-[10px] uppercase tracking-wider text-fg-4 mt-1.5 mb-0.5">Рассылка</div>@endif
        @foreach($h['asked'] as $a)
            <div class="flex items-baseline gap-2 py-0.5 border-t border-border-subtle">
                <span class="flex-1 min-w-0 text-fg-1 break-words" title="{{ $a['email'] }}{{ $a['request_code'] ? ' · '.$a['request_code'] : '' }}">{{ $a['supplier'] }}</span>
                {{-- Дата предложения, если ответил; иначе — когда спросили. --}}
                <span class="text-fg-3 whitespace-nowrap" title="{{ $a['answered_at'] ? 'ответ; спросили '.$a['asked_at']->format('d.m.Y') : 'спросили' }}">{{ ($a['answered_at'] ?? $a['asked_at'])->format('d.m.Y') }}</span>
                <span class="whitespace-nowrap text-right">
                    @if($a['status'] === 'quoted')
                        <span class="mono tnum font-semibold text-emerald-700">{{ $a['price'] !== null ? $money($a['price']).' '.($a['currency'] ?: '₽') : 'цена' }}</span>
                    @elseif($a['status'] === 'refused')
                        <span class="text-amber-700">отказ</span>
                    @elseif($a['status'] === 'pending')
                        <span class="text-fg-4">ждём ответ</span>
                    @else
                        <span class="text-fg-4">{{ $a['status'] }}</span>
                    @endif
                </span>
            </div>
        @endforeach
    @endif

    @if($h['onec'] === [] && $h['quoteparts'] === [] && $h['asked'] === [])
        <p class="text-fg-3">По этой позиции поставщиков ещё не спрашивали, закупок в 1С нет.</p>
    @endif
</div>
