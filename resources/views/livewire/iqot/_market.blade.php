{{-- Цены рынка по позиции из IQOT: КП по заявкам + мониторинг цен «Гибрид» (сайты и КП по почте),
     одним списком, БЕЗ НДС, с нашей ценой на своём месте (App\Services\Iqot\IqotMarket).
     Ожидает $market; необязательно $mon (IqotMonitoringItem) и $pos (IqotPosition) — для статусов. --}}
@php
    $rub = fn ($v) => $v !== null ? number_format((float) $v, 0, ',', ' ').' ₽' : '—';
    $kindLabel = [\App\Services\Iqot\IqotMarket::KIND_REQUEST => 'КП · заявка', \App\Services\Iqot\IqotMarket::KIND_SITE => 'сайт', \App\Services\Iqot\IqotMarket::KIND_MAIL_KP => 'КП · почта'];
    $kindClass = [\App\Services\Iqot\IqotMarket::KIND_REQUEST => 'chip-ok', \App\Services\Iqot\IqotMarket::KIND_SITE => 'chip-neutral', \App\Services\Iqot\IqotMarket::KIND_MAIL_KP => 'chip-ok'];
    $avail = ['in_stock' => 'в наличии', 'to_order' => 'под заказ', 'out_of_stock' => 'нет в наличии'];
    $mon = $mon ?? null;
    $pos = $pos ?? null;
    $ourShown = false;
    $n = 0;
