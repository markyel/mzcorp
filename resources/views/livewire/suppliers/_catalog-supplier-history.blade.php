{{--
    История поставщиков по позиции каталога: кого мы спрашивали и что ответили,
    плюс первая и последняя закупка из 1С. Данные — CatalogSupplierHistoryService.
    Ожидает: $history — array{asked, onec, asked_suppliers, quoted_suppliers}|null
--}}
@php
    $h = $history ?? ['asked' => [], 'onec' => [], 'asked_suppliers' => 0, 'quoted_suppliers' => 0];
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
    @elseif($h['onec'] === [])
        <p class="text-[12px] text-fg-3">По этой позиции поставщиков ещё не спрашивали, закупок в 1С нет.</p>
    @else
        <p class="text-[12px] text-fg-3">Через систему поставщиков по этой позиции ещё не спрашивали.</p>
    @endif
</div>
