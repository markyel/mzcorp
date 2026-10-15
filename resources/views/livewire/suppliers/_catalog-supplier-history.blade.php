{{--
    История поставщиков по позиции каталога: кого мы спрашивали и что ответили,
    плюс первая и последняя закупка из 1С. Данные — CatalogSupplierHistoryService.
    Ожидает: $history — array{asked, onec, asked_suppliers, quoted_suppliers}|null
--}}
@php
    $h = ($history ?? []) + ['asked' => [], 'onec' => [], 'quoteparts' => [], 'asked_suppliers' => 0, 'quoted_suppliers' => 0];
    $money = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    $groupsChips = fn (array $g) => implode('', array_map(fn ($n) => '<span class="chip chip-neutral text-[10px] ml-1">'.e($n).'</span>', $g));
@endphp
<div class="mt-3">
    <div class="flex items-baseline gap-2 mb-1.5">
        <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">История поставщиков</span>
        @if($h['asked_suppliers'] > 0)
            <span class="text-[11.5px] text-fg-3">спрашивали {{ $h['asked_suppliers'] }}, цену дали {{ $h['quoted_suppliers'] }}</span>
        @endif
    </div>

    @if($h['onec'] !== [])
        <div class="mb-2 space-y-0.5">
            @foreach($h['onec'] as $p)
                <div class="flex flex-wrap items-baseline gap-x-2 text-[12px]">
                    <span class="chip {{ $p['kind'] === 'last' ? 'chip-ok' : 'chip-neutral' }} text-[10px]" title="Цена закупки из 1С">1С · {{ $p['kind'] === 'last' ? 'последняя закупка' : 'первая закупка' }}</span>
                    <span class="mono tnum text-fg-1">{{ $money($p['price']) }} {{ $p['currency'] }}</span>
                    <span class="text-fg-3">{{ $p['priced_at']?->format('d.m.Y') }}</span>
                    <span class="text-fg-2" title="{{ $p['supplier'] }}">{{ \Illuminate\Support\Str::limit($p['supplier'], 90) }}</span>{!! $groupsChips($p['groups']) !!}
                </div>
            @endforeach
        </div>
    @endif

    {{-- Предложения через QuoteParts: все варианты (оригинал, аналог…) и прежние цены. --}}
    @if($h['quoteparts'] !== [])
        <div class="mb-2 overflow-x-auto">
            <div class="text-[10px] uppercase tracking-wider text-fg-4 mb-0.5">Через QuoteParts</div>
            <table class="w-full text-[12px]">
                <tbody>
                    @foreach($h['quoteparts'] as $q)
                        <tr @class(['border-t border-border-subtle align-baseline', 'opacity-60' => ! $q['current']])>
                            <td class="py-1 pr-2 text-fg-1">{{ \Illuminate\Support\Str::limit($q['supplier'], 40) }}</td>
                            <td class="py-1 px-2 text-fg-3 whitespace-nowrap">{{ $q['at']?->format('d.m.Y') }} · {{ $q['source'] }}</td>
                            <td class="py-1 px-2 whitespace-nowrap">
                                <span class="mono tnum font-semibold {{ $q['current'] ? 'text-emerald-700' : 'text-fg-2' }}">{{ $q['price'] !== null ? $money($q['price']).' '.$q['currency'] : '—' }}</span>
                                @if(count($q['tiers']) > 1)<span class="text-fg-4">· {{ collect($q['tiers'])->skip(1)->map(fn ($t) => 'от '.(float) $t['min_qty'].': '.$money($t['price']))->implode(', ') }}</span>@endif
                                @if($q['lead_time_days'] !== null)<span class="text-fg-4">· {{ $q['lead_time_days'] }} дн.</span>@endif
                            </td>
                            <td class="py-1 px-2">
                                @if($q['kind'] !== 'original')
                                    <span class="chip chip-sky text-[10px]">{{ \App\Models\QuotePartsOffer::kindLabel($q['kind']) }}</span>@if($q['product'] !== '') <span class="mono text-fg-3">{{ $q['product'] }}</span>@endif
                                    <span class="chip {{ $q['review'] === 'accepted' ? 'chip-ok' : ($q['review'] === 'rejected' ? 'chip-neutral' : 'chip-warn') }} text-[10px]">{{ ['accepted' => 'принято к закупке', 'rejected' => 'отклонено'][$q['review']] ?? 'на разборе' }}</span>
                                @endif
                                @if($q['note'])<span class="text-fg-3">«{{ \Illuminate\Support\Str::limit($q['note'], 60) }}»</span>@endif
                                @php $__g = $q['photos'] > 0 ? collect(range(0, $q['photos'] - 1))->map(fn ($i) => ['src' => route('quoteparts.offer-photo', [$q['remote_id'], $i]), 'name' => 'Фото '.($i + 1), 'dl' => route('quoteparts.offer-photo', [$q['remote_id'], $i])])->all() : []; @endphp @foreach($__g as $__i => $ph)<a href="{{ $ph['src'] }}" x-on:click.prevent="$dispatch('open-image', {{ \Illuminate\Support\Js::from(['items' => $__g, 'index' => $__i]) }})" class="text-sky-700 text-[11px]">📷</a>@endforeach
                            </td>
                            <td class="py-1 pl-2 text-fg-4 whitespace-nowrap">{{ $q['status'] }}{{ $q['valid_until'] ? ' · до '.$q['valid_until']->format('d.m.Y') : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($h['asked'] !== [])
        <div class="overflow-x-auto">
            <table class="w-full text-[12px]">
                <thead class="text-[10px] uppercase tracking-wider text-fg-4">
                    <tr class="text-left">
                        <th class="py-1 pr-2 font-medium">Поставщик</th>
                        <th class="py-1 px-2 font-medium">Запрос</th>
                        <th class="py-1 px-2 font-medium">Ответ</th>
                        <th class="py-1 pl-2 font-medium">Заявка</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($h['asked'] as $a)
                        <tr class="border-t border-border-subtle align-baseline">
                            <td class="py-1 pr-2 text-fg-1">
                                <span title="{{ $a['email'] }}">{{ \Illuminate\Support\Str::limit($a['supplier'], 40) }}</span>{!! $groupsChips($a['groups']) !!}
                            </td>
                            <td class="py-1 px-2 text-fg-3 whitespace-nowrap">{{ $a['asked_at']->format('d.m.Y') }}{{ $a['by'] ? ' · '.$a['by'] : '' }}</td>
                            <td class="py-1 px-2 whitespace-nowrap">
                                @if($a['status'] === 'quoted')
                                    <span class="text-emerald-700 font-semibold mono tnum">{{ $a['price'] !== null ? $money($a['price']).' '.($a['currency'] ?: '₽') : 'цена' }}</span>
                                @elseif($a['status'] === 'refused')
                                    <span class="text-amber-700">отказ</span>
                                @elseif($a['status'] === 'pending')
                                    <span class="text-fg-4">ждём ответ</span>
                                @else
                                    <span class="text-fg-4">{{ $a['status'] }}</span>
                                @endif
                            </td>
                            <td class="py-1 pl-2">
                                @if($a['request_id'])
                                    <a href="{{ route('requests.show', $a['request_id']) }}" target="_blank" class="mono text-sky-700 hover:underline">{{ $a['request_code'] }}</a>
                                @else
                                    <span class="text-fg-4">снабжение</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif($h['onec'] === [] && $h['quoteparts'] === [])
        <p class="text-[12px] text-fg-3">По этой позиции поставщиков ещё не спрашивали, закупок в 1С нет.</p>
    @else
        <p class="text-[12px] text-fg-3">Через систему поставщиков по этой позиции ещё не спрашивали.</p>
    @endif
</div>
