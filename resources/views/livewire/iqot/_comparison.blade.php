{{-- Сравнение «наша цена vs офферы IQOT» по позиции. Ожидает $pos (IqotPosition).
     Используется в разделе IQOT и в отчёте «Топ позиций». --}}
@php $cmp = $pos->priceComparison(); @endphp
@if($cmp['our_rank'])
    <div class="text-[12px] text-fg-2 mb-2">
        <b class="text-red-700">{{ $cmp['our_label'] ?? 'Наша цена' }}</b>
        занимает <b>{{ $cmp['our_rank'] }}-е место</b> из {{ $cmp['total'] }} по цене (без НДС)
        @if($cmp['delta'] !== null)
            · vs лучший IQOT:
            <b class="{{ $cmp['delta'] > 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ $cmp['delta'] > 0 ? '+' : '' }}{{ number_format($cmp['delta'], 0, ',', ' ') }} ₽ ({{ $cmp['delta'] > 0 ? '+' : '' }}{{ number_format($cmp['delta_pct'], 1, ',', ' ') }}%)</b>
        @endif
    </div>
@else
    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-1.5">Предложения поставщиков ({{ $cmp['total'] }}) · сравнение по цене без НДС</div>
@endif
<table class="w-full text-[12px]">
    <thead class="text-fg-3 text-[10px] uppercase tracking-wider">
        <tr>
            <th class="px-2 py-1 text-center w-8">#</th>
            <th class="px-2 py-1 text-left">Поставщик</th>
            <th class="px-2 py-1 text-left">Контакты</th>
            <th class="px-2 py-1 text-right">Цена/шт</th>
            <th class="px-2 py-1 text-right">Без НДС, ₽</th>
            <th class="px-2 py-1 text-right">Срок</th>
            <th class="px-2 py-1 text-left">Получено</th>
        </tr>
    </thead>
    <tbody>
        @foreach($cmp['rows'] as $i => $row)
            <tr class="border-t border-border-subtle {{ $row['is_ours'] ? 'bg-red-50' : '' }}" @if(!empty($row['notes'])) title="{{ $row['notes'] }}" @endif>
                <td class="px-2 py-1 text-center font-bold {{ $row['is_ours'] ? 'text-red-700' : 'text-fg-3' }}">{{ $i + 1 }}</td>
                <td class="px-2 py-1 {{ $row['is_ours'] ? 'text-red-700' : 'text-fg-1' }}">
                    @if($row['is_ours'])<span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-red-600 text-white mr-1">КП</span>@endif{{ $row['supplier'] }}
                </td>
                <td class="px-2 py-1 text-fg-2 text-[11px] mono">
                    @if($row['is_ours'])<span class="text-fg-3">собственное КП</span>@else{{ $row['phone'] }}{{ !empty($row['email']) ? ' · ' . $row['email'] : '' }}@endif
                </td>
                <td class="px-2 py-1 text-right mono font-semibold {{ $row['is_ours'] ? 'text-red-700' : 'text-fg-1' }}">
                    {{ number_format($row['raw'], 2, ',', ' ') }} {{ $row['currency_symbol'] }}
                    <div class="text-[9px] text-fg-3">{{ $row['vat_label'] }}</div>
                    @if($row['converted'])
                        @if($row['rate_known'])
                            <div class="text-[9px] text-amber-700"
                                 title="Пересчитано по курсу {{ number_format($row['rate'], 2, ',', ' ') }} ₽/{{ $row['currency'] }} (Настройки) — приблизительно">
                                ≈ {{ number_format($row['raw_rub'], 0, ',', ' ') }} ₽ · курс ~
                            </div>
                        @else
                            <div class="text-[9px] text-red-600"
                                 title="Курс валюты {{ $row['currency'] }} не задан в Настройках → не участвует в сравнении">
                                курс {{ $row['currency'] }} не задан
                            </div>
                        @endif
                    @endif
                </td>
                <td class="px-2 py-1 text-right mono {{ $row['net_rub'] === null ? 'text-fg-4' : 'text-fg-2' }}">
                    @if($row['net_rub'] !== null)
                        {{ number_format($row['net_rub'], 2, ',', ' ') }}@if($row['converted'])<span class="text-amber-700">*</span>@endif
                    @else
                        —
                    @endif
                </td>
                <td class="px-2 py-1 text-right mono text-fg-2">{{ $row['delivery_days'] !== null ? $row['delivery_days'] . ' дн' : '—' }}</td>
                <td class="px-2 py-1 text-fg-3 text-[11px]">{{ $row['received_at'] ? \Illuminate\Support\Carbon::parse($row['received_at'])->format('d.m H:i') : '—' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@if(collect($cmp['rows'])->contains('converted', true))
    <div class="mt-1.5 text-[10px] text-amber-700">
        <span class="font-semibold">*</span> цены в иностранной валюте пересчитаны в ₽ по приблизительному курсу из Настроек (для ранжирования). Уточняйте актуальный курс.
    </div>
@endif
