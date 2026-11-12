{{-- «Результаты» раздела QuoteParts: SVG прямо в шаблоне, как графики «Аналитики»; стили inline — без пересборки CSS. --}}
@php
    $act = $this->actuality;
    $last = end($act) ?: null;
    $series = [
        'all' => ['Весь прайс', 'var(--sky-600, #0284c7)'],
        'in_stock' => ['В наличии', 'var(--emerald-600, #059669)'],
        'to_order' => ['Под заказ', 'var(--amber-600, #d97706)'],
    ];
    $W = 760; $H = 240; $L = 40; $R = 12; $T = 12; $B = 28;
    $maxV = max(10, ...array_map(fn ($r) => max($r['all'], $r['in_stock'], $r['to_order']), $act ?: [['all' => 0, 'in_stock' => 0, 'to_order' => 0]]));
    $yMax = min(100, (int) ceil($maxV * 1.15 / 10) * 10);
    $n = count($act);
    $x = fn ($i) => $n <= 1 ? $L + ($W - $L - $R) / 2 : $L + $i * ($W - $L - $R) / ($n - 1);
    $y = fn ($v) => $T + ($H - $T - $B) * (1 - $v / $yMax);
    $pc = fn ($v) => number_format((float) $v, 1, ',', ' ').'%';
    $weeks = $this->purchase;
