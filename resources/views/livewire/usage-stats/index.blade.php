<div class="space-y-4">
    @php
        // Минуты → «Xч Yм» / «Nм» / «—».
        $fmtMin = function (int $m) {
            if ($m <= 0) return '—';
            if ($m < 60) return $m . 'м';
            $h = intdiv($m, 60);
            $r = $m % 60;
            return $r > 0 ? ($h . 'ч ' . $r . 'м') : ($h . 'ч');
        };
        $report = $this->report;
        $summary = $report['summary'];
        $daily = $report['daily'];
    @endphp

    {{-- ───────── Header + фильтры ───────── --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Использование системы</h3>
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

    {{-- ───────── Итоги по менеджерам ───────── --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Итоги за период</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="text-fg-3 text-[11.5px] uppercase tracking-wide border-b border-border">
                        <th class="text-left font-medium px-4 py-2">Менеджер</th>
                        <th class="text-right font-medium px-4 py-2">Время в системе</th>
                        <th class="text-right font-medium px-4 py-2">Активных дней</th>
                        <th class="text-right font-medium px-4 py-2">Ср. в день</th>
                        <th class="text-right font-medium px-4 py-2">Письма</th>
                        <th class="text-right font-medium px-4 py-2">Сопоставления</th>
                        <th class="text-right font-medium px-4 py-2">Уточн. вопросы</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($summary as $row)
                        @php $avg = $row['active_days'] > 0 ? (int) round($row['time_min'] / $row['active_days']) : 0; @endphp
                        <tr class="border-b border-border-subtle hover:bg-[var(--bg-hover)]">
                            <td class="px-4 py-2 font-medium text-fg-1">{{ $row['name'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ $fmtMin($row['time_min']) }}@if($row['has_estimated'])<span class="text-fg-4" title="Часть периода — приблизительная оценка по действиям (до внедрения точного учёта)">&nbsp;~</span>@endif
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-fg-2">{{ $row['active_days'] }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-fg-2">{{ $fmtMin($avg) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $row['emails'] ?: '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $row['matches'] ?: '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $row['questions'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-fg-3">Нет данных за период.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-2 text-[11px] text-fg-4 border-t border-border-subtle">
            «~» — приблизительная оценка времени по таймстампам действий (для дней до включения точного heartbeat-учёта присутствия). «Письма» — только РУЧНЫЕ письма менеджера, составленные и отправленные в CRM; авто-уведомления клиенту (подтверждение заявки, напоминания о КП/счёте, оживляющие) исключены. Письма, отправленные менеджером напрямую из почтового клиента (мимо CRM), сюда не входят — это и есть «работа вне системы».
        </div>
    </div>

    {{-- ───────── Детализация по дням ───────── --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>По дням</h3>
            <span class="text-[12px] text-fg-3 ml-2">строк: {{ count($daily) }}</span>
        </div>
        <div class="overflow-x-auto max-h-[640px]">
            <table class="w-full text-[13px]">
                <thead class="sticky top-0 bg-surface z-10">
                    <tr class="text-fg-3 text-[11.5px] uppercase tracking-wide border-b border-border">
                        <th class="text-left font-medium px-4 py-2">Дата</th>
                        <th class="text-left font-medium px-4 py-2">Менеджер</th>
                        <th class="text-right font-medium px-4 py-2">Время</th>
                        <th class="text-right font-medium px-4 py-2">Письма</th>
                        <th class="text-right font-medium px-4 py-2">Сопоставления</th>
                        <th class="text-right font-medium px-4 py-2">Уточн. вопросы</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($daily as $row)
                        <tr class="border-b border-border-subtle hover:bg-[var(--bg-hover)]" wire:key="d-{{ $row['user_id'] }}-{{ $row['day'] }}">
                            <td class="px-4 py-1.5 tabular-nums text-fg-2">{{ \Illuminate\Support\Carbon::parse($row['day'])->format('d.m.Y') }}</td>
                            <td class="px-4 py-1.5 text-fg-1">{{ $row['name'] }}</td>
                            <td class="px-4 py-1.5 text-right tabular-nums">
                                {{ $fmtMin($row['time_min']) }}@if($row['is_estimated'])<span class="text-fg-4" title="Приблизительная оценка по действиям">&nbsp;~</span>@endif
                            </td>
                            <td class="px-4 py-1.5 text-right tabular-nums">{{ $row['emails'] ?: '—' }}</td>
                            <td class="px-4 py-1.5 text-right tabular-nums">{{ $row['matches'] ?: '—' }}</td>
                            <td class="px-4 py-1.5 text-right tabular-nums">{{ $row['questions'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-fg-3">Нет активности за период.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
