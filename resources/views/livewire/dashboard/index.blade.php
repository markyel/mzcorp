@php
    $counts = $this->requestCounts;
    $coverage = $this->aiCoverage;
    $breakdown = $this->aiBreakdown;
    $maxBreakdown = !empty($breakdown) ? max(array_column($breakdown, 'count')) : 0;
@endphp

<div class="space-y-4">

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
            <div class="ds-card p-4 {{ $counts['unassigned'] > 0 ? 'border-red-300' : '' }}">
                <div class="text-[10.5px] uppercase tracking-wider font-semibold {{ $counts['unassigned'] > 0 ? 'text-red-700' : 'text-fg-3' }}">Не назначено</div>
                <div class="text-[28px] leading-none font-semibold mt-2 mono tnum {{ $counts['unassigned'] > 0 ? 'text-red-700' : 'text-fg-1' }}">{{ $counts['unassigned'] }}</div>
            </div>
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

    {{-- ───────── Two-col content ───────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Left wide --}}
        <div class="lg:col-span-2 space-y-4">

            @if($this->isPrivileged)
                {{-- AI-классификация писем --}}
                <div class="ds-card">
                    <div class="ds-card-header">
                        <h3>AI-классификация писем</h3>
                        <span class="flex-1"></span>
                        <span class="text-[11.5px] text-fg-3">
                            покрытие 30 дн: <span class="mono tnum text-fg-2">{{ $coverage['classified'] }} / {{ $coverage['total'] }}</span>
                            · <span class="mono tnum text-fg-1 font-semibold">{{ $coverage['percent'] }}%</span>
                        </span>
                    </div>
                    <div class="ds-card-body">
                        @if(empty($breakdown))
                            <div class="text-sm text-fg-3">Нет данных за последние 30 дней.</div>
                        @else
                            <div class="space-y-1.5">
                                @foreach($breakdown as $row)
                                    <div class="flex items-center gap-3 text-[12.5px]">
                                        <div class="w-32 shrink-0 text-fg-2">{{ $row['label'] }}</div>
                                        <div class="flex-1 h-2.5 rounded-full bg-neutral-100 overflow-hidden">
                                            <div class="h-full bg-sky-500"
                                                 style="width: {{ $maxBreakdown > 0 ? round($row['count'] * 100 / $maxBreakdown) : 0 }}%"></div>
                                        </div>
                                        <div class="w-12 text-right text-fg-1 mono tnum">{{ $row['count'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Менеджеры — нагрузка --}}
                <div class="ds-card">
                    <div class="ds-card-header"><h3>Нагрузка менеджеров</h3></div>
                    <div class="ds-card-body p-0">
                        @if(empty($this->managersLoad))
                            <div class="px-[18px] py-4 text-sm text-fg-3">В системе нет пользователей с ролью «менеджер».</div>
                        @else
                            <table class="w-full text-[12.5px] border-collapse">
                                <thead>
                                    <tr class="text-[10.5px] uppercase tracking-wider font-semibold text-fg-3 border-b border-border-subtle">
                                        <th class="text-left px-[18px] py-2 font-semibold">Менеджер</th>
                                        <th class="text-right px-[18px] py-2 font-semibold">Всего</th>
                                        <th class="text-right px-[18px] py-2 font-semibold">Новых</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($this->managersLoad as $m)
                                        <tr class="border-b border-border-subtle">
                                            <td class="px-[18px] py-2.5">
                                                <div class="text-fg-1">{{ $m['name'] }}</div>
                                                <div class="text-[11.5px] text-fg-3 mono">{{ $m['email'] }}</div>
                                            </td>
                                            <td class="px-[18px] py-2.5 text-right mono tnum text-fg-1">{{ $m['total'] }}</td>
                                            <td class="px-[18px] py-2.5 text-right mono tnum {{ $m['new'] > 0 ? 'text-amber-700 font-semibold' : 'text-fg-3' }}">{{ $m['new'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
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

            {{-- Mailbox health --}}
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
</div>
