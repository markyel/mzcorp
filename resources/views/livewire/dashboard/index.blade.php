<div class="space-y-6">

    {{-- KPI блок: счётчики заявок --}}
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
        <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs uppercase text-gray-500">{{ $this->isPrivileged ? 'Всего заявок' : 'Моих заявок' }}</div>
            <div class="text-2xl font-semibold mt-1">{{ $this->requestCounts['total'] }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs uppercase text-gray-500">Новых</div>
            <div class="text-2xl font-semibold mt-1 text-amber-600">{{ $this->requestCounts['new'] }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs uppercase text-gray-500">В работе</div>
            <div class="text-2xl font-semibold mt-1 text-emerald-700">{{ $this->requestCounts['assigned'] }}</div>
        </div>
        @if($this->isPrivileged)
            <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
                <div class="text-xs uppercase text-gray-500">Не назначено</div>
                <div class="text-2xl font-semibold mt-1 {{ $this->requestCounts['unassigned'] > 0 ? 'text-[#D32027]' : '' }}">
                    {{ $this->requestCounts['unassigned'] }}
                </div>
            </div>
        @endif
        <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs uppercase text-gray-500">За 24 часа</div>
            <div class="text-2xl font-semibold mt-1">{{ $this->requestCounts['today'] }}</div>
        </div>
        <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
            <div class="text-xs uppercase text-gray-500">За 7 дней</div>
            <div class="text-2xl font-semibold mt-1">{{ $this->requestCounts['week'] }}</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Левая колонка --}}
        <div class="lg:col-span-2 space-y-4">

            @if($this->isPrivileged)
                {{-- AI-классификация --}}
                <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-baseline justify-between mb-3">
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            AI-классификация писем
                        </h3>
                        <span class="text-xs text-gray-500">
                            покрытие 30 дн: {{ $this->aiCoverage['classified'] }} / {{ $this->aiCoverage['total'] }} ({{ $this->aiCoverage['percent'] }}%)
                        </span>
                    </div>
                    @if(empty($this->aiBreakdown))
                        <div class="text-sm text-gray-500">Нет данных за 30 дней.</div>
                    @else
                        <div class="space-y-1">
                            @php
                                $maxCount = max(array_column($this->aiBreakdown, 'count'));
                            @endphp
                            @foreach($this->aiBreakdown as $row)
                                <div class="flex items-center gap-3 text-sm">
                                    <div class="w-32 shrink-0 text-gray-700 dark:text-gray-300">{{ $row['label'] }}</div>
                                    <div class="flex-1 h-3 bg-gray-100 dark:bg-gray-700 rounded overflow-hidden">
                                        <div class="h-full bg-[#D32027]" style="width: {{ $maxCount > 0 ? round($row['count'] * 100 / $maxCount) : 0 }}%"></div>
                                    </div>
                                    <div class="w-12 text-right text-gray-600 dark:text-gray-400 tabular-nums">{{ $row['count'] }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Менеджеры --}}
                <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Менеджеры — нагрузка</h3>
                    @if(empty($this->managersLoad))
                        <div class="text-sm text-gray-500">В системе нет пользователей с ролью «менеджер».</div>
                    @else
                        <table class="w-full text-sm">
                            <thead class="text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="text-left py-1">Менеджер</th>
                                    <th class="text-right py-1">Всего</th>
                                    <th class="text-right py-1">Новых</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach($this->managersLoad as $m)
                                    <tr>
                                        <td class="py-1.5">
                                            <div>{{ $m['name'] }}</div>
                                            <div class="text-xs text-gray-500">{{ $m['email'] }}</div>
                                        </td>
                                        <td class="py-1.5 text-right tabular-nums">{{ $m['total'] }}</td>
                                        <td class="py-1.5 text-right tabular-nums {{ $m['new'] > 0 ? 'text-amber-600 font-medium' : 'text-gray-500' }}">{{ $m['new'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endif

            {{-- Последние заявки --}}
            <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex items-baseline justify-between mb-3">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ $this->isPrivileged ? 'Последние заявки' : 'Мои последние заявки' }}
                    </h3>
                    <a href="{{ route('requests.index') }}" class="text-xs text-[#D32027] hover:underline">Все →</a>
                </div>
                @if($this->recentRequests->isEmpty())
                    <div class="text-sm text-gray-500">Заявок ещё нет.</div>
                @else
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                        @foreach($this->recentRequests as $r)
                            <li class="py-2 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <a href="{{ route('requests.show', $r) }}" class="font-mono text-xs text-[#D32027] hover:underline">{{ $r->internal_code }}</a>
                                    <span class="ml-2">{{ \Illuminate\Support\Str::limit((string) $r->subject, 70) }}</span>
                                </div>
                                <div class="text-xs text-gray-500 whitespace-nowrap">
                                    {{ $r->client_email }} · {{ $r->assignedUser?->name ?? '—' }}
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        {{-- Правая колонка --}}
        <div class="space-y-4">

            {{-- Health ящиков --}}
            <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Почтовые ящики</h3>
                @if($this->mailboxes->isEmpty())
                    <div class="text-sm text-gray-500">Ни один ящик не подключён.</div>
                @else
                    <ul class="space-y-2 text-sm">
                        @foreach($this->mailboxes as $mb)
                            <li class="flex items-start gap-2">
                                <span class="mt-1 w-2 h-2 rounded-full {{ $mb->is_active ? ($mb->last_error_at ? 'bg-amber-500' : 'bg-emerald-500') : 'bg-gray-400' }}"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium truncate">{{ $mb->email }}</div>
                                    <div class="text-xs text-gray-500">
                                        {{ $mb->type->label() }} · {{ $mb->auth_type->value }}
                                    </div>
                                    @if($mb->last_synced_at)
                                        <div class="text-xs text-gray-500">
                                            sync: {{ $mb->last_synced_at->diffForHumans() }}
                                        </div>
                                    @endif
                                    @if($mb->last_error_at)
                                        <div class="text-xs text-amber-700 truncate" title="{{ $mb->last_error_message }}">
                                            ошибка {{ $mb->last_error_at->diffForHumans() }}
                                        </div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if($this->isPrivileged)
                {{-- Последние пересылки --}}
                <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-baseline justify-between mb-3">
                        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Последние пересылки</h3>
                        <a href="{{ route('mail-rules.index') }}" class="text-xs text-[#D32027] hover:underline">Правила →</a>
                    </div>
                    @if($this->recentForwards->isEmpty())
                        <div class="text-sm text-gray-500">Пересылок ещё не было.</div>
                    @else
                        <ul class="space-y-2 text-xs">
                            @foreach($this->recentForwards as $rm)
                                <li class="flex items-start gap-2">
                                    <span class="mt-1 w-2 h-2 rounded-full {{ $rm->success ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                                    <div class="min-w-0 flex-1">
                                        <div>
                                            → <span class="font-mono">{{ $rm->forwarded_to ?: '—' }}</span>
                                        </div>
                                        <div class="text-gray-500 truncate">
                                            «{{ \Illuminate\Support\Str::limit((string) $rm->emailMessage?->subject, 50) }}»
                                        </div>
                                        <div class="text-gray-500">
                                            {{ $rm->rule?->name ?? '—' }} · {{ $rm->processed_at?->diffForHumans() }}
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
