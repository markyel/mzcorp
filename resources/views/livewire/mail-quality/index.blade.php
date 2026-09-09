<div class="space-y-4">
    @php
        $r = $this->report;
        $pct = fn (?float $v) => $v === null ? '—' : number_format($v * 100, 0) . '%';
        $rateClass = function (?float $v) {
            if ($v === null) return 'text-fg-3';
            if ($v >= 0.2) return 'text-[var(--red-700)] font-semibold';
            if ($v >= 0.1) return 'text-[var(--amber-700,#b45309)] font-semibold';
            return 'text-[var(--emerald-700)]';
        };
        $phantomRate = $r['phantoms']['requests_created'] > 0
            ? $r['phantoms']['total'] / $r['phantoms']['requests_created']
            : null;
    @endphp

    {{-- ───────── Header + период ───────── --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Качество почты</h3>
            <span class="text-[12px] text-fg-3 ml-2">Период: {{ $this->periodLabel }}</span>
        </div>
        <div class="px-4 pb-3 flex items-center gap-2 flex-wrap text-[12px]">
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
                @foreach(['7' => '7 дн.', '30' => '30 дн.', '90' => '90 дн.'] as $k => $label)
                    @php $on = $periodDays === (int) $k; @endphp
                    <button type="button" wire:click="setPeriod({{ $k }})"
                            class="h-[26px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <span class="text-fg-3">Дрейф решений почтового конвейера: детекторы, откаты статусов, заявки-фантомы, маршрут писем. Ориентиры: отклонённых решений детектора ниже 10%, откатов меньше 100 в месяц, фантомов меньше 3% заявок.</span>
        </div>
    </div>

    {{-- ───────── Сводка ───────── --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="ds-card p-4">
            <div class="text-[11px] uppercase tracking-wider text-fg-3">Заявок создано</div>
            <div class="text-[26px] font-semibold tabular-nums">{{ number_format($r['phantoms']['requests_created'], 0, '.', ' ') }}</div>
        </div>
        <div class="ds-card p-4">
            <div class="text-[11px] uppercase tracking-wider text-fg-3">Заявки-фантомы</div>
            <div class="text-[26px] font-semibold tabular-nums {{ $rateClass($phantomRate === null ? null : $phantomRate * 5) }}">{{ $r['phantoms']['total'] }}<span class="text-[14px] text-fg-3 font-normal ml-1">{{ $phantomRate === null ? '' : '· ' . $pct($phantomRate) }}</span></div>
            <div class="text-[11.5px] text-fg-3">дубль, постпродажа, не по теме, пустой парсинг</div>
        </div>
        <div class="ds-card p-4">
            <div class="text-[11px] uppercase tracking-wider text-fg-3">Ручных откатов статуса</div>
            <div class="text-[26px] font-semibold tabular-nums {{ $r['rollbacks']['total'] > 100 * max(1, $periodDays / 30) ? 'text-[var(--amber-700,#b45309)]' : '' }}">{{ $r['rollbacks']['total'] }}</div>
            <div class="text-[11.5px] text-fg-3">из «КП / ждёт счёт / счёт» обратно в работу</div>
        </div>
        <div class="ds-card p-4">
            @php $worst = collect($r['detectors'])->filter(fn ($d) => $d['dismiss_rate'] !== null && $d['total'] >= 20)->sortByDesc('dismiss_rate')->first(); @endphp
            <div class="text-[11px] uppercase tracking-wider text-fg-3">Самый шумный детектор</div>
            @if($worst)
                <div class="text-[26px] font-semibold tabular-nums {{ $rateClass($worst['dismiss_rate']) }}">{{ $pct($worst['dismiss_rate']) }}</div>
                <div class="text-[11.5px] text-fg-3">{{ $worst['label'] }} · отклонено {{ $worst['dismissed'] + $worst['overridden'] }} из {{ $worst['total'] }}</div>
            @else
                <div class="text-[26px] font-semibold text-fg-3">—</div>
            @endif
        </div>
    </div>

    {{-- ───────── Детекторы ───────── --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>AI-решения по детекторам</h3><span class="text-[12px] text-fg-3 ml-2">доля отклонённых = сколько раз менеджер не согласился с автоматикой</span></div>
        <div class="px-4 pb-4 overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead class="text-[11.5px] uppercase tracking-wider text-fg-3">
                    <tr><th class="text-left py-1.5">Детектор</th><th class="text-right">Всего</th><th class="text-right">Авто</th><th class="text-right">Подтверждено</th><th class="text-right">Отклонено</th><th class="text-right">Доля отклонённых</th></tr>
                </thead>
                <tbody>
                    @forelse($r['detectors'] as $d)
                        <tr class="border-t border-border-subtle">
                            <td class="py-1.5">{{ $d['label'] }} <span class="text-fg-4 font-mono text-[11px]">{{ $d['type'] }}</span></td>
                            <td class="text-right tabular-nums">{{ $d['total'] }}</td>
                            <td class="text-right tabular-nums">{{ $d['auto_applied'] }}</td>
                            <td class="text-right tabular-nums">{{ $d['confirmed'] }}</td>
                            <td class="text-right tabular-nums">{{ $d['dismissed'] + $d['overridden'] }}</td>
                            <td class="text-right tabular-nums {{ $rateClass($d['dismiss_rate']) }}">{{ $pct($d['dismiss_rate']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-3 text-fg-3">За период решений нет.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid md:grid-cols-2 gap-4">
        {{-- ───────── Откаты ───────── --}}
        <div class="ds-card">
            <div class="ds-card-header"><h3>Ручные откаты статуса</h3><span class="text-[12px] text-fg-3 ml-2">кто чаще всего исправляет автоматику</span></div>
            <div class="px-4 pb-4">
                <table class="w-full text-[13px]">
                    <tbody>
                        @forelse($r['rollbacks']['by_manager'] as $m)
                            <tr class="border-t border-border-subtle"><td class="py-1.5">{{ $m['name'] }}</td><td class="text-right tabular-nums">{{ $m['n'] }}</td></tr>
                        @empty
                            <tr><td class="py-3 text-fg-3">Откатов за период нет.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ───────── Фантомы ───────── --}}
        <div class="ds-card">
            <div class="ds-card-header"><h3>Заявки-фантомы по причине закрытия</h3></div>
            <div class="px-4 pb-4">
                <table class="w-full text-[13px]">
                    <tbody>
                        @forelse($r['phantoms']['by_reason'] as $p)
                            <tr class="border-t border-border-subtle"><td class="py-1.5">{{ $p['label'] }} <span class="text-fg-4 font-mono text-[11px]">{{ $p['reason'] }}</span></td><td class="text-right tabular-nums">{{ $p['n'] }}</td></tr>
                        @empty
                            <tr><td class="py-3 text-fg-3">Фантомов за период нет.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ───────── Динамика ───────── --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>По неделям</h3><span class="text-[12px] text-fg-3 ml-2">неделя от понедельника</span></div>
        <div class="px-4 pb-4 overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead class="text-[11.5px] uppercase tracking-wider text-fg-3">
                    <tr><th class="text-left py-1.5">Неделя</th><th class="text-right">Заявок</th><th class="text-right">Фантомов</th><th class="text-right">Откатов</th><th class="text-right">Отклонённых решений</th><th class="text-right">Постпродажа</th></tr>
                </thead>
                <tbody>
                    @foreach($r['weekly'] as $w)
                        <tr class="border-t border-border-subtle">
                            <td class="py-1.5 font-mono">{{ $w['week'] }}</td>
                            <td class="text-right tabular-nums">{{ $w['requests'] }}</td>
                            <td class="text-right tabular-nums">{{ $w['phantoms'] }}</td>
                            <td class="text-right tabular-nums">{{ $w['rollbacks'] }}</td>
                            <td class="text-right tabular-nums">{{ $w['dismissed'] }}</td>
                            <td class="text-right tabular-nums">{{ $w['post_sale'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ───────── Маршрут писем ───────── --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>Куда уходят письма</h3><span class="text-[12px] text-fg-3 ml-2">журнал решений маршрутизатора, с 9 сентября 2026</span></div>
        <div class="px-4 pb-4 overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead class="text-[11.5px] uppercase tracking-wider text-fg-3">
                    <tr><th class="text-left py-1.5">Стадия</th><th class="text-left">Исход</th><th class="text-right">Писем</th></tr>
                </thead>
                <tbody>
                    @forelse($r['stages'] as $s)
                        <tr class="border-t border-border-subtle">
                            <td class="py-1.5">{{ $s['label'] }} <span class="text-fg-4 font-mono text-[11px]">{{ $s['stage'] }}</span></td>
                            <td class="font-mono text-[11.5px] text-fg-2">{{ $s['outcome'] }}</td>
                            <td class="text-right tabular-nums">{{ $s['n'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-3 text-fg-3">Решений за период нет.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