@endphp
<div style="display: flex; flex-direction: column; gap: 16px;">
    <div class="ds-card">
        <div class="ds-card-header"><h3>Позиции прайса с актуальной ценой</h3>
            <span class="text-[12px] text-fg-3 ml-2">доля активных позиций каталога с отметкой «цена актуальна» из 1С · снимок раз в сутки</span></div>
        <div class="ds-card-body" style="display: flex; flex-direction: column; gap: 10px;">
            @if($last)
                <div style="display: flex; gap: 22px; flex-wrap: wrap; font-size: 13px;">
                    @foreach($series as $key => [$label, $color])
                        <div><span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: {{ $color }}; margin-right: 6px;"></span>{{ $label }}:
                            <b style="font-size: 15px;">{{ $pc($last[$key]) }}</b></div>
                    @endforeach
                    <div style="color: var(--fg-3);">{{ number_format($last['actual'], 0, ',', ' ') }} из {{ number_format($last['total'], 0, ',', ' ') }} позиций · {{ \Illuminate\Support\Carbon::parse($last['day'])->format('d.m.Y') }}</div>
                </div>
                <svg viewBox="0 0 {{ $W }} {{ $H }}" style="width: 100%; max-width: {{ $W }}px; height: auto;" xmlns="http://www.w3.org/2000/svg">
                    @for($g = 0; $g <= $yMax; $g += max(10, (int) ($yMax / 5)))
                        <line x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $y($g) }}" y2="{{ $y($g) }}" stroke="var(--border-subtle, #e5e7eb)" />
                        <text x="{{ $L - 6 }}" y="{{ $y($g) + 4 }}" text-anchor="end" font-size="11" fill="var(--fg-3, #6b7280)">{{ $g }}%</text>
                    @endfor
                    @foreach($act as $i => $r)
                        @if($n <= 8 || $i % (int) ceil($n / 8) === 0 || $i === $n - 1)
                            <text x="{{ $x($i) }}" y="{{ $H - 8 }}" text-anchor="middle" font-size="11" fill="var(--fg-3, #6b7280)">{{ \Illuminate\Support\Carbon::parse($r['day'])->format('d.m') }}</text>
                        @endif
                    @endforeach
                    @foreach($series as $key => [$label, $color])
                        @if($n > 1)
                            <polyline fill="none" stroke="{{ $color }}" stroke-width="2" points="{{ collect($act)->map(fn ($r, $i) => round($x($i), 1).','.round($y($r[$key]), 1))->implode(' ') }}" />
                        @endif
                        @foreach($act as $i => $r)
                            <circle cx="{{ $x($i) }}" cy="{{ $y($r[$key]) }}" r="{{ $n > 30 ? 1.5 : 3 }}" fill="{{ $color }}"><title>{{ $label }} · {{ \Illuminate\Support\Carbon::parse($r['day'])->format('d.m.Y') }}: {{ $pc($r[$key]) }}</title></circle>
                        @endforeach
                    @endforeach
                </svg>
                @if($n < 7)<div class="text-[12px] text-fg-3">Данные копятся с 12.11.2026: до этого история актуальности не хранилась. Линии появятся со второго дня.</div>@endif
            @else
                <div class="text-[13px] text-fg-3">Первый снимок появится сегодня ночью.</div>
            @endif
        </div>
    </div>

    <div class="ds-card">
        <div class="ds-card-header"><h3>Цены закупки: решение против прошлой цены позиции</h3>
            <span class="text-[12px] text-fg-3 ml-2">цена «Закупаем здесь», выгруженная в 1С с завершением сбора цен, против последней известной цены в той же валюте: нашего прошлого решения или закупки по истории цен 1С — что новее · по неделям</span></div>
        <div class="ds-card-body" style="display: flex; flex-direction: column; gap: 10px;">
            @if($weeks === [])
                <div class="text-[13px] text-fg-3">Закупочных решений пока нет: они появляются при «Выгрузить и завершить сбор цен».</div>
            @else
                @php
                    $BW = 760; $BH = 200; $bL = 40; $bB = 34; $bT = 16;
                    $maxN = max(1, ...array_map(fn ($w) => max($w['up'], $w['down']), $weeks));
                    $slot = ($BW - $bL - 12) / max(1, count($weeks));
                    $bar = min(26, $slot / 3);
                    $by = fn ($v) => $bT + ($BH - $bT - $bB) * (1 - $v / $maxN);
                    $totalDown = array_sum(array_column($weeks, 'down'));
                    $totalUp = array_sum(array_column($weeks, 'up'));
                @endphp
                <div style="display: flex; gap: 22px; flex-wrap: wrap; font-size: 13px;">
                    <div><span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: var(--emerald-600, #059669); margin-right: 6px;"></span>дешевле прошлой цены: <b>{{ $totalDown }}</b></div>
                    <div><span style="display: inline-block; width: 10px; height: 10px; border-radius: 2px; background: var(--red-600, #dc2626); margin-right: 6px;"></span>дороже: <b>{{ $totalUp }}</b></div>
                    <div style="color: var(--fg-3);">над столбцами — медиана изменения цены за неделю</div>
                </div>
                <svg viewBox="0 0 {{ $BW }} {{ $BH }}" style="width: 100%; max-width: {{ $BW }}px; height: auto;" xmlns="http://www.w3.org/2000/svg">
                    <line x1="{{ $bL }}" x2="{{ $BW - 12 }}" y1="{{ $BH - $bB }}" y2="{{ $BH - $bB }}" stroke="var(--border, #d1d5db)" />
                    <text x="{{ $bL - 6 }}" y="{{ $by($maxN) + 4 }}" text-anchor="end" font-size="11" fill="var(--fg-3, #6b7280)">{{ $maxN }}</text>
                    @foreach($weeks as $i => $w)
                        @php $cx = $bL + $slot * ($i + 0.5); @endphp
                        <rect x="{{ $cx - $bar - 1 }}" y="{{ $by($w['down']) }}" width="{{ $bar }}" height="{{ max(0, $BH - $bB - $by($w['down'])) }}" fill="var(--emerald-600, #059669)"><title>дешевле: {{ $w['down'] }}</title></rect>
                        <rect x="{{ $cx + 1 }}" y="{{ $by($w['up']) }}" width="{{ $bar }}" height="{{ max(0, $BH - $bB - $by($w['up'])) }}" fill="var(--red-600, #dc2626)"><title>дороже: {{ $w['up'] }}</title></rect>
                        @if($w['n'] > 0)
                            <text x="{{ $cx }}" y="{{ min($by($w['down']), $by($w['up'])) - 4 }}" text-anchor="middle" font-size="11" font-weight="600"
                                  fill="{{ $w['median'] < 0 ? 'var(--emerald-700, #047857)' : ($w['median'] > 0 ? 'var(--red-700, #b91c1c)' : 'var(--fg-3, #6b7280)') }}">{{ $w['median'] > 0 ? '+' : '' }}{{ number_format($w['median'], 1, ',', '') }}%</text>
                        @endif
                        <text x="{{ $cx }}" y="{{ $BH - $bB + 16 }}" text-anchor="middle" font-size="11" fill="var(--fg-3, #6b7280)">{{ \Illuminate\Support\Carbon::parse($w['week'])->format('d.m') }}</text>
                    @endforeach
                </svg>
                <table style="width: 100%; max-width: 760px; font-size: 12.5px; border-collapse: collapse;">
                    <thead><tr style="color: var(--fg-3); text-align: right;">
                        <th style="text-align: left; padding: 4px 6px;">Неделя</th><th style="padding: 4px 6px;">Сравнили</th><th style="padding: 4px 6px;">Дешевле</th>
                        <th style="padding: 4px 6px;">Дороже</th><th style="padding: 4px 6px;">Так же</th><th style="padding: 4px 6px;">Медиана</th>
                        <th style="padding: 4px 6px;" title="Сравнили с нашим прошлым решением, а не с закупкой 1С">Против нашей цены</th><th style="padding: 4px 6px;">Без прошлой цены</th></tr></thead>
                    <tbody>
                        @foreach(array_reverse($weeks) as $w)
                            <tr style="border-top: 1px solid var(--border-subtle); text-align: right;">
                                <td style="text-align: left; padding: 4px 6px;">с {{ \Illuminate\Support\Carbon::parse($w['week'])->format('d.m.Y') }}</td>
                                <td style="padding: 4px 6px;">{{ $w['n'] }}</td><td style="padding: 4px 6px; color: var(--emerald-700, #047857);">{{ $w['down'] }}</td>
                                <td style="padding: 4px 6px; color: var(--red-700, #b91c1c);">{{ $w['up'] }}</td><td style="padding: 4px 6px;">{{ $w['flat'] }}</td>
                                <td style="padding: 4px 6px; font-weight: 600;">{{ $w['n'] ? ($w['median'] > 0 ? '+' : '').number_format($w['median'], 1, ',', '').'%' : '—' }}</td>
                                <td style="padding: 4px 6px;">{{ $w['vs_own'] ?? 0 }}</td>
                                <td style="padding: 4px 6px; color: var(--fg-3);" title="Другая валюта или прежних цен позиции нет">{{ $w['unmatched'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>
