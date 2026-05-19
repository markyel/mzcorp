@php
    use App\Enums\RequestStatus;

    // Phase 1.10: chip-цвет берётся через RequestStatus::chipClass()
    // (универсал на все 14 статусов). Старый $chipClass-массив оставлен
    // для backward-compat — если где-то в шаблоне ссылается.
    // Палитра status-chip → цвет (реальные статусы).
    // Богатая таксономия (КП-отправлено, счёт-выставлен, refresh-цен,
    // пауза до DD.MM, просрочено NЧ NМ) — Phase 2-4 state-machine.
    $chipClass = [
        RequestStatus::Pending->value  => 'chip-paused',
        RequestStatus::New->value      => 'chip-attn',
        RequestStatus::Assigned->value => 'chip-info',
    ];

    $statusLabel = [
        RequestStatus::Pending->value  => 'В обработке',
        RequestStatus::New->value      => 'Нераспределена',
        // Assigned не появляется как group-key (объединён с InProgress
        // в Pool::render). Override больше не нужен.
    ];

    $disabledTitle = 'Доступно в Phase 2';
@endphp

{{--
    Layout: rail (56px) + list nav (240px) + main.
    Topbar (~48px) приходит от x-app-layout, поэтому min-h берём от
    100vh - topbar. Internal scroll вне shell — основная страница
    скроллится естественно.
--}}
<div class="grid"
     style="grid-template-columns: 56px 240px 1fr; min-height: calc(100vh - var(--topbar-h));">

    {{-- ============== RAIL ============== --}}
    <aside class="border-r border-[var(--border)] bg-[var(--bg-sidebar)] flex flex-col items-center py-2 gap-0.5">
        @php
            // Иконы: Дашборд / Заявки(active) / Почта / Правила / KB / Поставщики / SLA / Поиск / Настройки
            // Phase 1 active: только «Заявки». Дашборд имеет реальный роут — кликабельный.
            $rail = [
                ['icon' => '⌂', 'label' => 'Дашборд',     'href' => route('dashboard'), 'active' => false, 'enabled' => true],
                ['icon' => '≡', 'label' => 'Заявки',      'href' => route('requests.index'), 'active' => true, 'enabled' => true],
                ['icon' => '✉', 'label' => 'Почта',       'href' => null, 'active' => false, 'enabled' => false],
                ['icon' => '⌗', 'label' => 'Правила',     'href' => null, 'active' => false, 'enabled' => false],
                ['icon' => '▦', 'label' => 'KB',          'href' => null, 'active' => false, 'enabled' => false],
                ['sep' => true],
                ['icon' => '◇', 'label' => 'Поставщики',  'href' => null, 'active' => false, 'enabled' => false],
                ['icon' => '◷', 'label' => 'SLA',         'href' => null, 'active' => false, 'enabled' => false],
                ['icon' => '⌕', 'label' => 'Поиск ⌘K',    'href' => null, 'active' => false, 'enabled' => false],
            ];
        @endphp

        @foreach($rail as $r)
            @if(isset($r['sep']))
                <div class="w-6 h-px bg-[var(--border)] my-1.5"></div>
            @else
                @php
                    $base = 'w-10 h-10 rounded-md flex items-center justify-center font-mono text-[13px] font-semibold relative';
                    $cls = $r['active']
                        ? "$base text-[var(--accent)] bg-[var(--bg-surface)]"
                        : ($r['enabled']
                            ? "$base text-[var(--fg-3)] hover:text-[var(--fg-1)] hover:bg-[var(--bg-hover)]"
                            : "$base text-[var(--fg-4)] cursor-not-allowed opacity-60");
                @endphp
                @if($r['enabled'] && $r['href'])
                    <a href="{{ $r['href'] }}" class="{{ $cls }}" title="{{ $r['label'] }}">
                        @if($r['active'])<span class="absolute -left-2 top-2 bottom-2 w-0.5 bg-[var(--accent)] rounded-r"></span>@endif
                        {{ $r['icon'] }}
                    </a>
                @else
                    <div class="{{ $cls }}" title="{{ $r['label'] }} — {{ $disabledTitle }}">{{ $r['icon'] }}</div>
                @endif
            @endif
        @endforeach

        {{-- Settings внизу --}}
        <div class="mt-auto"></div>
        <div class="w-6 h-px bg-[var(--border)] my-1.5"></div>
        <div class="w-10 h-10 rounded-md flex items-center justify-center font-mono text-[13px] font-semibold text-[var(--fg-4)] cursor-not-allowed opacity-60"
             title="Настройки — {{ $disabledTitle }}">⚙</div>
    </aside>

    {{-- ============== LIST NAV ============== --}}
    <aside class="border-r border-[var(--border)] bg-[var(--bg-sidebar)] overflow-y-auto flex flex-col">
        <div class="px-4 pt-3 pb-2 flex items-center justify-between">
            <h2 class="m-0 font-semibold text-[13px] text-[var(--fg-1)]">Заявки</h2>
            <span class="text-[11.5px] text-[var(--fg-4)] cursor-not-allowed" title="{{ $disabledTitle }}">+ Очередь</span>
        </div>

        @php
            // «Реальные» queries — только то, что мы умеем фильтровать сейчас.
            // Phase 2 saved views (KONE/Schindler / возраст ≥ 7 дн / крупные клиенты) —
            // dimmed-state, чтобы видеть структуру UI, но без обмана.
            $myQueries = [
                ['label' => 'Мои в работе', 'count' => $totals['mine_open'], 'scope' => 'mine', 'status' => '', 'pill' => false],
            ];
            $teamQueries = [];
            if ($this->canSeeAll) {
                // 'unassigned' — спец-флаг: applyView применит whereNull('assigned_user_id')
                // + сбросит bucket='all' (чтобы paused/closed unassigned тоже показались).
                $teamQueries[] = ['label' => 'Нераспределённые', 'count' => $totals['unassigned'], 'scope' => 'all', 'status' => '', 'unassigned' => true, 'pill' => false];
                $teamQueries[] = ['label' => 'Все открытые',     'count' => $totals['all_open'],   'scope' => 'all', 'status' => '', 'unassigned' => false, 'pill' => false];
            }

            // Detect active query — точное совпадение scope+status+unassigned.
            $isActive = function (array $q) use ($effectiveScope, $status) {
                $unassignedActive = (bool) ($this->unassignedOnly ?? false);
                $unassignedThis = (bool) ($q['unassigned'] ?? false);
                return $q['scope'] === $effectiveScope
                    && $q['status'] === $status
                    && $unassignedActive === $unassignedThis;
            };
        @endphp

        @php
            $renderQuery = function (array $q, bool $active) use ($disabledTitle) {
                $base = 'flex items-center gap-2 px-2 py-1.5 rounded-md text-[12.5px] cursor-pointer';
                $cls = $active
                    ? "$base bg-[var(--bg-surface)] text-[var(--fg-1)] font-medium"
                    : "$base text-[var(--fg-2)] hover:bg-[var(--bg-hover)]";
                if ($active) {
                    $cls .= ' shadow-[inset_2px_0_0_var(--accent)]';
                }
                return $cls;
            };
        @endphp

        <div class="px-2 pb-2">
            <div class="text-[10.5px] font-semibold uppercase tracking-wider text-[var(--fg-3)] px-2 pt-2 pb-1.5">
                Мои · {{ \Illuminate\Support\Str::limit(auth()->user()->name, 16, '…') }}
            </div>
            @foreach($myQueries as $q)
                @php $active = $isActive($q); @endphp
                <a href="#" wire:click.prevent="applyView('{{ $q['scope'] }}', '{{ $q['status'] }}')"
                   class="{{ $renderQuery($q, $active) }}">
                    <span class="w-3.5 text-center text-[var(--fg-3)]">●</span>
                    <span class="flex-1">{{ $q['label'] }}</span>
                    <span class="font-mono text-[11.5px] {{ $active ? 'text-[var(--fg-1)]' : 'text-[var(--fg-3)]' }}">{{ $q['count'] }}</span>
                </a>
            @endforeach

            {{-- Phase 2 placeholder queries (статус-производные) --}}
            @foreach([
                ['icon' => '⌛', 'label' => 'Жду клиента'],
                ['icon' => '⏸', 'label' => 'На паузе'],
                ['icon' => '✓', 'label' => 'КП отправлено'],
                ['icon' => '₽', 'label' => 'Счёт выставлен'],
            ] as $q)
                <div class="flex items-center gap-2 px-2 py-1.5 rounded-md text-[12.5px] text-[var(--fg-4)] cursor-not-allowed opacity-70"
                     title="{{ $disabledTitle }}">
                    <span class="w-3.5 text-center">{{ $q['icon'] }}</span>
                    <span class="flex-1">{{ $q['label'] }}</span>
                    <span class="font-mono text-[11.5px]">—</span>
                </div>
            @endforeach
        </div>

        @if(! empty($teamQueries))
            <div class="px-2 pb-2">
                <div class="text-[10.5px] font-semibold uppercase tracking-wider text-[var(--fg-3)] px-2 pt-2 pb-1.5">
                    Команда
                </div>
                @foreach($teamQueries as $q)
                    @php
                        $active = $isActive($q);
                        $unassignedArg = ($q['unassigned'] ?? false) ? 'true' : 'false';
                    @endphp
                    <a href="#" wire:click.prevent="applyView('{{ $q['scope'] }}', '{{ $q['status'] }}', {{ $unassignedArg }})"
                       class="{{ $renderQuery($q, $active) }}">
                        <span class="w-3.5 text-center text-[var(--fg-3)]">⊕</span>
                        <span class="flex-1">{{ $q['label'] }}</span>
                        <span class="font-mono text-[11.5px] {{ $active ? 'text-[var(--fg-1)]' : 'text-[var(--fg-3)]' }}">{{ $q['count'] ?? '—' }}</span>
                    </a>
                @endforeach

                @foreach([
                    ['icon' => '⚑', 'label' => 'Просрочено по SLA'],
                    ['icon' => '↻', 'label' => 'Refresh цен ждут'],
                ] as $q)
                    <div class="flex items-center gap-2 px-2 py-1.5 rounded-md text-[12.5px] text-[var(--fg-4)] cursor-not-allowed opacity-70"
                         title="{{ $disabledTitle }}">
                        <span class="w-3.5 text-center">{{ $q['icon'] }}</span>
                        <span class="flex-1">{{ $q['label'] }}</span>
                        <span class="font-mono text-[11.5px]">—</span>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="px-2 pb-2">
            <div class="text-[10.5px] font-semibold uppercase tracking-wider text-[var(--fg-3)] px-2 pt-2 pb-1.5">
                Сохранённые виды
            </div>
            @foreach([
                'Крупные клиенты',
                'KONE / Schindler',
                'МЛЗ + ЩЛЗ',
                'Возраст ≥ 7 дн',
            ] as $label)
                <div class="flex items-center gap-2 px-2 py-1.5 rounded-md text-[12.5px] text-[var(--fg-4)] cursor-not-allowed opacity-70"
                     title="{{ $disabledTitle }}">
                    <span class="w-3.5 text-center">★</span>
                    <span class="flex-1">{{ $label }}</span>
                    <span class="font-mono text-[11.5px]">—</span>
                </div>
            @endforeach
            <div class="flex items-center gap-2 px-2 py-1.5 rounded-md text-[12.5px] text-[var(--fg-4)] cursor-not-allowed opacity-70"
                 title="{{ $disabledTitle }}">
                <span class="w-3.5 text-center">+</span>
                <span class="flex-1">Сохранить текущий вид…</span>
            </div>
        </div>
    </aside>

    {{-- ============== MAIN ============== --}}
    <section class="bg-[var(--bg-surface)] flex flex-col min-w-0">

        {{-- PAGE HEADER --}}
        <div class="px-5 pt-3.5 pb-2 border-b border-[var(--border-subtle)] flex items-end gap-4">
            <div class="min-w-0">
                <div class="text-[11.5px] font-medium uppercase tracking-wider text-[var(--fg-3)] mb-1">
                    Заявки · {{ $effectiveScope === 'mine' ? 'Мои' : 'Команда' }} ·
                    @if($status === '')
                        Все
                    @else
                        {{ $statusLabel[$status] ?? $status }}
                    @endif
                </div>
                <h1 class="m-0 text-[20px] font-semibold leading-tight text-[var(--fg-1)] flex items-center gap-2.5">
                    @if($effectiveScope === 'mine' && $status === '')
                        Мои заявки
                    @elseif($effectiveScope === 'all' && $status === '')
                        Все открытые заявки
                    @elseif($effectiveScope === 'all' && $status === RequestStatus::New->value)
                        Нераспределённые
                    @else
                        Заявки
                    @endif
                    <span class="text-[14px] font-medium text-[var(--fg-3)] tnum">· {{ $page->total() }}</span>
                </h1>
                <div class="text-[12.5px] text-[var(--fg-3)] mt-1">
                    обновлено {{ now()->format('H:i') }} ·
                    <span class="text-[var(--fg-4)]" title="{{ $disabledTitle }}">автообновление —</span> ·
                    <span class="text-[var(--fg-4)]" title="{{ $disabledTitle }}">сумма в работе —</span>
                </div>
            </div>
            <div class="flex-1"></div>

            {{-- Search вынесен в topbar (navigation.blade.php). Здесь —
                 синхронизатор: если URL-param ?q изменился, Livewire подхватит. --}}

            <button class="btn" disabled title="{{ $disabledTitle }}">Экспорт CSV</button>

            {{-- View segmented control: only Таблица is real --}}
            <div class="flex bg-[var(--bg-app)] border border-[var(--border)] rounded-md p-0.5">
                <button class="h-6 px-2.5 rounded text-[12px] font-medium bg-[var(--bg-surface)] text-[var(--fg-1)] shadow-sm">Таблица</button>
                <button class="h-6 px-2.5 rounded text-[12px] font-medium text-[var(--fg-4)] cursor-not-allowed" title="{{ $disabledTitle }}">Канбан</button>
                <button class="h-6 px-2.5 rounded text-[12px] font-medium text-[var(--fg-4)] cursor-not-allowed" title="{{ $disabledTitle }}">Календарь</button>
            </div>
        </div>

        {{-- FILTERS BAR --}}
        <div class="px-5 py-2.5 border-b border-[var(--border)] bg-[var(--bg-surface-2)] text-[12.5px] flex items-center gap-2 flex-wrap">

            {{-- Scope filter (если can see all) --}}
            @if($this->canSeeAll)
                <span class="inline-flex items-center gap-1.5 h-[26px] px-2.5 border border-[var(--border-strong)] rounded-md bg-[var(--bg-surface)] text-[var(--fg-2)]">
                    Назначено:
                    <button wire:click="$set('scope', 'mine')"
                            class="font-medium {{ $effectiveScope === 'mine' ? 'text-[var(--accent)]' : 'text-[var(--fg-2)] hover:text-[var(--fg-1)]' }}">Мои ({{ $totals['mine'] }})</button>
                    <span class="text-[var(--fg-4)]">·</span>
                    <button wire:click="$set('scope', 'all')"
                            class="font-medium {{ $effectiveScope === 'all' ? 'text-[var(--accent)]' : 'text-[var(--fg-2)] hover:text-[var(--fg-1)]' }}">Команда ({{ $totals['all'] }})</button>
                </span>
            @endif

            {{-- Phase 1.10: bucket-chips (группа статусов). Phase 1.11
                 добавляет «Просрочено» — flat-list заявок с просроченным
                 attention_required_at; кнопка красная если счётчик > 0. --}}
            @php
                $bucketChips = [
                    'active'  => ['label' => 'Активные',   'count' => $bucketCounts['active']],
                    'overdue' => ['label' => 'Просрочено', 'count' => $bucketCounts['overdue'] ?? 0],
                    'paused'  => ['label' => 'На паузе',   'count' => $bucketCounts['paused']],
                    'closed'  => ['label' => 'Закрытые',   'count' => $bucketCounts['closed']],
                    'all'     => ['label' => 'Все',        'count' => $bucketCounts['all']],
                ];
            @endphp
            @foreach($bucketChips as $key => $meta)
                @php
                    $on = $bucket === $key;
                    $isOverdueChip = $key === 'overdue';
                    $overdueAttn = $isOverdueChip && $meta['count'] > 0;
                @endphp
                <button wire:click="setBucket('{{ $key }}')"
                        class="inline-flex items-center gap-1.5 h-[26px] px-2.5 rounded-md whitespace-nowrap font-medium
                               {{ $on
                                  ? ($overdueAttn ? 'bg-[var(--red-600)] text-white' : 'bg-[var(--accent)] text-fg-on-accent')
                                  : ($overdueAttn
                                        ? 'bg-[var(--red-50)] border border-[var(--red-300)] text-[var(--red-700)] hover:bg-[var(--red-100)]'
                                        : 'bg-[var(--bg-surface)] border border-[var(--border-strong)] text-[var(--fg-2)] hover:text-[var(--fg-1)]') }}">
                    <span>{{ $meta['label'] }}</span>
                    <span class="font-mono text-[11px] {{ $on ? 'opacity-90' : 'opacity-75' }}">{{ $meta['count'] }}</span>
                </button>
            @endforeach

            <span class="text-[var(--fg-4)] mx-1">·</span>

            {{-- Уточняющие status-chips внутри текущего bucket'а. --}}
            <button wire:click="$set('status', '')"
                    class="inline-flex items-center gap-1.5 h-[26px] px-2.5 rounded-md whitespace-nowrap
                           {{ $status === ''
                              ? 'bg-[var(--sky-50)] border border-[var(--sky-500)] text-[var(--sky-700)]'
                              : 'bg-[var(--bg-surface)] border border-[var(--border-strong)] text-[var(--fg-2)] hover:text-[var(--fg-1)]' }}">
                Любой статус
            </button>
            @foreach($bucketStatuses as $sv)
                @php
                    $enum = \App\Enums\RequestStatus::tryFrom($sv);
                    if (! $enum) continue;
                    $cnt = $statusCounts[$sv] ?? 0;
                    if ($cnt === 0) continue; // не показываем пустые
                    $on = $status === $sv;
                @endphp
                <button wire:click="$set('status', '{{ $sv }}')"
                        class="inline-flex items-center gap-1.5 h-[26px] px-2.5 rounded-md whitespace-nowrap
                               {{ $on
                                  ? 'bg-[var(--sky-50)] border border-[var(--sky-500)] text-[var(--sky-700)]'
                                  : 'bg-[var(--bg-surface)] border border-[var(--border-strong)] text-[var(--fg-2)] hover:text-[var(--fg-1)]' }}">
                    <span>{{ $enum->label() }}</span>
                    <span class="font-mono text-[11px] opacity-75">{{ $cnt }}</span>
                    @if($on)<span class="text-[var(--fg-3)] text-[14px] leading-none">×</span>@endif
                </button>
            @endforeach

            {{-- Phase 2 disabled chips --}}
            @foreach(['Бренд', 'Возраст ≤ 30 дн', 'Сумма ≥ 10 000 ₽'] as $label)
                <span class="inline-flex items-center gap-1.5 h-[26px] px-2.5 border border-dashed border-[var(--border-strong)] rounded-md bg-[var(--bg-surface)] text-[var(--fg-4)] cursor-not-allowed"
                      title="{{ $disabledTitle }}">{{ $label }}</span>
            @endforeach
            <span class="inline-flex items-center gap-1.5 h-[26px] px-2.5 border border-dashed border-[var(--border-strong)] rounded-md bg-[var(--bg-surface)] text-[var(--fg-4)] cursor-not-allowed"
                  title="{{ $disabledTitle }}">+ фильтр</span>

            <div class="flex-1"></div>

            <span class="text-[11.5px] text-[var(--fg-3)] inline-flex items-center gap-1.5">
                Группировка: <b class="font-medium text-[var(--fg-1)]">по статусу</b>
            </span>
            <span class="text-[11.5px] text-[var(--fg-3)] inline-flex items-center gap-1.5">
                Сортировка: <b class="font-medium text-[var(--fg-1)]">свежие ↓</b>
            </span>
        </div>

        {{-- BULK BAR — Phase 2 placeholder. Без selection. --}}
        <div class="px-5 py-2 border-b border-[var(--border)] bg-[var(--bg-surface-2)] text-[12.5px] text-[var(--fg-4)] flex items-center gap-2.5"
             title="{{ $disabledTitle }}">
            <span class="font-semibold">Множественный выбор</span>
            <span class="opacity-70">· назначить менеджера / refresh цен / пауза / закрыть как не наша тема</span>
            <span class="flex-1"></span>
            <span class="text-[11px] uppercase tracking-wider opacity-70">Phase 2</span>
        </div>

        {{-- TABLE --}}
        <div class="flex-1 overflow-auto">
            @if($page->total() === 0)
                <div class="p-12 text-center text-[var(--fg-3)]">
                    @if($effectiveScope === 'mine' && $search === '' && $status === '')
                        Все заявки разобраны. Хорошая работа.
                    @else
                        Под фильтр ничего не попало.
                    @endif
                </div>
            @else
                {{-- THEAD (sticky) --}}
                <div class="sticky top-0 bg-[var(--bg-surface)] border-b border-[var(--border-strong)] z-[2] grid items-center px-5 h-[32px] gap-x-3
                            text-[11px] font-semibold uppercase tracking-wider text-[var(--fg-3)]"
                     style="grid-template-columns: 24px 110px minmax(220px,1fr) 150px 170px 140px 160px 100px 80px 32px;">
                    <span></span>
                    <span>код</span>
                    <span>заявка</span>
                    <span>статус</span>
                    <span>событие</span>
                    <span>менеджер</span>
                    <span>клиент</span>
                    <span class="text-right">сумма</span>
                    <span class="text-right">возраст</span>
                    <span></span>
                </div>

                @foreach($groups as $group)
                    {{-- Group header sticky. Phase 1.11: bucket=overdue даёт
                         flat-list — group со status=null, header не рендерим. --}}
                    @if($group['status'] !== null)
                    <div class="flex items-center gap-2.5 px-5 pt-2.5 pb-2 bg-[var(--bg-surface-2)] border-t border-[var(--border)] border-b border-[var(--border-subtle)] sticky top-[32px] z-[1]">
                        <span class="w-3.5 text-[var(--fg-3)] text-[10px]">▼</span>
                        <h3 class="m-0 font-semibold text-[12px] uppercase tracking-wider text-[var(--fg-1)]">
                            {{ $statusLabel[$group['status']->value] ?? $group['status']->label() }}
                        </h3>
                        <span class="font-semibold text-[11px] text-[var(--fg-3)] tnum">· {{ $group['count'] }}</span>
                        <span class="ml-auto text-[11.5px] font-medium text-[var(--fg-3)] flex gap-3.5">
                            <span title="{{ $disabledTitle }}">ср. возраст —</span>
                            <span title="{{ $disabledTitle }}">сумма —</span>
                        </span>
                    </div>
                    @endif

                    @foreach($group['rows'] as $req)
                        @php
                            $href = route('requests.show', $req);

                            // Короткий формат возраста: «Nм / Nч / Nд» (как в макете),
                            // вместо ru-локали Carbon «1 ч. назад».
                            $age = '—';
                            $ageDays = 0;
                            if ($req->created_at) {
                                $secs = (int) abs(now()->diffInSeconds($req->created_at, false));
                                $ageDays = (int) floor($secs / 86400);
                                $age = $secs < 60 ? $secs . 'с'
                                    : ($secs < 3600 ? (int) floor($secs / 60) . 'м'
                                    : ($secs < 86400 ? (int) floor($secs / 3600) . 'ч'
                                    : $ageDays . 'д'));
                            }
                            $ageColor = $ageDays >= 7 ? 'text-[var(--red-700)]' : ($ageDays >= 3 ? 'text-[var(--amber-700)]' : 'text-[var(--fg-3)]');

                            // Title cell: t1 — имя первой позиции (если items распарсены),
                            // иначе subject. t2 — компактная контекстная строка
                            // «N поз. · бренд» БЕЗ дублирования subject (он уже в t1
                            // когда items нет, или сам по себе шумит).
                            $firstItem = $req->items->first();
                            $titleT1 = \Illuminate\Support\Str::limit(
                                $firstItem?->parsed_name ?: ($req->subject ?: '(без темы)'),
                                90,
                                '…'
                            );
                            $titleT2parts = [];
                            if ($req->items_count > 0) {
                                $titleT2parts[] = $req->items_count . ' поз.';
                            }
                            if ($firstItem?->parsed_brand) {
                                $titleT2parts[] = $firstItem->parsed_brand;
                            }
                            // Subject в t2 только если items есть (иначе t1 = subject уже).
                            if ($firstItem && $req->subject) {
                                $titleT2parts[] = \Illuminate\Support\Str::limit($req->subject, 50, '…');
                            }
                            $titleT2 = implode(' · ', $titleT2parts);

                            // Sticky-шильдик. AssignmentService пишет reason'ы двух форматов:
                            //   - plain 'auto_sticky' (старые 165 backfill-записей)
                            //   - 'auto_sticky:{"kind":"catalog|client|text","linked":[...]}'
                            // Сравнение по str_starts_with покрывает оба варианта, kind вытаскиваем
                            // из JSON-suffix для tooltip'а и иконки.
                            $stickyReason = $req->latestAssignment?->reason ?? '';
                            $isSticky = str_starts_with($stickyReason, 'auto_sticky');
                            $stickyKind = null;
                            $stickyLinked = [];
                            if ($isSticky && str_contains($stickyReason, ':')) {
                                $stickyPayload = json_decode(substr($stickyReason, strlen('auto_sticky:')), true);
                                if (is_array($stickyPayload)) {
                                    $stickyKind = $stickyPayload['kind'] ?? null;
                                    $stickyLinked = is_array($stickyPayload['linked'] ?? null) ? $stickyPayload['linked'] : [];
                                }
                            }
                            // Иконка + tooltip по типу sticky. Старые plain-reason'ы без kind
                            // рендерятся нейтрально как «sticky».
                            $stickyIcon = match ($stickyKind) {
                                'catalog' => '📦',
                                'client' => '👤',
                                'text' => '🔤',
                                default => '',
                            };
                            $stickyTitle = match ($stickyKind) {
                                'catalog' => 'Sticky по каталогу: тот же товар уже у этого менеджера',
                                'client' => 'Sticky по клиенту: у менеджера открыта заявка от того же email',
                                'text' => 'Sticky по тексту: совпало название/артикул позиции',
                                default => 'Sticky-привязка к менеджеру',
                            };
                            if (! empty($stickyLinked)) {
                                $stickyTitle .= ' · ' . count($stickyLinked) . ' связ. заяв.';
                            }
                            $attachCount = $req->emailMessage?->attachments_count ?? 0;

                            // Phase 1.11 + minimize 2026-05-21:
                            // attention badge + tint. ClientReplied — info (amber),
                            // не алярм: «есть новости», менеджер ещё не открыл.
                            // Snimaется в Detail::mount → onManagerOpened.
                            // SlaBreach / PostponedResume — red, реальная просрочка.
                            $attnReason = $req->attention_reason; // AttentionReason|null
                            $attnAt = $req->attention_required_at;
                            $isClientReplied = $attnReason === \App\Enums\AttentionReason::ClientReplied;
                            $isFreshAssignment = $attnReason === \App\Enums\AttentionReason::FreshAssignment;
                            $isManualFlag = $attnReason === \App\Enums\AttentionReason::Manual;
                            $isSupplierReplied = $attnReason === \App\Enums\AttentionReason::SupplierReplied;
                            // info-флаги — это «есть новости» / «новая» / «🚩 пометка»,
                            // НЕ просрочка по SLA. Красным фоном не подсвечиваем,
                            // «просрочено N» текстом не пишем.
                            $isInfoFlag = $isClientReplied || $isFreshAssignment || $isManualFlag || $isSupplierReplied;
                            $isOverdueAlarm = $req->attention_level === 1 && ! $isInfoFlag;
                            $attnText = null;
                            if ($isClientReplied) {
                                $attnText = 'есть ответ';
                            } elseif ($isFreshAssignment) {
                                $attnText = 'новая';
                            } elseif ($isSupplierReplied) {
                                $attnText = 'ответ поставщика';
                            } elseif ($isManualFlag) {
                                $attnText = '🚩 пометка';
                            } elseif ($attnAt) {
                                $diffSecs = (int) now()->diffInSeconds($attnAt, false);
                                $absSecs = abs($diffSecs);
                                $unit = $absSecs < 3600 ? (int) max(1, floor($absSecs / 60)) . 'м'
                                    : ($absSecs < 86400 ? (int) max(1, floor($absSecs / 3600)) . 'ч'
                                    : (int) max(1, floor($absSecs / 86400)) . 'д');
                                $attnText = $diffSecs < 0
                                    ? 'просрочено ' . $unit
                                    : 'через ' . $unit;
                            }

                            $managerName = $req->assignedUser?->name;
                            $managerInitials = $managerName
                                ? collect(preg_split('/\s+/u', trim($managerName)))
                                    ->filter()
                                    ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
                                    ->take(2)
                                    ->implode('')
                                : '?';
                            $clientLine1 = $req->client_name ?: $req->client_email;
                            $clientLine2 = $req->client_name ? $req->client_email : null;
                        @endphp

                        <a href="{{ $href }}" wire:key="req-{{ $req->id }}"
                           class="grid items-center px-5 h-[42px] gap-x-3 border-b border-[var(--border-subtle)] text-[12.5px] hover:bg-[var(--bg-hover)] transition-colors
                                  {{ $isOverdueAlarm ? 'bg-[var(--red-50)] hover:bg-[var(--red-100)] border-l-2 border-l-[var(--red-500)] pl-[18px]' : ($isInfoFlag ? 'bg-[var(--amber-50)] hover:bg-[var(--amber-100)] border-l-2 border-l-[var(--amber-500)] pl-[18px]' : '') }}"
                           style="grid-template-columns: 24px 110px minmax(220px,1fr) 150px 170px 140px 160px 100px 80px 32px;">

                            {{-- checkbox (Phase 2) --}}
                            <span class="w-3.5 h-3.5 border border-[var(--border-strong)] rounded-[3px] bg-[var(--bg-surface)] opacity-50"
                                  title="{{ $disabledTitle }}"></span>

                            {{-- code --}}
                            <span class="font-mono text-[12px] text-[var(--accent)]">{{ $req->internal_code }}</span>

                            {{-- title (t1 + badges, t2) --}}
                            <span class="pr-2 overflow-hidden min-w-0">
                                <div class="font-medium text-[var(--fg-1)] truncate flex items-center gap-1.5">
                                    <span class="truncate">{{ $titleT1 }}</span>
                                    @if($isSticky)
                                        <span class="inline-flex items-center gap-0.5 font-semibold text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded-[3px] bg-[var(--violet-50)] text-[var(--violet-700)] flex-shrink-0"
                                              title="{{ $stickyTitle }}">{{ $stickyIcon }} sticky</span>
                                    @endif
                                    @if($attachCount > 0)
                                        <span class="inline-flex items-center gap-0.5 font-semibold text-[10px] px-1.5 py-0.5 rounded-[3px] bg-[var(--neutral-100)] text-[var(--fg-2)] flex-shrink-0">📎 {{ $attachCount }}</span>
                                    @endif
                                    @if($attnReason && ($attnAt || $isClientReplied))
                                        <span class="inline-flex items-center gap-0.5 font-semibold text-[10px] px-1.5 py-0.5 rounded-[3px] flex-shrink-0
                                                     {{ $isOverdueAlarm
                                                         ? 'bg-[var(--red-100)] text-[var(--red-700)]'
                                                         : ($isClientReplied
                                                             ? 'bg-[var(--amber-100)] text-[var(--amber-800)]'
                                                             : 'bg-[var(--amber-50)] text-[var(--amber-700)]') }}"
                                              title="{{ $attnReason->label() }}{{ $attnAt ? ' · ' . $attnAt->format('d.m.Y H:i') : '' }}">
                                            {{ $attnReason->icon() }} {{ $attnText }}
                                        </span>
                                    @endif
                                </div>
                                @if($titleT2 !== '')
                                    <div class="text-[11.5px] text-[var(--fg-3)] mt-0.5 truncate">{{ $titleT2 }}</div>
                                @endif
                            </span>

                            {{-- status chip — через enum-метод (Phase 1.10 универсал). --}}
                            <span>
                                <span class="chip {{ $req->status->chipClass() }}">
                                    <span class="dot"></span>{{ $req->status->label() }}
                                </span>
                            </span>

                            {{-- событие: тип последней активности (Phase pool-event).
                                 requiresAttention() — амбер, silencesAttention() / нейтральные — серый. --}}
                            @php
                                $actType = $req->last_activity_type;
                                $actAt = $req->last_activity_at;
                                if ($actType) {
                                    $actClasses = $actType->requiresAttention()
                                        ? 'bg-[var(--amber-50)] text-[var(--amber-800)] border-[var(--amber-700)]/30'
                                        : 'bg-[var(--neutral-100)] text-[var(--fg-2)] border-[var(--border-subtle)]';
                                }
                            @endphp
                            <span class="min-w-0 overflow-hidden">
                                @if($actType)
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-[3px] border text-[11px] font-medium {{ $actClasses }}"
                                          title="{{ $actType->label() }}{{ $actAt ? ' · ' . $actAt->format('d.m.Y H:i') : '' }}">
                                        <span>{{ $actType->icon() }}</span>
                                        <span class="truncate">{{ $actType->label() }}</span>
                                    </span>
                                @else
                                    <span class="text-[var(--fg-4)] text-[11px]">—</span>
                                @endif
                            </span>

                            {{-- manager + (optional) acting badge --}}
                            @php
                                // Phase 2 delegation: для текущего юзера если он
                                // acting в active delegation — показываем badge
                                // «временно от @{owner}».
                                $authId = auth()->id();
                                $activeDelegation = $req->relationLoaded('activeDelegations')
                                    ? $req->activeDelegations->first()
                                    : null;
                                $iAmActing = $activeDelegation && $activeDelegation->acting_user_id === $authId;
                            @endphp
                            <span class="inline-flex items-center gap-1.5 min-w-0">
                                @if($managerName)
                                    <span class="w-[22px] h-[22px] rounded-full bg-[var(--neutral-200)] text-[var(--fg-2)] font-semibold text-[10px] leading-[22px] text-center flex-shrink-0">{{ $managerInitials }}</span>
                                    <span class="text-[var(--fg-2)] truncate">{{ $managerName }}</span>
                                @else
                                    <span class="w-[22px] h-[22px] rounded-full bg-[var(--bg-app)] border border-dashed border-[var(--border-strong)] text-[var(--fg-3)] font-semibold text-[10px] leading-[20px] text-center flex-shrink-0">?</span>
                                    <span class="text-[var(--fg-3)] truncate">не назначено</span>
                                @endif
                                @if($iAmActing)
                                    <span class="inline-flex items-center px-1.5 rounded-sm bg-amber-50 text-amber-700 font-semibold text-[10.5px] flex-shrink-0"
                                          title="Заявка открыта вам на время отсутствия {{ $activeDelegation->originalUser?->name ?? $managerName }}">
                                        ↺ открыто мне
                                    </span>
                                @endif
                            </span>

                            {{-- client --}}
                            <span class="min-w-0 overflow-hidden">
                                <div class="font-medium text-[var(--fg-1)] truncate">{{ $clientLine1 ?: '—' }}</div>
                                @if($clientLine2)
                                    <div class="text-[11.5px] text-[var(--fg-3)] mt-0.5 truncate">{{ $clientLine2 }}</div>
                                @endif
                            </span>

                            {{-- сумма (Phase 3) --}}
                            <span class="text-right text-[var(--fg-4)] font-medium tnum" title="{{ $disabledTitle }}">—</span>

                            {{-- возраст --}}
                            <span class="text-right font-mono text-[12px] {{ $ageColor }}">{{ $age ?: '—' }}</span>

                            {{-- ⋯ menu (Phase 2) --}}
                            <span class="text-center text-[var(--fg-4)] font-bold tracking-widest cursor-not-allowed"
                                  title="{{ $disabledTitle }}">···</span>
                        </a>
                    @endforeach
                @endforeach
            @endif
        </div>

        {{-- FOOTER --}}
        @if($page->total() > 0)
            <div class="h-[36px] flex items-center gap-3.5 px-5 border-t border-[var(--border)] bg-[var(--bg-surface-2)] font-medium text-[11.5px] text-[var(--fg-3)] tnum">
                <span>{{ $page->firstItem() }}–{{ $page->lastItem() }} из {{ $page->total() }}</span>
                <span class="text-[var(--border-strong)]">·</span>
                <span class="text-[var(--fg-4)]" title="{{ $disabledTitle }}">выделено —</span>
                <span class="flex-1"></span>
                <div class="flex items-center gap-2">
                    {{ $page->onEachSide(1)->links() }}
                </div>
                <span class="text-[var(--border-strong)]">·</span>
                <span>25 / стр.</span>
            </div>
        @endif
    </section>
</div>
