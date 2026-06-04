<div class="space-y-4">
    @php
        // Формат длительности между двумя метками времени.
        $fmtDur = function ($start, $end) {
            if (! $start || ! $end) return null;
            try {
                $s = \Illuminate\Support\Carbon::parse($start);
                $e = \Illuminate\Support\Carbon::parse($end);
            } catch (\Throwable) { return null; }
            $mins = $s->diffInMinutes($e);
            if ($mins < 60) return $mins . ' мин';
            if ($mins < 60 * 24) return rtrim(rtrim(number_format($mins / 60, 1, '.', ''), '0'), '.') . ' ч';
            return rtrim(rtrim(number_format($mins / 1440, 1, '.', ''), '0'), '.') . ' дн';
        };
        $fmtH = function (?float $h) {
            if ($h === null) return '—';
            if ($h < 48) return rtrim(rtrim(number_format($h, 1, '.', ''), '0'), '.') . ' ч';
            return rtrim(rtrim(number_format($h / 24, 1, '.', ''), '0'), '.') . ' дн';
        };
    @endphp

    {{-- ───────── Header + фильтры ───────── --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Аналитика по менеджерам</h3>
            <span class="text-[12px] text-fg-3 ml-2">Период: {{ $this->periodLabel }}</span>
        </div>
        <div class="px-4 pb-3 flex items-center gap-2 gap-y-2 flex-wrap text-[12px]">
            {{-- Период --}}
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
                @foreach(['7' => '7 дн.', '30' => '30 дн.', '90' => '90 дн.'] as $k => $label)
                    @php $on = ! $this->isCustomPeriod && $periodDays === (int) $k; @endphp
                    <button type="button" wire:click="setPeriod({{ $k }})"
                            class="h-[26px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            {{-- Произвольный диапазон --}}
            <div class="inline-flex items-center gap-1">
                <input type="date" wire:model="customFrom"
                       class="h-[26px] px-1.5 border border-border rounded-md bg-surface text-fg-1 text-[12px] outline-none focus:border-[var(--sky-500)]" />
                <span class="text-fg-3">–</span>
                <input type="date" wire:model="customTo"
                       class="h-[26px] px-1.5 border border-border rounded-md bg-surface text-fg-1 text-[12px] outline-none focus:border-[var(--sky-500)]" />
                <button type="button" wire:click="applyCustomPeriod" class="btn btn-sm">Применить</button>
                @if($this->isCustomPeriod)
                    <button type="button" wire:click="clearCustomPeriod" class="btn btn-sm" title="Сбросить произвольный период">✕</button>
                @endif
            </div>

            <span class="text-fg-4">|</span>

            {{-- Менеджеры (multi-select) --}}
            <div class="flex items-center gap-1.5 flex-wrap">
                @foreach($this->managers as $m)
                    @php $on = in_array($m->id, $managerIds, true); @endphp
                    <button type="button" wire:click="toggleManager({{ $m->id }})"
                            class="h-[24px] px-2 rounded-full border text-[11.5px] whitespace-nowrap
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent border-[var(--accent)]' : 'bg-surface border-border text-fg-2 hover:text-fg-1' }}">
                        {{ $m->name }}
                    </button>
                @endforeach
                @if(! empty($managerIds))
                    <button type="button" wire:click="clearManagers" class="text-[11.5px] text-fg-3 hover:text-fg-1 underline">все</button>
                @endif
            </div>
        </div>
    </div>

    {{-- ───────── 1. Динамика закрытых заявок (мультилиния) ───────── --}}
    @php
        $dyn = $this->dynamics;
        $series = $dyn['series'];
        $labels = $dyn['labels'];
        $n = count($labels);
        $maxV = max(1, (int) $dyn['max']);
        $svgW = 900; $svgH = 260; $padL = 30; $padR = 12; $padT = 12; $padB = 26;
        $xAt = function ($i) use ($svgW, $padL, $padR, $n) {
            if ($n <= 1) return $padL;
            return $padL + ($i * ($svgW - $padL - $padR) / ($n - 1));
        };
        $yAt = function ($v) use ($svgH, $padT, $padB, $maxV) {
            return $svgH - $padB - ($v * ($svgH - $padT - $padB) / $maxV);
        };
        $buildPath = function ($points) use ($xAt, $yAt) {
            $d = '';
            foreach ($points as $i => $v) {
                $d .= ($i === 0 ? 'M' : 'L') . round($xAt($i), 1) . ' ' . round($yAt($v), 1) . ' ';
            }
            return trim($d);
        };
        $yTicks = $maxV <= 4 ? range(0, $maxV) : [0, (int) round($maxV / 2), $maxV];
        $xStep = max(1, (int) ceil($n / 12));
        $grandTotal = array_sum(array_map(fn ($s) => $s['total'], $series));
    @endphp
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Динамика закрытых заявок по менеджерам</h3>
            <span class="text-[12px] text-fg-3 ml-2">won+lost по дате закрытия · всего {{ $grandTotal }}</span>
        </div>
        <div class="ds-card-body">
            @if($grandTotal === 0)
                <div class="text-center text-fg-3 py-8 text-[13px]">За период закрытых заявок нет.</div>
            @else
                <svg viewBox="0 0 {{ $svgW }} {{ $svgH }}" xmlns="http://www.w3.org/2000/svg"
                     preserveAspectRatio="xMidYMid meet"
                     style="width:100%;height:auto;max-height:280px;font-family:var(--font-sans);font-size:10px;">
                    @foreach($yTicks as $tick)
                        @php $ty = $yAt((int) $tick); @endphp
                        <line x1="{{ $padL }}" x2="{{ $svgW - $padR }}" y1="{{ round($ty, 1) }}" y2="{{ round($ty, 1) }}"
                              stroke="#e5e7eb" stroke-width="1" />
                        <text x="{{ $padL - 5 }}" y="{{ round($ty + 3, 1) }}" text-anchor="end" fill="#9ca3af" font-size="10">{{ $tick }}</text>
                    @endforeach
                    @foreach($labels as $i => $lab)
                        @if($i % $xStep === 0 || $i === $n - 1)
                            <text x="{{ round($xAt($i), 1) }}" y="{{ $svgH - $padB + 14 }}" text-anchor="middle" fill="#6b7280" font-size="10">{{ $lab }}</text>
                        @endif
                    @endforeach
                    @foreach($series as $s)
                        @if($s['total'] > 0)
                            <path d="{{ $buildPath($s['points']) }}" fill="none" stroke="{{ $s['color'] }}"
                                  stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        @endif
                    @endforeach
                </svg>
                {{-- Легенда --}}
                <div class="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-[11.5px]">
                    @foreach($series as $s)
                        @if($s['total'] > 0)
                            <span class="inline-flex items-center gap-1.5">
                                <span class="inline-block w-3 h-[3px] rounded-full" style="background: {{ $s['color'] }}"></span>
                                <span class="text-fg-1">{{ $s['name'] }}</span>
                                <span class="text-fg-3">— {{ $s['total'] }} (<span class="text-emerald-700">{{ $s['won'] }}</span>/<span class="text-red-700">{{ $s['lost'] }}</span>)</span>
                            </span>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ───────── 2. Успех/Потеря по менеджерам (когорта по дате создания) ───────── --}}
    @php $wl = $this->wonLost; @endphp
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Закрытые заявки: Успех / Потеря по менеджерам</h3>
            <span class="text-[12px] text-fg-3 ml-2">когорта по дате создания за период</span>
        </div>
        <div class="ds-card-body overflow-x-auto">
            @if(empty($wl))
                <div class="text-center text-fg-3 py-6 text-[13px]">Нет данных за период.</div>
            @else
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                        <tr>
                            <th class="px-2 py-2 text-left">Менеджер</th>
                            <th class="px-2 py-2 text-right">Всего</th>
                            <th class="px-2 py-2 text-right text-emerald-700">Успех</th>
                            <th class="px-2 py-2 text-right text-red-700">Потеря</th>
                            <th class="px-2 py-2 text-right text-fg-3">В работе</th>
                            <th class="px-2 py-2 text-right">Win-rate</th>
                            <th class="px-2 py-2 text-left w-[180px]">Распределение</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($wl as $row)
                            @php $tot = max(1, $row['total']); @endphp
                            <tr class="border-b border-border-subtle last:border-b-0">
                                <td class="px-2 py-1.5 text-fg-1">{{ $row['name'] }}</td>
                                <td class="px-2 py-1.5 text-right mono text-fg-1">{{ $row['total'] }}</td>
                                <td class="px-2 py-1.5 text-right mono text-emerald-700">{{ $row['won'] }}</td>
                                <td class="px-2 py-1.5 text-right mono text-red-700">{{ $row['lost'] }}</td>
                                <td class="px-2 py-1.5 text-right mono text-fg-3">{{ $row['open'] }}</td>
                                <td class="px-2 py-1.5 text-right mono {{ $row['win_rate'] === null ? 'text-fg-4' : ($row['win_rate'] >= 50 ? 'text-emerald-700' : 'text-amber-700') }}">
                                    {{ $row['win_rate'] === null ? '—' : $row['win_rate'] . '%' }}
                                </td>
                                <td class="px-2 py-1.5">
                                    <div class="flex h-2.5 rounded-sm overflow-hidden bg-surface-2">
                                        <div style="width: {{ $row['won'] / $tot * 100 }}%" class="bg-emerald-500" title="Успех: {{ $row['won'] }}"></div>
                                        <div style="width: {{ $row['lost'] / $tot * 100 }}%" class="bg-red-500" title="Потеря: {{ $row['lost'] }}"></div>
                                        <div style="width: {{ $row['open'] / $tot * 100 }}%" class="bg-neutral-300" title="В работе: {{ $row['open'] }}"></div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- ───────── 2b. Общая круговая: Успех/Потеря, доли по менеджерам ───────── --}}
    @php
        $pieRows = array_values(array_filter($wl, fn ($r) => ($r['won'] + $r['lost']) > 0));
        $greens = ['#065f46', '#047857', '#059669', '#10b981', '#34d399', '#6ee7b7', '#a7f3d0', '#bbf7d0'];
        $reds   = ['#7f1d1d', '#991b1b', '#b91c1c', '#dc2626', '#ef4444', '#f87171', '#fca5a5', '#fecaca'];
        $wonSegs = []; $lostSegs = []; $pTotWon = 0; $pTotLost = 0; $legend = [];
        foreach ($pieRows as $pi => $row) {
            $g = $greens[$pi % count($greens)];
            $rd = $reds[$pi % count($reds)];
            if ($row['won'] > 0)  $wonSegs[]  = ['name' => $row['name'], 'value' => $row['won'],  'color' => $g,  'group' => 'Успех'];
            if ($row['lost'] > 0) $lostSegs[] = ['name' => $row['name'], 'value' => $row['lost'], 'color' => $rd, 'group' => 'Потеря'];
            $pTotWon += $row['won']; $pTotLost += $row['lost'];
            $legend[] = ['name' => $row['name'], 'won' => $row['won'], 'lost' => $row['lost'], 'g' => $g, 'r' => $rd];
        }
        $segs = array_merge($wonSegs, $lostSegs);
        $pTotal = $pTotWon + $pTotLost;
        $cx = 110; $cy = 110; $rO = 100; $rI = 58;
        $polar = function ($r, $deg) use ($cx, $cy) {
            $a = deg2rad($deg - 90);
            return [round($cx + $r * cos($a), 2), round($cy + $r * sin($a), 2)];
        };
        $arc = function ($s, $e) use ($polar, $rO, $rI) {
            $large = ($e - $s) > 180 ? 1 : 0;
            [$x1, $y1] = $polar($rO, $s); [$x2, $y2] = $polar($rO, $e);
            [$x3, $y3] = $polar($rI, $e); [$x4, $y4] = $polar($rI, $s);
            return "M $x1 $y1 A $rO $rO 0 $large 1 $x2 $y2 L $x3 $y3 A $rI $rI 0 $large 0 $x4 $y4 Z";
        };
        $acc = 0; $arcs = [];
        foreach ($segs as $sg) {
            $ang = $pTotal > 0 ? $sg['value'] / $pTotal * 360 : 0;
            $arcs[] = ['seg' => $sg, 'start' => $acc, 'end' => $acc + $ang];
            $acc += $ang;
        }
        $wonPct = $pTotal > 0 ? round($pTotWon * 100 / $pTotal) : 0;
    @endphp
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Общая: Успех / Потеря по менеджерам</h3>
            <span class="text-[12px] text-fg-3 ml-2">закрытые за период · когорта по дате создания</span>
        </div>
        <div class="ds-card-body">
            @if($pTotal === 0)
                <div class="text-center text-fg-3 py-6 text-[13px]">Нет закрытых заявок за период.</div>
            @else
                <div class="flex flex-wrap items-center gap-6">
                    <div class="shrink-0 mx-auto sm:mx-0">
                        <svg viewBox="0 0 220 220" width="220" height="220" style="font-family:var(--font-sans)">
                            @if(count($segs) === 1)
                                <circle cx="110" cy="110" r="100" fill="{{ $segs[0]['color'] }}" />
                                <circle cx="110" cy="110" r="58" fill="var(--bg-surface)" />
                                <title>{{ $segs[0]['group'] }} · {{ $segs[0]['name'] }}: {{ $segs[0]['value'] }}</title>
                            @else
                                @foreach($arcs as $a)
                                    <path d="{{ $arc($a['start'], $a['end']) }}" fill="{{ $a['seg']['color'] }}" stroke="white" stroke-width="1">
                                        <title>{{ $a['seg']['group'] }} · {{ $a['seg']['name'] }}: {{ $a['seg']['value'] }}</title>
                                    </path>
                                @endforeach
                            @endif
                            <text x="110" y="102" text-anchor="middle" font-size="12" fill="#6b7280">Закрыто</text>
                            <text x="110" y="123" text-anchor="middle" font-size="22" font-weight="700" fill="#111827">{{ $pTotal }}</text>
                            <text x="110" y="139" text-anchor="middle" font-size="11" fill="#059669">{{ $wonPct }}% успех</text>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-[260px]">
                        <div class="flex items-center gap-4 mb-2 text-[12.5px]">
                            <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm" style="background:#059669"></span><span class="text-fg-1 font-medium">Успех: {{ $pTotWon }}</span></span>
                            <span class="inline-flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm" style="background:#dc2626"></span><span class="text-fg-1 font-medium">Потеря: {{ $pTotLost }}</span></span>
                        </div>
                        <table class="w-full text-[12px]">
                            <thead class="text-fg-3 text-[10px] uppercase tracking-wider border-b border-border">
                                <tr>
                                    <th class="px-2 py-1 text-left">Менеджер</th>
                                    <th class="px-2 py-1 text-right">Успех</th>
                                    <th class="px-2 py-1 text-right">Потеря</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($legend as $lg)
                                    <tr class="border-b border-border-subtle last:border-b-0">
                                        <td class="px-2 py-1 text-fg-1">{{ $lg['name'] }}</td>
                                        <td class="px-2 py-1 text-right">
                                            <span class="inline-flex items-center gap-1.5 justify-end">
                                                <span class="w-2.5 h-2.5 rounded-sm" style="background:{{ $lg['g'] }}"></span>
                                                <span class="mono text-emerald-700">{{ $lg['won'] }}</span>
                                            </span>
                                        </td>
                                        <td class="px-2 py-1 text-right">
                                            <span class="inline-flex items-center gap-1.5 justify-end">
                                                <span class="w-2.5 h-2.5 rounded-sm" style="background:{{ $lg['r'] }}"></span>
                                                <span class="mono text-red-700">{{ $lg['lost'] }}</span>
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ───────── 3. Время закрытия по менеджерам (Успех/Потеря) ───────── --}}
    @php $ttc = $this->timeToClose; @endphp
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Время закрытия заявок по менеджерам</h3>
            <span class="text-[12px] text-fg-3 ml-2">от создания до закрытия · когорта по дате создания</span>
        </div>
        <div class="ds-card-body overflow-x-auto">
            @if(empty($ttc))
                <div class="text-center text-fg-3 py-6 text-[13px]">Нет закрытых заявок за период.</div>
            @else
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                        <tr>
                            <th class="px-2 py-2 text-left" rowspan="2">Менеджер</th>
                            <th class="px-2 py-1.5 text-center text-emerald-700 border-l border-border-subtle" colspan="3">Успех</th>
                            <th class="px-2 py-1.5 text-center text-red-700 border-l border-border-subtle" colspan="3">Потеря</th>
                        </tr>
                        <tr class="text-[10px]">
                            <th class="px-2 py-1 text-right border-l border-border-subtle">кол-во</th>
                            <th class="px-2 py-1 text-right">сред.</th>
                            <th class="px-2 py-1 text-right">медиана</th>
                            <th class="px-2 py-1 text-right border-l border-border-subtle">кол-во</th>
                            <th class="px-2 py-1 text-right">сред.</th>
                            <th class="px-2 py-1 text-right">медиана</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($ttc as $row)
                            <tr class="border-b border-border-subtle last:border-b-0">
                                <td class="px-2 py-1.5 text-fg-1">{{ $row['name'] }}</td>
                                <td class="px-2 py-1.5 text-right mono text-fg-2 border-l border-border-subtle">{{ $row['won_count'] }}</td>
                                <td class="px-2 py-1.5 text-right mono text-fg-1">{{ $fmtH($row['won_avg_h']) }}</td>
                                <td class="px-2 py-1.5 text-right mono text-fg-2">{{ $fmtH($row['won_median_h']) }}</td>
                                <td class="px-2 py-1.5 text-right mono text-fg-2 border-l border-border-subtle">{{ $row['lost_count'] }}</td>
                                <td class="px-2 py-1.5 text-right mono text-fg-1">{{ $fmtH($row['lost_avg_h']) }}</td>
                                <td class="px-2 py-1.5 text-right mono text-fg-2">{{ $fmtH($row['lost_median_h']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- ───────── 4. Детализация обработки заявок ───────── --}}
    @php $details = $this->details; @endphp
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Детализация обработки заявок</h3>
            <span class="text-[12px] text-fg-3 ml-2">создано за период · {{ $details->total() }} заявок</span>
        </div>
        <div class="ds-card-body overflow-x-auto">
            @if($details->isEmpty())
                <div class="text-center text-fg-3 py-6 text-[13px]">Нет заявок за период.</div>
            @else
                <table class="w-full text-[12px] whitespace-nowrap">
                    <thead class="text-fg-3 text-[10px] uppercase tracking-wider border-b border-border">
                        <tr>
                            <th class="px-2 py-2 text-left">Заявка</th>
                            <th class="px-2 py-2 text-left">Менеджер</th>
                            <th class="px-2 py-2 text-left">Создана</th>
                            <th class="px-2 py-2 text-right">До 1-й реакции</th>
                            <th class="px-2 py-2 text-right">Доп. вопросов</th>
                            <th class="px-2 py-2 text-right">Обработка</th>
                            <th class="px-2 py-2 text-left">КП дано</th>
                            <th class="px-2 py-2 text-right">До КП</th>
                            <th class="px-2 py-2 text-left">Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($details as $r)
                            @php
                                $reactDur = $fmtDur($r->created_at, $r->first_reaction_at);
                                $handleEnd = $r->closed_at ?? now();
                                $handleDur = $fmtDur($r->created_at, $handleEnd);
                                $toQuoteDur = $fmtDur($r->created_at, $r->quote_sent_at);
                                $st = $r->status;
                            @endphp
                            <tr wire:key="ad-{{ $r->id }}" class="border-b border-border-subtle last:border-b-0 hover:bg-hover">
                                <td class="px-2 py-1.5">
                                    <a href="{{ route('requests.show', $r->id) }}" wire:navigate class="text-sky-700 hover:underline mono">{{ $r->internal_code }}</a>
                                </td>
                                <td class="px-2 py-1.5 text-fg-1">{{ $r->assignedUser?->name ?? '—' }}</td>
                                <td class="px-2 py-1.5 mono text-fg-2">{{ $r->created_at?->format('d.m.Y H:i') }}</td>
                                <td class="px-2 py-1.5 text-right mono {{ $reactDur ? 'text-fg-1' : 'text-fg-4' }}">{{ $reactDur ?? '—' }}</td>
                                <td class="px-2 py-1.5 text-right mono {{ (int) $r->clarifications_count > 0 ? 'text-amber-700' : 'text-fg-4' }}">{{ (int) $r->clarifications_count ?: '—' }}</td>
                                <td class="px-2 py-1.5 text-right mono text-fg-2">{{ $handleDur ?? '—' }}{{ $r->closed_at ? '' : ' (откр.)' }}</td>
                                <td class="px-2 py-1.5 mono {{ $r->quote_sent_at ? 'text-fg-1' : 'text-fg-4' }}">
                                    {{ $r->quote_sent_at ? \Illuminate\Support\Carbon::parse($r->quote_sent_at)->format('d.m.Y H:i') : '—' }}
                                </td>
                                <td class="px-2 py-1.5 text-right mono {{ $toQuoteDur ? 'text-fg-1' : 'text-fg-4' }}">{{ $toQuoteDur ?? '—' }}</td>
                                <td class="px-2 py-1.5">
                                    <span class="text-[11px] text-fg-2">{{ $st?->label() ?? $st }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="mt-3">{{ $details->links() }}</div>
            @endif
        </div>
    </div>
</div>
