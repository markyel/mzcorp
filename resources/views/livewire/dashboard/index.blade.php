@php
    $counts = $this->requestCounts;
    $mail = $this->emailProcessing;
    $breakdown = $mail['breakdown'];
    $maxBreakdown = !empty($breakdown) ? max(array_column($breakdown, 'count')) : 0;
    $periodDays = $this->periodDays;
@endphp

<div class="space-y-4">

    {{-- ───────── Period switcher (для funnel / heatmap) ─────────
         Сохраняется в URL ?period=N (preset) или ?from=Y-m-d&to=Y-m-d (custom). --}}
    @if($this->isPrivileged)
        @php
            $isCustom = $this->isCustomPeriod;
            $pickerOpen = $this->customPickerOpen;
        @endphp
        <div class="flex flex-wrap items-center gap-2 text-[12px]">
            <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">Период:</span>
            @foreach([1 => '1 дн.', 7 => '7 дн.', 30 => '30 дн.', 90 => '90 дн.'] as $d => $label)
                @php $active = ! $isCustom && $periodDays === $d; @endphp
                <button type="button" wire:click="setPeriod({{ $d }})"
                        class="px-2.5 py-1 rounded border text-[12px] transition-colors
                               {{ $active
                                   ? 'border-sky-500 bg-sky-50 text-sky-800 font-semibold'
                                   : 'border-border bg-surface text-fg-2 hover:bg-surface-2' }}">
                    {{ $label }}
                </button>
            @endforeach

            {{-- Custom period chip — раскрывает inline picker --}}
            <button type="button" wire:click="toggleCustomPicker"
                    class="px-2.5 py-1 rounded border text-[12px] transition-colors
                           {{ $isCustom
                               ? 'border-sky-500 bg-sky-50 text-sky-800 font-semibold'
                               : 'border-border bg-surface text-fg-2 hover:bg-surface-2' }}">
                {{ $isCustom ? $this->periodLabel : 'период…' }}
            </button>

            @if($isCustom)
                <button type="button" wire:click="clearCustomPeriod"
                        class="text-[11px] text-fg-3 hover:text-fg-1 underline decoration-dotted"
                        title="Вернуться к preset-периоду">сбросить</button>
            @endif
        </div>

        @if($pickerOpen)
            <div class="ds-card p-3 flex flex-wrap items-end gap-2 text-[12px]">
                <label class="flex flex-col gap-1">
                    <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">С</span>
                    <input type="date" wire:model="customFrom"
                           class="px-2 py-1 border border-border rounded bg-surface text-fg-1 text-[12.5px] tnum"
                           max="{{ now()->format('Y-m-d') }}">
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">По</span>
                    <input type="date" wire:model="customTo"
                           class="px-2 py-1 border border-border rounded bg-surface text-fg-1 text-[12.5px] tnum"
                           max="{{ now()->format('Y-m-d') }}">
                </label>
                <button type="button" wire:click="applyCustomPeriod"
                        class="px-3 py-1 rounded border border-sky-500 bg-sky-50 text-sky-800 font-semibold text-[12px] hover:bg-sky-100">
                    Применить
                </button>
                <button type="button" wire:click="toggleCustomPicker"
                        class="px-2.5 py-1 rounded border border-border bg-surface text-fg-2 hover:bg-surface-2 text-[12px]">
                    Отмена
                </button>
            </div>
        @endif
    @endif

    {{-- ───────── Attention strip (Phase 1.11, Foundation §5.3) ───────── --}}
    @php
        $overdue = $counts['overdue'] ?? 0;
        $dueToday = $counts['dueToday'] ?? 0;
        $poolHrefOverdue = route('requests.index', ['bucket' => 'overdue']);
    @endphp
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <a href="{{ $poolHrefOverdue }}"
           class="ds-card p-4 flex items-center gap-4 transition-colors
                  {{ $overdue > 0 ? 'bg-[var(--red-50)] border-[var(--red-300)] hover:bg-[var(--red-100)]' : '' }}">
            <div class="flex-1">
                <div class="text-[10.5px] uppercase tracking-wider font-semibold {{ $overdue > 0 ? 'text-[var(--red-700)]' : 'text-fg-3' }}">
                    Просрочено
                </div>
                <div class="text-[28px] leading-none font-semibold mt-2 mono tnum {{ $overdue > 0 ? 'text-[var(--red-700)]' : 'text-fg-1' }}">
                    {{ $overdue }}
                </div>
                <div class="text-[11.5px] text-fg-3 mt-1">
                    {{ $overdue > 0 ? 'Дедлайн прошёл — открыть пул' : 'Все дедлайны соблюдены' }}
                </div>
            </div>
            <div class="text-[28px] {{ $overdue > 0 ? 'text-[var(--red-500)]' : 'text-[var(--fg-4)]' }}">⚡</div>
        </a>
        <div class="ds-card p-4 flex items-center gap-4 {{ $dueToday > 0 ? 'bg-[var(--amber-50)] border-[var(--amber-700)]/30' : '' }}">
            <div class="flex-1">
                <div class="text-[10.5px] uppercase tracking-wider font-semibold {{ $dueToday > 0 ? 'text-[var(--amber-700)]' : 'text-fg-3' }}">
                    Дедлайн сегодня
                </div>
                <div class="text-[28px] leading-none font-semibold mt-2 mono tnum {{ $dueToday > 0 ? 'text-[var(--amber-700)]' : 'text-fg-1' }}">
                    {{ $dueToday }}
                </div>
                <div class="text-[11.5px] text-fg-3 mt-1">
                    Заявки, у которых attention_required_at сегодня
                </div>
            </div>
            <div class="text-[28px] {{ $dueToday > 0 ? 'text-[var(--amber-700)]' : 'text-[var(--fg-4)]' }}">⏰</div>
        </div>
    </div>

    {{-- ───────── KPI strip (6 tiles) ───────── --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">

        <div class="ds-card p-4">
            <div class="text-[10.5px] uppercase tracking-wider font-semibold text-fg-3">{{ $this->isPrivileged ? 'Всего заявок' : 'Моих заявок' }}</div>
            <div class="text-[28px] leading-none font-semibold text-fg-1 mt-2 mono tnum">{{ $counts['total'] }}</div>
        </div>

        <div class="ds-card p-4">
            <div class="text-[10.5px] uppercase tracking-wider font-semibold text-fg-3">Новые</div>
            <div class="text-[28px] leading-none font-semibold mt-2 mono tnum {{ $counts['new'] > 0 ? 'text-amber-700' : 'text-fg-1' }}">{{ $counts['new'] }}</div>
        </div>

        <div class="ds-card p-4">
            <div class="text-[10.5px] uppercase tracking-wider font-semibold text-fg-3">В работе</div>
            <div class="text-[28px] leading-none font-semibold mt-2 mono tnum text-sky-700">{{ $counts['assigned'] }}</div>
        </div>

        @if($this->isPrivileged)
            <a href="{{ route('requests.index', ['scope' => 'all', 'unassigned' => 1, 'bucket' => 'all']) }}" wire:navigate
               class="ds-card p-4 block transition-colors hover:border-[var(--accent)] {{ $counts['unassigned'] > 0 ? 'border-red-300' : '' }}"
               title="Открыть «Заявки» с фильтром «Нераспределённые»">
                <div class="text-[10.5px] uppercase tracking-wider font-semibold {{ $counts['unassigned'] > 0 ? 'text-red-700' : 'text-fg-3' }}">Не назначено</div>
                <div class="text-[28px] leading-none font-semibold mt-2 mono tnum {{ $counts['unassigned'] > 0 ? 'text-red-700' : 'text-fg-1' }}">{{ $counts['unassigned'] }}</div>
            </a>
        @endif

        <div class="ds-card p-4">
            <div class="text-[10.5px] uppercase tracking-wider font-semibold text-fg-3">За 24 часа</div>
            <div class="text-[28px] leading-none font-semibold text-fg-1 mt-2 mono tnum">{{ $counts['today'] }}</div>
        </div>

        <div class="ds-card p-4">
            <div class="text-[10.5px] uppercase tracking-wider font-semibold text-fg-3">За 7 дней</div>
            <div class="text-[28px] leading-none font-semibold text-fg-1 mt-2 mono tnum">{{ $counts['week'] }}</div>
        </div>
    </div>

    {{-- ───────── Обновления системы (для всех ролей) ───────── --}}
    @if($this->latestUpdates->isNotEmpty())
        <div class="ds-card">
            <div class="ds-card-header">
                <h3>Обновления</h3>
                <span class="flex-1"></span>
                <a href="{{ route('updates.index') }}" class="text-[12px] text-sky-700 hover:underline">Все обновления →</a>
            </div>
            <div class="ds-card-body">
                <ul class="space-y-3 text-[12.5px]">
                    @foreach($this->latestUpdates as $upd)
                        <li wire:key="dash-upd-{{ $upd->id }}">
                            <a href="{{ route('updates.index') }}" class="text-fg-1 font-medium hover:text-sky-700 hover:underline">{{ $upd->title }}</a>
                            <div class="text-fg-3 mt-0.5" style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">{{ $upd->previewText(180) }}</div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    {{-- ───────── Funnel + conversion (за период) ─────────
         received → quoted → won/lost.
         quote_rate = quoted/received, conversion = won/(won+lost). --}}
    @if($this->isPrivileged)
        @php
            $f = $this->funnel;
            $maxStage = max($f['received'], $f['quoted'], $f['won'] + $f['lost'], 1);
            // ширина «бара» в процентах относительно received (worst case 100%)
            $w = fn ($v) => $maxStage > 0 ? max(2, round($v * 100 / $maxStage)) : 0;
        @endphp
        <div class="ds-card">
            <div class="ds-card-header">
                <h3>Воронка · {{ $this->periodLabel }}</h3>
                <span class="flex-1"></span>
                <span class="text-[11.5px] text-fg-3">
                    quote-rate
                    <span class="mono tnum font-semibold {{ $f['quote_rate'] === null ? 'text-fg-3' : ($f['quote_rate'] >= 50 ? 'text-emerald-700' : ($f['quote_rate'] >= 25 ? 'text-amber-700' : 'text-red-700')) }}">{{ $f['quote_rate'] !== null ? $f['quote_rate'] . '%' : '—' }}</span>
                    · conversion
                    <span class="mono tnum font-semibold {{ $f['conversion'] === null ? 'text-fg-3' : ($f['conversion'] >= 60 ? 'text-emerald-700' : ($f['conversion'] >= 30 ? 'text-amber-700' : 'text-red-700')) }}">{{ $f['conversion'] !== null ? $f['conversion'] . '%' : '—' }}</span>
                </span>
            </div>
            <div class="ds-card-body">
                <div class="space-y-2 text-[12.5px]">
                    {{-- Received --}}
                    <div class="flex items-center gap-3">
                        <div class="w-28 shrink-0 text-fg-2 uppercase text-[10.5px] tracking-wider">Получено</div>
                        <div class="flex-1 h-7 rounded bg-neutral-100 relative overflow-hidden">
                            <div class="h-full bg-sky-200" style="width: {{ $w($f['received']) }}%"></div>
                        </div>
                        <div class="w-16 text-right mono tnum text-fg-1 font-semibold">{{ $f['received'] }}</div>
                    </div>
                    {{-- Quoted --}}
                    <div class="flex items-center gap-3">
                        <div class="w-28 shrink-0 text-fg-2 uppercase text-[10.5px] tracking-wider">КП отправлено</div>
                        <div class="flex-1 h-7 rounded bg-neutral-100 relative overflow-hidden">
                            <div class="h-full bg-sky-500" style="width: {{ $w($f['quoted']) }}%"></div>
                        </div>
                        <div class="w-16 text-right mono tnum text-fg-1 font-semibold">{{ $f['quoted'] }}</div>
                    </div>
                    {{-- Won / Lost — две полосы рядом, значения на самой шкале,
                         справа — ВСЕГО закрыто (won+lost). --}}
                    <div class="flex items-center gap-3">
                        <div class="w-28 shrink-0 text-fg-2 uppercase text-[10.5px] tracking-wider">Закрыто</div>
                        <div class="flex-1 h-7 rounded bg-neutral-100 relative overflow-hidden flex">
                            <div class="h-full bg-emerald-500 flex items-center justify-center overflow-hidden" style="width: {{ $w($f['won']) }}%" title="Закрыто-выиграно: {{ $f['won'] }}">
                                @if($f['won'] > 0)
                                    <span class="mono tnum text-[10.5px] font-semibold text-white" style="text-shadow:0 1px 1.5px rgba(0,0,0,.4)">{{ $f['won'] }}</span>
                                @endif
                            </div>
                            <div class="h-full bg-red-400 flex items-center justify-center overflow-hidden" style="width: {{ $w($f['lost']) }}%" title="Закрыто-проиграно: {{ $f['lost'] }}">
                                @if($f['lost'] > 0)
                                    <span class="mono tnum text-[10.5px] font-semibold text-white" style="text-shadow:0 1px 1.5px rgba(0,0,0,.4)">{{ $f['lost'] }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="w-16 text-right mono tnum text-fg-1 font-semibold" title="Всего закрыто (выиграно + проиграно)">{{ $f['won'] + $f['lost'] }}</div>
                    </div>
                </div>
                <div class="text-[10.5px] text-fg-4 mt-3">
                    Получено = заявки, у которых created_at в окне. Quoted/Won/Lost = переходы в этот статус (request_state_changes) в окне. quote-rate = Quoted/Получено. conversion = Won/(Won+Lost).
                </div>
            </div>
        </div>
    @endif

    {{-- ───────── Two-col content ───────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Left wide --}}
        <div class="lg:col-span-2 space-y-4">

            @if($this->isPrivileged)
                {{-- ───────── Timeseries inflow по дням ─────────
                     Линейный SVG-чарт: ось X — дни, ось Y — кол-во заявок.
                     Три серии: personal (личные ящики менеджеров),
                     shared (общая почта — info@), total (включая ручные
                     заявки + manual). Источник — Dashboard::
                     requestInflowTimeseries(). Без JS-библиотек, чистый SVG. --}}
                @php
                    $ts = $this->requestInflowTimeseries;
                    $tsPoints = $ts['points'];
                    $tsTotals = $ts['totals'];
                    $tsMax = max(1, $ts['max']); // защита от деления на 0
                    $tsCount = count($tsPoints);
                    // Геометрия SVG. ViewBox делаем гибким по числу точек.
                    $svgW = 880;
                    $svgH = 220;
                    $padL = 36;   // под Y-axis подписи
                    $padR = 12;
                    $padT = 12;
                    $padB = 26;   // под X-axis подписи
                    $plotW = $svgW - $padL - $padR;
                    $plotH = $svgH - $padT - $padB;
                    // X-координата i-той точки. При count=1 — центр; иначе span.
                    $xAt = function (int $i) use ($padL, $plotW, $tsCount) {
                        if ($tsCount <= 1) {
                            return $padL + $plotW / 2;
                        }
                        return $padL + ($i / ($tsCount - 1)) * $plotW;
                    };
                    $yAt = function (int $v) use ($padT, $plotH, $tsMax) {
                        return $padT + $plotH - ($v / $tsMax) * $plotH;
                    };
                    // Построить SVG-path "M x0,y0 L x1,y1 L x2,y2 ..." по ряду значений.
                    $buildPath = function (string $key) use ($tsPoints, $xAt, $yAt) {
                        $segs = [];
                        foreach ($tsPoints as $i => $p) {
                            $cmd = $i === 0 ? 'M' : 'L';
                            $segs[] = $cmd . round($xAt($i), 1) . ',' . round($yAt((int) $p[$key]), 1);
                        }
                        return implode(' ', $segs);
                    };
                    // Сколько label'ов на оси X помещается без overlap. Цель ~8.
                    $xLabelStep = max(1, (int) ceil($tsCount / 8));
                    // Y-axis ticks — 4 уровня.
                    $yTicks = [0, (int) round($tsMax * 0.25), (int) round($tsMax * 0.5), (int) round($tsMax * 0.75), $tsMax];
                @endphp
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Поток заявок · по дням · {{ $this->periodLabel }}</h3>
                        <span class="flex-1"></span>
                        {{-- Legend --}}
                        <span class="inline-flex items-center gap-1 text-[11.5px] text-fg-3">
                            <span class="inline-block w-3 h-[2px]" style="background:#0284c7;"></span>
                            Личные <span class="mono tnum text-fg-1 font-semibold">{{ $tsTotals['personal'] }}</span>
                        </span>
                        <span class="inline-flex items-center gap-1 text-[11.5px] text-fg-3 ml-3">
                            <span class="inline-block w-3 h-[2px]" style="background:#059669;"></span>
                            Общая <span class="mono tnum text-fg-1 font-semibold">{{ $tsTotals['shared'] }}</span>
                        </span>
                        <span class="inline-flex items-center gap-1 text-[11.5px] text-fg-3 ml-3">
                            <span class="inline-block w-3 h-[2px]" style="background:#111827;"></span>
                            Всего <span class="mono tnum text-fg-1 font-semibold">{{ $tsTotals['total'] }}</span>
                        </span>
                        <span class="inline-flex items-center gap-1 text-[11.5px] text-fg-3 ml-3">
                            <span class="inline-block w-3 h-[2px]" style="background:#d97706;"></span>
                            Успешно закрыто <span class="mono tnum text-fg-1 font-semibold">{{ $tsTotals['won'] }}</span>
                        </span>
                    </div>
                    <div class="ds-card-body">
                        @if($tsCount === 0 || $tsTotals['total'] === 0)
                            <div class="text-center text-fg-3 py-8 text-[13px]">
                                За выбранный период заявок не было.
                            </div>
                        @else
                            <svg viewBox="0 0 {{ $svgW }} {{ $svgH }}"
                                 xmlns="http://www.w3.org/2000/svg"
                                 preserveAspectRatio="xMidYMid meet"
                                 style="width:100%;height:auto;max-height:260px;font-family:var(--font-sans);font-size:10px;">
                                {{-- Y-axis grid lines + labels --}}
                                @foreach($yTicks as $tick)
                                    @php $ty = $yAt((int) $tick); @endphp
                                    <line x1="{{ $padL }}" x2="{{ $svgW - $padR }}"
                                          y1="{{ round($ty, 1) }}" y2="{{ round($ty, 1) }}"
                                          stroke="#e5e7eb" stroke-width="1" />
                                    <text x="{{ $padL - 6 }}" y="{{ round($ty + 3, 1) }}"
                                          text-anchor="end" fill="#9ca3af" font-size="10">{{ $tick }}</text>
                                @endforeach

                                {{-- X-axis labels (выборочно) --}}
                                @foreach($tsPoints as $i => $p)
                                    @if($i % $xLabelStep === 0 || $i === $tsCount - 1)
                                        <text x="{{ round($xAt($i), 1) }}" y="{{ $svgH - $padB + 14 }}"
                                              text-anchor="middle" fill="#6b7280" font-size="10">{{ $p['label'] }}</text>
                                    @endif
                                @endforeach

                                {{-- Series: shared (общая почта · emerald) --}}
                                <path d="{{ $buildPath('shared') }}"
                                      fill="none" stroke="#059669" stroke-width="2"
                                      stroke-linecap="round" stroke-linejoin="round" />

                                {{-- Series: personal (личные · sky) --}}
                                <path d="{{ $buildPath('personal') }}"
                                      fill="none" stroke="#0284c7" stroke-width="2"
                                      stroke-linecap="round" stroke-linejoin="round" />

                                {{-- Series: total (всего · neutral-900 + dashed) --}}
                                <path d="{{ $buildPath('total') }}"
                                      fill="none" stroke="#111827" stroke-width="1.5"
                                      stroke-dasharray="3 3"
                                      stroke-linecap="round" stroke-linejoin="round" />

                                {{-- Series: won (успешно закрыто · amber) --}}
                                <path d="{{ $buildPath('won') }}"
                                      fill="none" stroke="#d97706" stroke-width="2"
                                      stroke-linecap="round" stroke-linejoin="round" />

                                {{-- Точки + hover-tooltip через native <title> --}}
                                @foreach($tsPoints as $i => $p)
                                    @php $cx = round($xAt($i), 1); @endphp
                                    @if($p['shared'] > 0)
                                        <circle cx="{{ $cx }}" cy="{{ round($yAt((int) $p['shared']), 1) }}"
                                                r="2.5" fill="#059669">
                                            <title>{{ $p['label'] }} · общая: {{ $p['shared'] }}</title>
                                        </circle>
                                    @endif
                                    @if($p['personal'] > 0)
                                        <circle cx="{{ $cx }}" cy="{{ round($yAt((int) $p['personal']), 1) }}"
                                                r="2.5" fill="#0284c7">
                                            <title>{{ $p['label'] }} · личные: {{ $p['personal'] }}</title>
                                        </circle>
                                    @endif
                                    @if($p['total'] > 0)
                                        <circle cx="{{ $cx }}" cy="{{ round($yAt((int) $p['total']), 1) }}"
                                                r="2" fill="#111827" fill-opacity="0.55">
                                            <title>{{ $p['label'] }} · всего: {{ $p['total'] }}{{ ($p['total'] !== $p['personal'] + $p['shared']) ? ' (вручную: ' . ($p['total'] - $p['personal'] - $p['shared']) . ')' : '' }}</title>
                                        </circle>
                                    @endif
                                    @if($p['won'] > 0)
                                        <circle cx="{{ $cx }}" cy="{{ round($yAt((int) $p['won']), 1) }}"
                                                r="2.5" fill="#d97706">
                                            <title>{{ $p['label'] }} · успешно закрыто: {{ $p['won'] }}</title>
                                        </circle>
                                    @endif
                                @endforeach
                            </svg>
                        @endif
                    </div>
                </div>

                {{-- ───────── Менеджеры: динамика закрытых + Успех/Потеря ─────────
                     Компактные виджеты; полная версия — раздел «Аналитика». --}}
                @if($this->isPrivileged)
                    @php
                        $mdyn = $this->managerClosedDynamics;
                        $mseries = $mdyn['series'];
                        $mlabels = $mdyn['labels'];
                        $mn = count($mlabels);
                        $mmax = max(1, (int) $mdyn['max']);
                        $mW = 900; $mH = 220; $mpadL = 26; $mpadR = 10; $mpadT = 10; $mpadB = 22;
                        $mxAt = function ($i) use ($mW, $mpadL, $mpadR, $mn) {
                            if ($mn <= 1) return $mpadL;
                            return $mpadL + ($i * ($mW - $mpadL - $mpadR) / ($mn - 1));
                        };
                        $myAt = function ($v) use ($mH, $mpadT, $mpadB, $mmax) {
                            return $mH - $mpadB - ($v * ($mH - $mpadT - $mpadB) / $mmax);
                        };
                        $mPath = function ($points) use ($mxAt, $myAt) {
                            $d = '';
                            foreach ($points as $i => $v) { $d .= ($i === 0 ? 'M' : 'L') . round($mxAt($i), 1) . ' ' . round($myAt($v), 1) . ' '; }
                            return trim($d);
                        };
                        $mGrand = array_sum(array_map(fn ($s) => $s['total'], $mseries));
                        $mStep = max(1, (int) ceil($mn / 12));
                        $wl = $this->managerWonLost;
                    @endphp
                    <div class="ds-card">
                        <div class="ds-card-header">
                            <h3>Менеджеры · динамика закрытых</h3>
                            <span class="text-[12px] text-fg-3 ml-2">won+lost по дате закрытия · {{ $this->periodLabel }}</span>
                            <span class="flex-1"></span>
                            <a href="{{ route('analytics.index') }}" wire:navigate class="text-[12px] text-sky-700 hover:underline">Аналитика →</a>
                        </div>
                        <div class="ds-card-body">
                            @if($mGrand === 0)
                                <div class="text-center text-fg-3 py-6 text-[13px]">За период закрытых заявок нет.</div>
                            @else
                                <svg viewBox="0 0 {{ $mW }} {{ $mH }}" xmlns="http://www.w3.org/2000/svg"
                                     preserveAspectRatio="xMidYMid meet"
                                     style="width:100%;height:auto;max-height:240px;font-family:var(--font-sans);font-size:10px;">
                                    @foreach([0, (int) round($mmax / 2), $mmax] as $tick)
                                        @php $ty = $myAt((int) $tick); @endphp
                                        <line x1="{{ $mpadL }}" x2="{{ $mW - $mpadR }}" y1="{{ round($ty, 1) }}" y2="{{ round($ty, 1) }}" stroke="#e5e7eb" stroke-width="1" />
                                        <text x="{{ $mpadL - 5 }}" y="{{ round($ty + 3, 1) }}" text-anchor="end" fill="#9ca3af" font-size="10">{{ $tick }}</text>
                                    @endforeach
                                    @foreach($mlabels as $i => $lab)
                                        @if($i % $mStep === 0 || $i === $mn - 1)
                                            <text x="{{ round($mxAt($i), 1) }}" y="{{ $mH - $mpadB + 13 }}" text-anchor="middle" fill="#6b7280" font-size="10">{{ $lab }}</text>
                                        @endif
                                    @endforeach
                                    @foreach($mseries as $s)
                                        @if($s['total'] > 0)
                                            <path d="{{ $mPath($s['points']) }}" fill="none" stroke="{{ $s['color'] }}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                        @endif
                                    @endforeach
                                </svg>
                                <div class="flex flex-wrap gap-x-3 gap-y-1 mt-2 text-[11px]">
                                    @foreach($mseries as $s)
                                        @if($s['total'] > 0)
                                            <span class="inline-flex items-center gap-1.5">
                                                <span class="inline-block w-3 h-[3px] rounded-full" style="background: {{ $s['color'] }}"></span>
                                                <span class="text-fg-1">{{ $s['name'] }}</span>
                                                <span class="text-fg-3">{{ $s['total'] }} (<span class="text-emerald-700">{{ $s['won'] }}</span>/<span class="text-red-700">{{ $s['lost'] }}</span>)</span>
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif

                            @php $wlClosed = array_values(array_filter($wl, fn ($r) => ($r['won'] + $r['lost']) > 0)); @endphp
                            @if(! empty($wlClosed))
                                <div class="mt-4 overflow-x-auto">
                                    <div class="text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1.5">Успех / Потеря по менеджерам · заявки, созданные за период (закрытые)</div>
                                    <table class="w-full text-[12px]">
                                        <thead class="text-fg-3 text-[10px] uppercase tracking-wider border-b border-border">
                                            <tr>
                                                <th class="px-2 py-1.5 text-left">Менеджер</th>
                                                <th class="px-2 py-1.5 text-right">Закрыто</th>
                                                <th class="px-2 py-1.5 text-right text-emerald-700">Успех</th>
                                                <th class="px-2 py-1.5 text-right text-red-700">Потеря</th>
                                                <th class="px-2 py-1.5 text-right">Win-rate</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($wlClosed as $row)
                                                <tr class="border-b border-border-subtle last:border-b-0">
                                                    <td class="px-2 py-1 text-fg-1">{{ $row['name'] }}</td>
                                                    <td class="px-2 py-1 text-right mono text-fg-1">{{ $row['won'] + $row['lost'] }}</td>
                                                    <td class="px-2 py-1 text-right mono text-emerald-700">{{ $row['won'] }}</td>
                                                    <td class="px-2 py-1 text-right mono text-red-700">{{ $row['lost'] }}</td>
                                                    <td class="px-2 py-1 text-right mono {{ $row['win_rate'] === null ? 'text-fg-4' : ($row['win_rate'] >= 50 ? 'text-emerald-700' : 'text-amber-700') }}">{{ $row['win_rate'] === null ? '—' : $row['win_rate'] . '%' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                {{-- ───────── Heatmap inflow-by-hour (weekday × hour) ─────────
                     7 строк (Пн..Вс) × 24 колонки (часы Europe/Moscow).
                     Интенсивность фона = count/max в палитре sky.
                     0 → bg-neutral-50, max → bg-sky-700. --}}
                @php
                    $hm = $this->inflowHeatmap;
                    $hmMatrix = $hm['matrix'];
                    $hmMax = $hm['max'];
                    $hmTotal = $hm['total'];
                    $weekdays = [1=>'Пн', 2=>'Вт', 3=>'Ср', 4=>'Чт', 5=>'Пт', 6=>'Сб', 7=>'Вс'];
                    // палитра sky: 0,1..10 → bg-class + text-color
                    $heatCell = function (int $v, int $max) {
                        if ($v === 0 || $max === 0) {
                            return ['bg' => 'background:#f5f6f8', 'text' => '#c5cad3'];
                        }
                        // линейная шкала, 5 уровней + max
                        $ratio = $v / $max;
                        if ($ratio < 0.10)  return ['bg' => 'background:#e0f2fe', 'text' => '#0c4a6e'];
                        if ($ratio < 0.25)  return ['bg' => 'background:#bae6fd', 'text' => '#0c4a6e'];
                        if ($ratio < 0.50)  return ['bg' => 'background:#7dd3fc', 'text' => '#0c4a6e'];
                        if ($ratio < 0.75)  return ['bg' => 'background:#38bdf8', 'text' => 'white'];
                        return ['bg' => 'background:#0284c7', 'text' => 'white'];
                    };
                @endphp
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Поток заявок · по часам · {{ $this->periodLabel }}</h3>
                        <span class="flex-1"></span>
                        <span class="text-[11.5px] text-fg-3">всего <span class="mono tnum text-fg-1 font-semibold">{{ $hmTotal }}</span> · максимум <span class="mono tnum text-fg-1 font-semibold">{{ $hmMax }}</span>/час</span>
                    </div>
                    <div class="ds-card-body overflow-x-auto">
                        <table class="border-collapse text-[10px] mono" style="table-layout: fixed;">
                            <thead>
                                <tr>
                                    <th class="w-7"></th>
                                    @for($h = 0; $h < 24; $h++)
                                        <th class="text-fg-3 font-normal" style="width: 22px; padding: 0;">
                                            {{ $h % 3 === 0 ? sprintf('%02d', $h) : '' }}
                                        </th>
                                    @endfor
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($weekdays as $d => $label)
                                    <tr>
                                        <td class="text-fg-3 pr-1 text-right">{{ $label }}</td>
                                        @for($h = 0; $h < 24; $h++)
                                            @php
                                                $v = $hmMatrix[$d][$h] ?? 0;
                                                $style = $heatCell($v, $hmMax);
                                            @endphp
                                            <td class="text-center" style="width: 22px; height: 20px; {{ $style['bg'] }}; color: {{ $style['text'] }}; border: 1px solid white;"
                                                title="{{ $label }} {{ sprintf('%02d:00', $h) }} — {{ $v }} заявок">
                                                {{ $v > 0 ? $v : '' }}
                                            </td>
                                        @endfor
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <div class="text-[10.5px] text-fg-4 mt-2">
                            Часы Europe/Moscow. Темнее = больше заявок в это время. Помогает планировать дежурства и нагрузку.
                        </div>
                    </div>
                </div>

                {{-- AI-обработка входящей почты. Числа стыкуются со страницей:
                     «Создано заявок» = funnel['received'] (тот же период и запрос
                     Request::created). Категории — про ПИСЬМА, явно отделены. --}}
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>AI-обработка входящей почты · {{ $this->periodLabel }}</h3>
                        <span class="flex-1"></span>
                        <span class="text-[11.5px] text-fg-3" title="Доля входящих писем, которым AI проставил категорию">
                            покрытие AI <span class="mono tnum text-fg-1 font-semibold">{{ $mail['percent'] }}%</span>
                        </span>
                    </div>
                    <div class="ds-card-body space-y-3">
                        {{-- Два опорных числа: письма (вход) и заявки (выход) --}}
                        <div class="grid grid-cols-2 gap-2 text-center">
                            <div class="p-2.5 rounded-md border border-border bg-app">
                                <div class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Проанализировано писем</div>
                                <div class="text-[20px] font-bold mono tnum text-fg-1">{{ $mail['analyzed'] }}</div>
                                <div class="text-[10.5px] text-fg-4 mt-0.5">входящих за период</div>
                            </div>
                            <div class="p-2.5 rounded-md border border-sky-200 bg-sky-50">
                                <div class="text-[10.5px] uppercase tracking-wider text-sky-700 font-semibold mb-1">Создано заявок</div>
                                <div class="text-[20px] font-bold mono tnum text-sky-800">{{ $mail['requests_created'] }}</div>
                                <div class="text-[10.5px] text-fg-4 mt-0.5">= «Получено» в воронке</div>
                            </div>
                        </div>

                        {{-- Разбивка ПИСЕМ по категориям + что с ними делаем --}}
                        <div>
                            <div class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold mb-1.5">
                                Что AI распознал в письмах
                            </div>
                            @if(empty($breakdown))
                                <div class="text-sm text-fg-3">Нет писем за период.</div>
                            @else
                                <div class="space-y-1.5">
                                    @foreach($breakdown as $row)
                                        <div class="flex items-center gap-3 text-[12.5px]">
                                            <div class="w-40 shrink-0">
                                                <div class="text-fg-2 truncate">{{ $row['label'] }}</div>
                                                <div class="text-[10.5px] text-fg-4 truncate">{{ $row['note'] }}</div>
                                            </div>
                                            <div class="flex-1 h-2.5 rounded-full bg-neutral-100 overflow-hidden">
                                                <div class="h-full bg-sky-500"
                                                     style="width: {{ $maxBreakdown > 0 ? round($row['count'] * 100 / $maxBreakdown) : 0 }}%"></div>
                                            </div>
                                            <div class="w-14 text-right text-fg-1 mono tnum">{{ $row['count'] }}</div>
                                        </div>
                                        {{-- Куда идут письма-запросы: открыли новую vs дополнили
                                             существующую (сумма = число писем-запросов). Отвечает
                                             на «а куда делись остальные письма». --}}
                                        @if($row['class'] === 'client_request')
                                            <div class="flex items-start gap-3 text-[11px] text-fg-4 pl-3 -mt-0.5">
                                                <div class="w-40 shrink-0">└ разбивка</div>
                                                <div class="flex-1">
                                                    <span class="mono tnum text-fg-2">{{ $mail['opened_new'] }}</span> открыли новую заявку
                                                    · <span class="mono tnum text-fg-2">{{ $mail['added_existing'] }}</span> дополнили существующую
                                                </div>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="text-[10.5px] text-fg-4">
                            Письма ≠ заявки, вычитать их друг из друга нельзя. Из {{ $mail['request_emails'] }} писем-запросов {{ $mail['opened_new'] }} открыли новую заявку, а {{ $mail['added_existing'] }} легли в уже существующую (клиент дополняет заказ в том же треде).
                            «Создано заявок» ({{ $mail['requests_created'] }}) = {{ $mail['opened_new'] }} из писем-запросов + {{ $mail['other_created'] }} открыты из писем других категорий (напр. клиент дописал новую позицию в старый тред → новая заявка). Это число совпадает с «Получено» в воронке.
                        </div>
                    </div>
                </div>

                {{-- ───────── Сложность активных заявок ───────── --}}
                @php
                    $cKpi = $this->complexityKpi;
                    $cBreakdown = $this->complexityBreakdown;
                    $cMaxTotal = collect($cBreakdown)->max('total') ?: 1;
                @endphp
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Сложность активных заявок</h3>
                        <span class="flex-1"></span>
                        <span class="text-[11.5px] text-fg-3">
                            всего: <span class="mono tnum text-fg-2">{{ $cKpi['total_active'] }}</span>
                            · сложных:
                            <span class="mono tnum text-amber-700 font-semibold">{{ $cKpi['hard'] }}</span>
                            + очень сложных:
                            <span class="mono tnum text-red-700 font-semibold">{{ $cKpi['very_hard'] }}</span>
                        </span>
                    </div>
                    <div class="ds-card-body space-y-3">
                        {{-- KPI карточки --}}
                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div class="p-2.5 rounded-md border border-border bg-app">
                                <div class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Всего активных</div>
                                <div class="text-[20px] font-bold mono tnum text-fg-1">{{ $cKpi['total_active'] }}</div>
                            </div>
                            <div class="p-2.5 rounded-md border border-amber-200 bg-amber-50">
                                <div class="text-[10.5px] uppercase tracking-wider text-amber-700 font-semibold mb-1">Сложных (hard)</div>
                                <div class="text-[20px] font-bold mono tnum text-amber-800">{{ $cKpi['hard'] }}</div>
                            </div>
                            <div class="p-2.5 rounded-md border border-red-200 bg-red-50">
                                <div class="text-[10.5px] uppercase tracking-wider text-red-700 font-semibold mb-1">Очень сложных</div>
                                <div class="text-[20px] font-bold mono tnum text-red-800">{{ $cKpi['very_hard'] }}</div>
                            </div>
                        </div>

                        {{-- Breakdown позиций по match_path × has_photo --}}
                        <div>
                            <div class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold mb-1.5">
                                Откуда приходят позиции (active)
                            </div>
                            <div class="space-y-1.5">
                                @foreach($cBreakdown as $row)
                                    @php
                                        $rowTone = match ($row['path']) {
                                            'internal_sku' => 'bg-emerald-500',
                                            'brand_article' => 'bg-sky-500',
                                            'name_match' => 'bg-amber-500',
                                            'manual' => 'bg-red-500',
                                            default => 'bg-neutral-400',
                                        };
                                    @endphp
                                    <div class="flex items-center gap-3 text-[12.5px]">
                                        <div class="w-48 shrink-0 text-fg-2 flex items-center gap-1.5 whitespace-nowrap">
                                            <span class="shrink-0">{{ $row['icon'] }}</span>
                                            <span class="shrink-0">{{ $row['label'] }}</span>
                                            <span class="text-fg-3 text-[10.5px] shrink-0">×{{ $row['weight'] }}</span>
                                        </div>
                                        <div class="flex-1 h-2.5 rounded-full bg-neutral-100 overflow-hidden">
                                            <div class="h-full {{ $rowTone }}"
                                                 style="width: {{ round($row['total'] * 100 / $cMaxTotal) }}%"></div>
                                        </div>
                                        <div class="w-16 text-right text-fg-1 mono tnum shrink-0">
                                            {{ $row['total'] }}
                                        </div>
                                        <div class="w-24 text-right text-[10.5px] text-fg-3 whitespace-nowrap shrink-0"
                                             title="С фото: {{ $row['with_photo'] }}; без фото: {{ $row['no_photo'] }}">
                                            <span class="text-emerald-700">📷 {{ $row['with_photo'] }}</span>
                                            <span class="mx-0.5">·</span>
                                            <span>{{ $row['no_photo'] }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="text-[10.5px] text-fg-4 mt-2 leading-relaxed">
                                Веса позиций задаются в Настройках (<code>complexity.weights.*</code>).
                                Score заявки = сумма весов её active-позиций; уровень — по порогам <code>complexity.thresholds</code>.
                                Цифры справа — «📷 с фото · без фото».
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ───────── Менеджеры: единая карточка с 4 режимами ─────────
                     Текущая загрузка (default) — снимок: активные/слжн/hard/всего/info@.
                     Сегодня / Вчера / Период — назначено за окно: количество,
                     из них info@, sparkline. РОПу видно как распределяются
                     заявки по менеджерам в данный период. --}}
                @php
                    $sparkMode = $this->sparklineMode;
                    $sparkLabel = $this->sparklineLabel;
                    $sparkPickerOpen = $this->sparklinePickerOpen;
                    $sparkIsCustom = $sparkMode === 'custom';
                    $isCurrentMode = $sparkMode === 'current';
                @endphp
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Менеджеры · {{ $sparkLabel }}</h3>
                        <span class="flex-1"></span>
                        <span class="flex items-center gap-1.5 text-[11.5px]">
                            @foreach([
                                'current'   => 'Текущая загрузка',
                                'today'     => 'Сегодня',
                                'yesterday' => 'Вчера',
                            ] as $modeKey => $modeLabel)
                                @php $active = ! $sparkIsCustom && $sparkMode === $modeKey; @endphp
                                <button type="button" wire:click="setSparklineMode('{{ $modeKey }}')"
                                        class="px-2 py-0.5 rounded border text-[11px] transition-colors
                                               {{ $active
                                                   ? 'border-sky-500 bg-sky-50 text-sky-800 font-semibold'
                                                   : 'border-border bg-surface text-fg-3 hover:bg-surface-2' }}">
                                    {{ $modeLabel }}
                                </button>
                            @endforeach
                            <button type="button" wire:click="toggleSparklinePicker"
                                    class="px-2 py-0.5 rounded border text-[11px] transition-colors
                                           {{ $sparkIsCustom
                                               ? 'border-sky-500 bg-sky-50 text-sky-800 font-semibold'
                                               : 'border-border bg-surface text-fg-3 hover:bg-surface-2' }}">
                                {{ $sparkIsCustom ? $sparkLabel : 'Период…' }}
                            </button>
                        </span>
                    </div>

                    @if($sparkPickerOpen)
                        <div class="px-[18px] py-2.5 flex flex-wrap items-end gap-2 text-[12px] border-b border-border-subtle bg-surface-2">
                            <label class="flex flex-col gap-1">
                                <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">С</span>
                                <input type="date" wire:model="sparklineFrom"
                                       class="px-2 py-1 border border-border rounded bg-surface text-fg-1 text-[12.5px] tnum"
                                       max="{{ now()->format('Y-m-d') }}">
                            </label>
                            <label class="flex flex-col gap-1">
                                <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">По</span>
                                <input type="date" wire:model="sparklineTo"
                                       class="px-2 py-1 border border-border rounded bg-surface text-fg-1 text-[12.5px] tnum"
                                       max="{{ now()->format('Y-m-d') }}">
                            </label>
                            <button type="button" wire:click="applySparklinePeriod"
                                    class="px-3 py-1 rounded border border-sky-500 bg-sky-50 text-sky-800 font-semibold text-[12px] hover:bg-sky-100">
                                Применить
                            </button>
                            <button type="button" wire:click="toggleSparklinePicker"
                                    class="px-2.5 py-1 rounded border border-border bg-surface text-fg-2 hover:bg-surface-2 text-[12px]">
                                Отмена
                            </button>
                        </div>
                    @endif

                    <div class="ds-card-body p-0">
                        @if($isCurrentMode)
                            {{-- Режим «Текущая загрузка» — снимок --}}
                            @php $currentLoad = $this->managersCurrentLoad; @endphp
                            @if(empty($currentLoad))
                                <div class="px-[18px] py-4 text-sm text-fg-3">В системе нет пользователей с ролью «менеджер».</div>
                            @else
                                <table class="w-full text-[12.5px] border-collapse">
                                    <thead>
                                        <tr class="text-[10.5px] uppercase tracking-wider font-semibold text-fg-3 border-b border-border-subtle">
                                            <th class="text-left px-[18px] py-2">Менеджер</th>
                                            <th class="text-right px-2 py-2" title="Открытых заявок прямо сейчас">активные</th>
                                            <th class="text-right px-2 py-2" title="Суммарный complexity_score активных">слжн</th>
                                            <th class="text-right px-2 py-2" title="Hard + very_hard в работе">hard</th>
                                            <th class="text-right px-2 py-2" title="Всего заявок за всё время (включая закрытые)">всего ист.</th>
                                            <th class="text-right px-[18px] py-2" title="Сколько из «всего» пришло через info@myzip.ru">info@</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($currentLoad as $m)
                                            <tr class="border-b border-border-subtle last:border-b-0">
                                                <td class="px-[18px] py-2">
                                                    <div class="flex items-center gap-2.5">
                                                        @if(!empty($m['avatar_url']))
                                                            <img src="{{ $m['avatar_url'] }}" alt="" class="rounded-full shrink-0" style="width:30px;height:30px;object-fit:cover;">
                                                        @else
                                                            @php $mInit = collect(preg_split('/\s+/u', trim((string) $m['name'])))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->implode(''); @endphp
                                                            <span class="inline-flex items-center justify-center rounded-full bg-[var(--neutral-200)] text-fg-2 font-semibold text-[11px] shrink-0" style="width:30px;height:30px;">{{ $mInit ?: '?' }}</span>
                                                        @endif
                                                        <div class="min-w-0">
                                                            <div class="text-fg-1">{{ $m['name'] }}</div>
                                                            <div class="text-[11.5px] text-fg-3 mono truncate">{{ $m['email'] }}</div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-2 py-2 text-right mono tnum {{ $m['active'] > 0 ? 'text-fg-1 font-semibold' : 'text-fg-3' }}">{{ $m['active'] }}</td>
                                                <td class="px-2 py-2 text-right mono tnum {{ $m['active_complexity'] > 0 ? 'text-fg-1' : 'text-fg-3' }}">{{ $m['active_complexity'] }}</td>
                                                <td class="px-2 py-2 text-right mono tnum {{ $m['hard_count'] > 0 ? 'text-amber-700 font-semibold' : 'text-fg-3' }}">{{ $m['hard_count'] }}</td>
                                                <td class="px-2 py-2 text-right mono tnum {{ $m['total_all_time'] > 0 ? 'text-fg-2' : 'text-fg-3' }}">{{ $m['total_all_time'] }}</td>
                                                <td class="px-[18px] py-2 text-right mono tnum {{ $m['from_info_total'] > 0 ? 'text-fg-3' : 'text-fg-4' }}">{{ $m['from_info_total'] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        @else
                            {{-- Режим «Сегодня / Вчера / Период» — назначено за окно --}}
                            @php
                                $assigned = $this->managersAssignedInPeriod;
                                $sparkMax = 0;
                                foreach ($assigned as $row) {
                                    foreach ($row['points'] as $p) {
                                        if ($p > $sparkMax) $sparkMax = $p;
                                    }
                                }
                                $sparkMax = max(1, $sparkMax);
                                $renderSpark = function (array $points) use ($sparkMax): string {
                                    $W = 84; $H = 18; $pad = 1;
                                    $n = count($points);
                                    if ($n < 1) return '';
                                    if ($n === 1) {
                                        $v = $points[0];
                                        $cx = $W / 2;
                                        $cy = $H - $pad - ($v / $sparkMax) * ($H - 2 * $pad);
                                        return '<svg width="' . $W . '" height="' . $H . '" style="display:block">'
                                            . '<line x1="' . ($pad + 8) . '" y1="' . ($H - $pad) . '" x2="' . ($W - $pad - 8) . '" y2="' . ($H - $pad) . '" stroke="#cbd5e1" stroke-width="0.5"/>'
                                            . '<circle cx="' . round($cx, 1) . '" cy="' . round($cy, 1) . '" r="2.5" fill="#0284c7"/>'
                                            . '</svg>';
                                    }
                                    $stepX = ($W - 2 * $pad) / ($n - 1);
                                    $coords = [];
                                    foreach ($points as $i => $v) {
                                        $x = $pad + $i * $stepX;
                                        $y = $H - $pad - ($v / $sparkMax) * ($H - 2 * $pad);
                                        $coords[] = round($x, 1) . ',' . round($y, 1);
                                    }
                                    $line = implode(' ', $coords);
                                    $lastX = $pad + ($n - 1) * $stepX;
                                    $lastY = $H - $pad - (end($points) / $sparkMax) * ($H - 2 * $pad);
                                    return '<svg width="' . $W . '" height="' . $H . '" style="display:block">'
                                        . '<polyline fill="none" stroke="#0284c7" stroke-width="1.4" points="' . $line . '"/>'
                                        . '<circle cx="' . round($lastX, 1) . '" cy="' . round($lastY, 1) . '" r="2" fill="#0284c7"/>'
                                        . '</svg>';
                                };
                                $totalAssigned = array_sum(array_column($assigned, 'assigned'));
                            @endphp
                            @if(empty($assigned) || $totalAssigned === 0)
                                <div class="px-[18px] py-4 text-sm text-fg-3">
                                    @if($sparkIsCustom)
                                        Нет назначений за выбранный период ({{ $sparkLabel }}).
                                    @else
                                        Нет назначений {{ $sparkLabel }}.
                                    @endif
                                </div>
                            @else
                                <table class="w-full text-[12.5px] border-collapse">
                                    <thead>
                                        <tr class="text-[10.5px] uppercase tracking-wider font-semibold text-fg-3 border-b border-border-subtle">
                                            <th class="text-left px-[18px] py-2">Менеджер</th>
                                            <th class="text-right px-2 py-2" title="Назначений за выбранный период ({{ $sparkLabel }})">назначено</th>
                                            <th class="text-right px-2 py-2" title="Из них через info@myzip.ru в тот же период">info@</th>
                                            <th class="text-left px-[18px] py-2">поток</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($assigned as $m)
                                            <tr class="border-b border-border-subtle last:border-b-0">
                                                <td class="px-[18px] py-2">
                                                    <div class="flex items-center gap-2.5">
                                                        @if(!empty($m['avatar_url']))
                                                            <img src="{{ $m['avatar_url'] }}" alt="" class="rounded-full shrink-0" style="width:30px;height:30px;object-fit:cover;">
                                                        @else
                                                            @php $mInit = collect(preg_split('/\s+/u', trim((string) $m['name'])))->filter()->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->take(2)->implode(''); @endphp
                                                            <span class="inline-flex items-center justify-center rounded-full bg-[var(--neutral-200)] text-fg-2 font-semibold text-[11px] shrink-0" style="width:30px;height:30px;">{{ $mInit ?: '?' }}</span>
                                                        @endif
                                                        <div class="min-w-0">
                                                            <div class="text-fg-1">{{ $m['name'] }}</div>
                                                            <div class="text-[11.5px] text-fg-3 mono truncate">{{ $m['email'] }}</div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-2 py-2 text-right mono tnum {{ $m['assigned'] > 0 ? 'text-fg-1 font-semibold' : 'text-fg-3' }}">{{ $m['assigned'] }}</td>
                                                <td class="px-2 py-2 text-right mono tnum {{ $m['from_info_period'] > 0 ? 'text-fg-2' : 'text-fg-3' }}">{{ $m['from_info_period'] }}</td>
                                                <td class="px-[18px] py-2">{!! $renderSpark($m['points']) !!}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        @endif
                    </div>
                </div>

                {{-- ───────── Распределение текущих заявок по статусам ─────────
                     Круговая (donut) диаграмма активных заявок по статусам.
                     Фильтр по конкретному менеджеру или все. --}}
                @php $dist = $this->statusDistribution; @endphp
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Заявки по статусам</h3>
                        <span class="flex-1"></span>
                        <select wire:model.live="statusChartManagerId"
                                class="px-2 py-1 border border-border rounded bg-surface text-fg-1 text-[12px] max-w-[220px]">
                            <option value="0">Все менеджеры</option>
                            @foreach($this->statusChartManagers as $mgr)
                                <option value="{{ $mgr->id }}">{{ $mgr->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="ds-card-body">
                        @if($dist['total'] === 0)
                            <div class="py-8 text-center text-fg-3 text-[12.5px]">
                                Нет активных заявок для выбранного фильтра.
                            </div>
                        @else
                            @php
                                // Геометрия donut + выноски (leader lines) к подписям
                                // по бокам. Подписи разводим по вертикали, чтобы не
                                // налезали друг на друга (анти-коллизия в каждой
                                // половине отдельно).
                                $W = 540; $H = 260;
                                $cx = 270; $cy = 130; $r = 84; $innerR = 52;
                                $total = $dist['total'];
                                $single = count($dist['slices']) === 1;

                                $polar = function (float $deg, float $rad) use ($cx, $cy): array {
                                    $a = deg2rad($deg);
                                    return [round($cx + $rad * cos($a), 2), round($cy + $rad * sin($a), 2)];
                                };

                                $arcs = [];
                                $labels = [];
                                $angle = -90.0; // старт сверху
                                foreach ($dist['slices'] as $sl) {
                                    $sweep = $sl['count'] / $total * 360;
                                    $start = $angle;
                                    $end = $angle + $sweep;
                                    $mid = ($start + $end) / 2;
                                    $angle = $end;

                                    if ($single) {
                                        $arcs[] = ['full' => true, 'color' => $sl['color']];
                                    } else {
                                        [$x1, $y1] = $polar($start, $r);
                                        [$x2, $y2] = $polar($end, $r);
                                        $largeArc = $sweep > 180 ? 1 : 0;
                                        $arcs[] = [
                                            'full' => false,
                                            'color' => $sl['color'],
                                            'd' => "M {$cx} {$cy} L {$x1} {$y1} A {$r} {$r} 0 {$largeArc} 1 {$x2} {$y2} Z",
                                        ];
                                    }

                                    [$ax, $ay] = $polar($mid, $r);        // точка на кромке сектора
                                    $side = cos(deg2rad($mid)) >= 0 ? 'r' : 'l';
                                    $labels[] = [
                                        'slice' => $sl,
                                        'side' => $side,
                                        'ax' => $ax, 'ay' => $ay,
                                        'idealY' => $ay,
                                        'pct' => round($sl['count'] / $total * 100),
                                    ];
                                }

                                // Анти-коллизия подписей по вертикали в каждой половине.
                                $gap = 26; $topY = 18; $botY = $H - 18;
                                foreach (['l', 'r'] as $sideKey) {
                                    $idx = array_keys(array_filter($labels, fn ($l) => $l['side'] === $sideKey));
                                    usort($idx, fn ($a, $b) => $labels[$a]['idealY'] <=> $labels[$b]['idealY']);
                                    $prev = null;
                                    foreach ($idx as $i) {
                                        $y = $labels[$i]['idealY'];
                                        if ($prev !== null && $y < $prev + $gap) {
                                            $y = $prev + $gap;
                                        }
                                        $labels[$i]['labelY'] = $y;
                                        $prev = $y;
                                    }
                                    if (! empty($idx)) {
                                        $lastI = end($idx);
                                        $overflow = $labels[$lastI]['labelY'] - $botY;
                                        if ($overflow > 0) {
                                            foreach ($idx as $i) { $labels[$i]['labelY'] -= $overflow; }
                                        }
                                        $firstI = $idx[0];
                                        if ($labels[$firstI]['labelY'] < $topY) {
                                            $shift = $topY - $labels[$firstI]['labelY'];
                                            foreach ($idx as $i) { $labels[$i]['labelY'] += $shift; }
                                        }
                                    }
                                }

                                // X-координаты колонок выносок/подписей по сторонам.
                                $colX = ['l' => $cx - $r - 22, 'r' => $cx + $r + 22];
                                $tickX = ['l' => $cx - $r - 34, 'r' => $cx + $r + 34];
                                $textX = ['l' => $cx - $r - 40, 'r' => $cx + $r + 40];
                            @endphp
                            <svg viewBox="0 0 {{ $W }} {{ $H }}" class="w-full max-w-[540px] h-auto mx-auto block"
                                 xmlns="http://www.w3.org/2000/svg">
                                {{-- Секторы --}}
                                @foreach($arcs as $arc)
                                    @if($arc['full'])
                                        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" fill="{{ $arc['color'] }}"/>
                                    @else
                                        <path d="{{ $arc['d'] }}" fill="{{ $arc['color'] }}"
                                              stroke="var(--bg-surface)" stroke-width="1.5"/>
                                    @endif
                                @endforeach
                                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $innerR }}" fill="var(--bg-surface)"/>
                                <text x="{{ $cx }}" y="{{ $cy - 2 }}" text-anchor="middle"
                                      style="fill: var(--fg-1); font-size: 26px; font-weight: 700;">{{ $total }}</text>
                                <text x="{{ $cx }}" y="{{ $cy + 15 }}" text-anchor="middle"
                                      style="fill: var(--fg-3); font-size: 9.5px; letter-spacing: 0.06em;">заявок</text>

                                {{-- Выноски + подписи --}}
                                @foreach($labels as $lb)
                                    @php
                                        $side = $lb['side'];
                                        $ly = $lb['labelY'];
                                        $points = "{$lb['ax']},{$lb['ay']} {$colX[$side]},{$ly} {$tickX[$side]},{$ly}";
                                        $anchor = $side === 'r' ? 'start' : 'end';
                                    @endphp
                                    <polyline points="{{ $points }}" fill="none"
                                              stroke="{{ $lb['slice']['color'] }}" stroke-width="1.3"/>
                                    <circle cx="{{ $lb['ax'] }}" cy="{{ $lb['ay'] }}" r="2.4" fill="{{ $lb['slice']['color'] }}"/>
                                    <text x="{{ $textX[$side] }}" y="{{ $ly - 3 }}" text-anchor="{{ $anchor }}"
                                          style="fill: var(--fg-1); font-size: 11px;">{{ $lb['slice']['label'] }}</text>
                                    <text x="{{ $textX[$side] }}" y="{{ $ly + 9 }}" text-anchor="{{ $anchor }}"
                                          style="fill: var(--fg-3); font-size: 10px;">{{ $lb['slice']['count'] }} · {{ $lb['pct'] }}%</text>
                                @endforeach
                            </svg>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Последние заявки --}}
            <div class="ds-card">
                <div class="ds-card-header">
                    <h3>{{ $this->isPrivileged ? 'Последние заявки' : 'Мои последние заявки' }}</h3>
                    <span class="flex-1"></span>
                    <a href="{{ route('requests.index') }}" class="text-[12px] text-sky-700 hover:underline">все →</a>
                </div>
                <div class="p-0">
                    @if($this->recentRequests->isEmpty())
                        <div class="px-[18px] py-4 text-sm text-fg-3">Заявок ещё нет.</div>
                    @else
                        <ul class="text-[12.5px]">
                            @foreach($this->recentRequests as $r)
                                <li class="flex items-center gap-3 px-[18px] py-2.5 border-b border-border-subtle last:border-b-0 hover:bg-hover transition-colors">
                                    <a href="{{ route('requests.show', $r) }}" class="mono text-accent hover:underline shrink-0">{{ $r->internal_code }}</a>
                                    <span class="flex-1 truncate text-fg-1">{{ \Illuminate\Support\Str::limit((string) $r->subject, 70) ?: '(без темы)' }}</span>
                                    <span class="text-[11.5px] text-fg-3 truncate max-w-[200px] hidden md:inline">{{ $r->client_email }}</span>
                                    <span class="text-[11.5px] text-fg-2 whitespace-nowrap">{{ $r->assignedUser?->name ?? '—' }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        {{-- Right column --}}
        <div class="space-y-4">

            {{-- Mailbox health (скрыто у менеджеров — не их зона) --}}
            @unless($this->isManager)
            <div class="ds-card">
                <div class="ds-card-header"><h3>Почтовые ящики</h3></div>
                <div class="ds-card-body">
                    @if($this->mailboxes->isEmpty())
                        <div class="text-sm text-fg-3">Ни один ящик не подключён.</div>
                    @else
                        <ul class="space-y-2.5 text-[12.5px]">
                            @foreach($this->mailboxes as $mb)
                                @php
                                    $hasError = $mb->last_error_at && (! $mb->last_synced_at || $mb->last_error_at->gt($mb->last_synced_at));
                                    $dot = ! $mb->is_active
                                        ? 'bg-neutral-400'
                                        : ($hasError ? 'bg-amber-600' : 'bg-emerald-600');
                                @endphp
                                <li class="flex items-start gap-2">
                                    <span class="mt-1.5 w-2 h-2 rounded-full {{ $dot }} shrink-0"></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="text-fg-1 truncate">{{ $mb->email }}</div>
                                        <div class="text-[11.5px] text-fg-3">
                                            {{ $mb->type?->label() ?? $mb->type }} · auth: <span class="mono">{{ $mb->auth_type?->value ?? '—' }}</span>
                                        </div>
                                        @if($mb->last_synced_at)
                                            <div class="text-[11.5px] text-fg-3">sync: {{ $mb->last_synced_at->diffForHumans() }}</div>
                                        @endif
                                        @if($hasError)
                                            <div class="text-[11.5px] text-amber-700 truncate" title="{{ $mb->last_error_message }}">
                                                ошибка {{ $mb->last_error_at->diffForHumans() }}
                                            </div>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
            @endunless

            @if($this->isPrivileged)
                {{-- Foundation §7.3: AI quality score (DocumentDetector). --}}
                @php $aiScore = $this->aiQualityScore; @endphp
                @if(! empty($aiScore))
                    <div class="ds-card">
                        <div class="ds-card-header">
                            <h3>AI quality (детектор · 30 дн.)</h3>
                            <span class="flex-1"></span>
                            <a href="{{ route('settings.index') }}" class="text-[12px] text-sky-700 hover:underline">настройки →</a>
                        </div>
                        <div class="ds-card-body">
                            <table class="w-full text-[12px]">
                                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider">
                                    <tr>
                                        <th class="text-left py-1.5">Тип</th>
                                        <th class="text-right py-1.5" title="Всего срабатываний">всего</th>
                                        <th class="text-right py-1.5" title="auto_applied + manually_confirmed">подтв.</th>
                                        <th class="text-right py-1.5" title="manually_overridden — оператор изменил target">оверр.</th>
                                        <th class="text-right py-1.5" title="dismissed">прочерк</th>
                                        <th class="text-right py-1.5" title="suggested — ждут решения">pending</th>
                                        <th class="text-right py-1.5" title="(auto + confirmed) / total_final">corr%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($aiScore as $row)
                                        @php
                                            $corrTone = $row['correctness'] === null ? 'text-fg-3'
                                                : ($row['correctness'] >= 90 ? 'text-emerald-700'
                                                : ($row['correctness'] >= 70 ? 'text-amber-700' : 'text-red-700'));
                                        @endphp
                                        <tr class="border-t border-border-subtle">
                                            <td class="py-1.5 text-fg-1">{{ $row['label'] }}</td>
                                            <td class="py-1.5 text-right mono">{{ $row['total'] }}</td>
                                            <td class="py-1.5 text-right mono text-emerald-700">{{ $row['auto_applied'] + $row['confirmed'] }}</td>
                                            <td class="py-1.5 text-right mono text-amber-700">{{ $row['overridden'] }}</td>
                                            <td class="py-1.5 text-right mono text-fg-3">{{ $row['dismissed'] }}</td>
                                            <td class="py-1.5 text-right mono {{ $row['pending'] > 0 ? 'text-sky-700 font-semibold' : 'text-fg-3' }}">{{ $row['pending'] }}</td>
                                            <td class="py-1.5 text-right mono font-semibold {{ $corrTone }}">
                                                {{ $row['correctness'] !== null ? $row['correctness'] . '%' : '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            <div class="text-[10.5px] text-fg-4 mt-2">
                                corr% = (auto-applied + подтверждённые) / total. Auto-mode включается в настройках вручную после достижения ≥99% (Foundation §7.3).
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Последние пересылки --}}
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>Последние пересылки</h3>
                        <span class="flex-1"></span>
                        <a href="{{ route('mail-rules.index') }}" class="text-[12px] text-sky-700 hover:underline">правила →</a>
                    </div>
                    <div class="ds-card-body">
                        @if($this->recentForwards->isEmpty())
                            <div class="text-sm text-fg-3">Пересылок ещё не было.</div>
                        @else
                            <ul class="space-y-2 text-[12px]">
                                @foreach($this->recentForwards as $rm)
                                    <li class="flex items-start gap-2">
                                        <span class="mt-1.5 w-2 h-2 rounded-full {{ $rm->success ? 'bg-emerald-600' : 'bg-red-600' }} shrink-0"></span>
                                        <div class="min-w-0 flex-1">
                                            <div class="text-fg-1">→ <span class="mono text-fg-2">{{ $rm->forwarded_to ?: '—' }}</span></div>
                                            <div class="text-fg-3 truncate">«{{ \Illuminate\Support\Str::limit((string) $rm->emailMessage?->subject, 50) }}»</div>
                                            <div class="text-fg-3 mono">{{ $rm->rule?->name ?? '—' }} · {{ $rm->processed_at?->diffForHumans() }}</div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Оживление проигранных заявок (RevivalOffer) — прозрачность результата --}}
    @if($this->isPrivileged)
        @php $rv = $this->revivalStats; @endphp
        <div class="ds-card">
            <div class="ds-card-header">
                <h3>Оживление проигранных заявок</h3>
                <span class="text-[12px] text-fg-3 ml-2">авто-письма по снижению цены и их результат</span>
                <span class="flex-1"></span>
                <a href="{{ route('updates.index') }}" wire:navigate class="text-[12px] text-sky-700 hover:underline">что это →</a>
            </div>
            <div class="ds-card-body">
                @if($rv['total'] === 0)
                    <div class="text-sm text-fg-3">Пока ни одного оживляющего письма не отправлено. Система пришлёт его автоматически, когда по проигранной заявке с КП снизится цена.</div>
                @else
                    {{-- Сводка --}}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3 text-center">
                        <div class="rounded-md border border-border bg-surface px-2 py-2">
                            <div class="text-[18px] font-semibold text-fg-1 mono">{{ $rv['total'] }}</div>
                            <div class="text-[10.5px] uppercase tracking-wider text-fg-3">Отправлено</div>
                        </div>
                        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-2 py-2">
                            <div class="text-[18px] font-semibold text-emerald-700 mono">{{ $rv['revived'] }}</div>
                            <div class="text-[10.5px] uppercase tracking-wider text-emerald-700">Оживлено</div>
                        </div>
                        <div class="rounded-md border border-border bg-surface px-2 py-2">
                            <div class="text-[18px] font-semibold text-fg-2 mono">{{ $rv['silence'] }}</div>
                            <div class="text-[10.5px] uppercase tracking-wider text-fg-3">Тишина</div>
                        </div>
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-2 py-2">
                            <div class="text-[18px] font-semibold text-amber-800 mono">{{ $rv['declined'] }}</div>
                            <div class="text-[10.5px] uppercase tracking-wider text-amber-800">Ответил, без оживления</div>
                        </div>
                    </div>

                    {{-- Список последних --}}
                    <div class="overflow-x-auto">
                        <table class="w-full text-[12px]">
                            <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                                <tr>
                                    <th class="text-left px-2 py-1.5">Заявка</th>
                                    <th class="text-left px-2 py-1.5">Клиент</th>
                                    <th class="text-left px-2 py-1.5">Менеджер</th>
                                    <th class="text-left px-2 py-1.5">Отправлено</th>
                                    <th class="text-left px-2 py-1.5">Результат</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($rv['rows'] as $row)
                                    <tr wire:key="rv-{{ $row['request_id'] }}-{{ $row['sent_at']?->timestamp }}" class="border-b border-border-subtle hover:bg-hover">
                                        <td class="px-2 py-1.5 whitespace-nowrap">
                                            @if($row['request_id'])
                                                <a href="{{ route('requests.show', $row['request_id']) }}" wire:navigate class="mono text-sky-700 hover:underline">{{ $row['code'] ?? '—' }}</a>
                                            @else
                                                <span class="mono text-fg-3">{{ $row['code'] ?? '—' }}</span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-1.5 text-fg-2 truncate max-w-[220px]">{{ $row['client'] ?? '—' }}</td>
                                        <td class="px-2 py-1.5 text-fg-3 whitespace-nowrap">{{ $row['manager'] ?? '—' }}</td>
                                        <td class="px-2 py-1.5 text-fg-3 mono whitespace-nowrap">{{ $row['sent_at']?->format('d.m.Y') }}</td>
                                        <td class="px-2 py-1.5 whitespace-nowrap">
                                            @if($row['result'] === 'revived')
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10.5px] font-semibold bg-emerald-50 border border-emerald-200 text-emerald-700">↻ Оживлена</span>
                                            @elseif($row['result'] === 'silence')
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10.5px] font-medium bg-surface border border-border text-fg-3">Тишина</span>
                                            @else
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10.5px] font-medium bg-amber-50 border border-amber-200 text-amber-800">Ответил, без оживления</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