@endphp
<div class="rounded-md border border-border-subtle bg-surface px-3 py-2">
    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-[12px]">
        <span class="font-semibold text-fg-1">IQOT · цены рынка</span>
        @if($market['offers'] > 0)
            <span>лучшая без НДС <b class="mono">{{ $rub($market['best_net']) }}</b></span>
            <span class="text-fg-3">медиана {{ $rub($market['median_net']) }} · предложений {{ $market['offers'] }}
                <span class="text-fg-4">({{ $market['from_requests'] > 0 ? 'КП по заявке '.$market['from_requests'] : '' }}{{ $market['from_requests'] > 0 && $market['from_monitoring'] > 0 ? ', ' : '' }}{{ $market['from_monitoring'] > 0 ? 'мониторинг '.$market['from_monitoring'] : '' }})</span></span>
            @if($market['our_rank'])
                <span>мы — <b>{{ $market['our_rank'] }}-е место</b> из {{ $market['total'] }}
                    @if($market['delta'] !== null)<b class="{{ $market['delta'] > 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ $market['delta'] > 0 ? '+' : '' }}{{ number_format($market['delta'], 0, ',', ' ') }} ₽ ({{ $market['delta'] > 0 ? '+' : '' }}{{ number_format($market['delta_pct'], 1, ',', ' ') }}%)</b> к лучшей@endif
                </span>
            @endif
        @else
            <span class="text-fg-3">{{ $market['rows'] !== [] ? 'фактических цен нет — только сомнительные' : 'цен пока нет' }}</span>
        @endif
        <span class="text-fg-4">
            @if($mon){{ ! $mon->active ? 'мониторинг снят' : 'мониторинг: '.(\App\Models\IqotMonitoringItem::MATCH_LABELS[$mon->match_status] ?? 'отправлена') }}{{ $mon->prices_synced_at ? ', '.$mon->prices_synced_at->format('d.m H:i') : '' }}@endif
            @if($mon && $pos) · @endif
            @if($pos)заявка: {{ $pos->statusEnum()?->label() ?? $pos->status }}{{ $pos->analyzed_at ? ', '.$pos->analyzed_at->format('d.m.Y') : '' }}@endif
            @if($mon && ! empty($mon->payload['article'])) · OEM {{ $mon->payload['article'] }}@endif
        </span>
    </div>
    @if($mon && ($mon->match_status === 'ambiguous' || $mon->match_status === 'rejected' || $mon->import_error))
        <div class="mt-1 text-[11.5px] text-amber-800">{{ $mon->import_error ? 'Ошибка загрузки: '.$mon->import_error : '' }}{{ $mon->match_note ? ' '.$mon->match_note : '' }}{{ $mon->match_status === 'ambiguous' ? ' Уточните название или бренд в каталоге.' : '' }}</div>
    @endif
    @if($market['rows'] !== [])
        <div class="overflow-x-auto">
        <table class="mt-1.5 w-full text-[12px]">
            <thead class="text-fg-3 text-[10px] uppercase tracking-wider">
                <tr>
                    <th class="py-1 pr-2 text-left w-6">#</th>
                    <th class="py-1 pr-2 text-left">Поставщик</th>
                    <th class="py-1 pr-2 text-left">Откуда</th>
                    <th class="py-1 pr-2 text-right">Цена</th>
                    <th class="py-1 pr-2 text-right">Без НДС</th>
                    <th class="py-1 pr-2 text-left">Наличие / срок</th>
                    <th class="py-1 text-right">Дата</th>
                </tr>
            </thead>
            <tbody>
                @foreach($market['rows'] as $r)
                    @php $doubtful = ! empty($r['doubtful']); @endphp
                    @if(! $doubtful && ! $ourShown && $market['our_net'] !== null && $market['our_net'] <= $r['net'])
                        @php $ourShown = true; $n++; @endphp
                        @include('livewire.iqot._market-our-row', ['n' => $n, 'market' => $market, 'rub' => $rub])
                    @endif
                    @php if (! $doubtful) { $n++; } @endphp
                    <tr class="border-t border-border-subtle align-top" @if($doubtful) style="opacity: .6" title="IQOT понизил доверие к этому сайту: его цену поймали на расхождении. В лучшую цену, медиану и наше место не входит." @endif>
                        <td class="py-1 pr-2 text-fg-4">{{ $doubtful ? '—' : $n }}</td>
                        <td class="py-1 pr-2">
                            @if($r['url'])<a href="{{ $r['url'] }}" target="_blank" rel="noopener noreferrer" class="text-sky-700 hover:underline" @if($r['kind'] === \App\Services\Iqot\IqotMarket::KIND_REQUEST) title="Сайт поставщика по домену его почты" @endif>{{ $r['supplier'] }}</a>@else<span class="text-fg-1">{{ $r['supplier'] }}</span>@endif
                            @if($r['kind'] === \App\Services\Iqot\IqotMarket::KIND_REQUEST && $r['url'])<span class="text-fg-4 text-[11px]"> · {{ \App\Services\Iqot\IqotMarket::readableDomain((string) parse_url($r['url'], PHP_URL_HOST)) }}</span>@endif
                            @if($r['pages'] > 1)<span class="text-fg-4 text-[11px]" title="У поставщика несколько страниц с этим товаром — показана лучшая цена"> · {{ $r['pages'] }} стр.</span>@endif
                            @if($r['contacts'])<div class="text-[11px] text-fg-3 mono">{{ $r['contacts'] }}</div>@endif
                        </td>
                        <td class="py-1 pr-2 whitespace-nowrap">
                            <span class="chip {{ $kindClass[$r['kind']] }} text-[10px]">{{ $kindLabel[$r['kind']] }}</span>
                            @if($r['confirmed'])<span class="chip chip-sky text-[10px]" title="Поставщик подтвердил цену сайта по почте">подтверждена</span>@endif
                            @if(! empty($r['mail_kp']))<span class="chip chip-ok text-[10px]" title="Поставщик прислал КП по почте">КП {{ $rub($r['mail_kp']) }}</span>@endif
                            @if(! empty($r['web_old']))<span class="chip chip-neutral text-[10px]" title="Цену сайта опровергло присланное КП — она справочная, в расчёт идёт цена КП">сайт <s>{{ $rub($r['web_old']) }}</s></span>@endif
                            @if(! empty($r['doubtful']))<span class="chip chip-attn text-[10px]">сомнительная цена</span>@endif
                        </td>
                        <td class="py-1 pr-2 whitespace-nowrap mono text-right">
                            {{ number_format((float) $r['raw'], 0, ',', ' ') }} {{ $r['symbol'] }}
                            <div class="text-[10px] {{ $r['vat'] === 'НДС не указан' ? 'text-amber-700' : 'text-fg-4' }}" @if($r['vat'] === 'НДС не указан') title="Поставщик не указал НДС — считаем, что цена с НДС" @endif>{{ $r['vat'] }}</div>
                        </td>
                        <td class="py-1 pr-2 whitespace-nowrap mono text-right font-semibold">{{ $rub($r['net']) }}</td>
                        <td class="py-1 pr-2 text-fg-3 whitespace-nowrap">{{ $avail[$r['availability'] ?? ''] ?? '' }}{{ $r['delivery_days'] !== null ? $r['delivery_days'].' дн.' : '' }}</td>
                        <td class="py-1 text-fg-4 whitespace-nowrap text-right">{{ $r['date']?->format('d.m.Y') }}</td>
                    </tr>
                @endforeach
                @if(! $ourShown && $market['our_net'] !== null)
                    @include('livewire.iqot._market-our-row', ['n' => $n + 1, 'market' => $market, 'rub' => $rub])
                @endif
            </tbody>
        </table>
        </div>
    @endif
</div>
