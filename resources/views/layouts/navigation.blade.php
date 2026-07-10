@php
    /**
     * Top-bar 48px по макету 03-requests.html:
     *   [brand] [workspace pill] [nav-links] [search ⌘K] ... [Import 1C] [+ Заявка] [🔔] [avatar]
     *
     * Глобальная nav (Дашборд/Заявки/Правила почты) пока остаётся в топбаре —
     * Phase 2 переедет в левый rail когда rail станет глобальным компонентом.
     * Search-form GET'ает в requests.index — фильтрует пул через URL-param `q`.
     * Phase 2 элементы (Import 1C / +Заявка / Bell) — disabled placeholder'ы.
     */
    $user = auth()->user();

    // Workspace-pill = «здоровье системы по общим ящикам». Показываем
    // только привилегированным ролям; менеджеру не нужен — он видит
    // чужой info@ и счётчик, который к нему отношения не имеет.
    $showWorkspacePill = $user?->hasAnyRole(['head_of_sales', 'director', 'secretary', 'admin']) ?? false;

    $workspaceLabel = null;
    $workspaceDot = 'bg-neutral-400';
    $mailboxesActive = 0;
    $mailboxesError = 0;
    if ($showWorkspacePill) {
        $mailboxesActive = \App\Models\Mailbox::query()->where('is_active', true)->count();
        $mailboxesError  = \App\Models\Mailbox::query()
            ->where('is_active', true)
            ->whereNotNull('last_error_at')
            ->whereColumn('last_error_at', '>', \Illuminate\Support\Facades\DB::raw('COALESCE(last_synced_at, \'1970-01-01\')'))
            ->count();
        $primaryMailbox = \App\Models\Mailbox::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->value('email');
        $workspaceDot = $mailboxesError > 0
            ? 'bg-amber-600'
            : ($mailboxesActive > 0 ? 'bg-emerald-600' : 'bg-neutral-400');

        // Склонение «ящик» — Russian plural rules.
        $boxLabel = (function (int $n): string {
            $mod10  = $n % 10;
            $mod100 = $n % 100;
            if ($mod100 >= 11 && $mod100 <= 14) return 'ящиков';
            if ($mod10 === 1) return 'ящик';
            if ($mod10 >= 2 && $mod10 <= 4) return 'ящика';
            return 'ящиков';
        })($mailboxesActive);

        $workspaceLabel = $primaryMailbox
            ? $primaryMailbox . ' · +' . max($mailboxesActive - 1, 0) . ' ' . $boxLabel
            : $mailboxesActive . ' ' . $boxLabel;
        if ($primaryMailbox && $mailboxesActive <= 1) {
            $workspaceLabel = $primaryMailbox;
        }
    }

    $navLinks = [];
    $navLinks[] = ['route' => 'dashboard', 'label' => 'Дашборд', 'pattern' => 'dashboard'];
    if ($user?->hasAnyRole(['manager', 'head_of_sales', 'director', 'secretary', 'admin'])) {
        $navLinks[] = ['route' => 'requests.index', 'label' => 'Заявки', 'pattern' => 'requests.*'];
        // «Каталог» вынесен в левый rail (resources/views/components/left-rail.blade.php),
        // в горизонтальный топбар не дублируется.
    }
    if ($user?->hasAnyRole(['head_of_sales', 'director', 'admin'])) {
        $navLinks[] = ['route' => 'mail-rules.index', 'label' => 'Правила почты', 'pattern' => 'mail-rules.*'];
        $navLinks[] = ['route' => 'sender-blocklist.index', 'label' => 'Стоп-лист', 'pattern' => 'sender-blocklist.*'];
    }
    // «Авто-отклонённые» — привилегированные + секретарь (контроль маршрутизации).
    if ($user?->hasAnyRole(['head_of_sales', 'director', 'admin', 'secretary'])) {
        $navLinks[] = ['route' => 'mail-review.index', 'label' => 'Авто-отклонённые', 'pattern' => 'mail-review.*'];
    }
    // «Аналитика» — метрики по менеджерам (РОП / директорат / секретарь / админ).
    if ($user?->hasAnyRole(['head_of_sales', 'director', 'admin', 'secretary'])) {
        $navLinks[] = ['route' => 'analytics.index', 'label' => 'Аналитика', 'pattern' => 'analytics.*'];
    }
    // «Использование» — статистика активности менеджеров (директорат / админ).
    if ($user?->hasAnyRole(['director', 'admin'])) {
        $navLinks[] = ['route' => 'usage-stats.index', 'label' => 'Использование', 'pattern' => 'usage-stats.*'];
    }
    // «IQOT» — анализ цен конкурентов (РОП / директорат / админ).
    if ($user?->hasAnyRole(['head_of_sales', 'director', 'admin'])) {
        $navLinks[] = ['route' => 'iqot.index', 'label' => 'IQOT', 'pattern' => 'iqot.*'];
    }
    if ($user?->hasAnyRole(['head_of_sales', 'director', 'admin'])) {
        $navLinks[] = ['route' => 'managers.index', 'label' => 'Менеджеры', 'pattern' => 'managers.*'];
        // Уведомления клиенту вынесены в подпункт страницы «Настройки»,
        // не дублируем в горизонтальном топбаре (Phase 6).
        $navLinks[] = ['route' => 'settings.index', 'label' => 'Настройки', 'pattern' => 'settings.*|notifications.*'];
    }
    if ($user?->hasRole('admin')) {
        // Подключение основной почты и активация/деактивация маршрутизации —
        // только для админа. Скрыто от РОПа/директора, чтобы случайно
        // не остановить распределение заявок.
        $navLinks[] = ['route' => 'mailboxes.index', 'label' => 'Ящики', 'pattern' => 'mailboxes.*'];
    }

    // Бейдж непрочитанных обновлений (раздел «Обновления», все роли).
    // Дешёвый индексируемый COUNT; nav рендерится на каждой странице.
    $updatesUnread = $user ? \App\Models\ChangelogEntry::unreadCountFor($user) : 0;

    $disabledTitle = 'Доступно в Phase 2';
    $userInitials = collect(preg_split('/\s+/u', trim((string) ($user?->name ?? '?'))))
        ->filter()
        ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
        ->take(2)
        ->implode('');
@endphp

<nav class="bg-surface border-b border-border sticky top-0 z-30" style="height: var(--topbar-h)">
    <div class="h-[48px] px-4 flex items-center gap-3">

        {{-- Brand --}}
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 shrink-0" aria-label="mzCorp CRM">
            <img src="{{ asset('images/mzcorp-emblem.png') }}" alt="mzCorp" style="height:28px;width:auto;display:block">
            <img src="{{ asset('images/mzcorp-wordmark.png') }}" alt="mzCorp" style="height:22px;width:auto;display:block">
        </a>

        {{-- Workspace pill — только для привилегированных ролей.
             Менеджер видит только nav-links (Дашборд / Заявки), без счётчика
             общих ящиков, который к нему отношения не имеет. --}}
        @auth
            @if($showWorkspacePill && $workspaceLabel !== null)
                <div class="hidden sm:inline-flex items-center gap-1.5 px-2.5 h-[26px] border border-[var(--border)] rounded-md text-[var(--fg-2)] whitespace-nowrap"
                     style="font-size: 12.5px"
                     title="Активных ящиков: {{ $mailboxesActive }}{{ $mailboxesError ? ', с ошибкой: '.$mailboxesError : '' }}">
                    <span class="inline-block w-1.5 h-1.5 rounded-full {{ $workspaceDot }}"></span>
                    <span>{{ $workspaceLabel }}</span>
                </div>
            @endif
        @endauth

        {{-- Nav links --}}
        @auth
            <div class="hidden md:flex items-center gap-0.5 ml-1 shrink-0">
                @foreach($navLinks as $link)
                    @php $active = request()->routeIs($link['pattern']); @endphp
                    <a href="{{ route($link['route']) }}"
                       class="relative px-3 py-2 text-[13px] rounded-md transition-colors
                              {{ $active ? 'text-[var(--fg-1)] font-semibold' : 'text-[var(--fg-2)] hover:text-[var(--fg-1)] hover:bg-[var(--bg-hover)]' }}">
                        {{ $link['label'] }}
                        @if($active)
                            <span class="absolute left-3 right-3 -bottom-px h-0.5 bg-[var(--accent)] rounded-full"></span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endauth

        <div class="flex-1"></div>

        {{-- Action buttons (Phase 2 placeholders) --}}
        @auth
            <button type="button" class="hidden md:inline-flex items-center gap-1.5 h-[30px] px-3 border border-[var(--border-strong)] rounded-md bg-[var(--bg-surface)] text-[var(--fg-1)] text-[12.5px] font-medium opacity-55 cursor-not-allowed"
                    disabled title="{{ $disabledTitle }}">Импорт из 1С</button>
            <button type="button" class="hidden md:inline-flex items-center gap-1.5 h-[30px] px-3 rounded-md bg-[var(--accent)] text-[var(--fg-on-accent)] border border-[var(--accent)] text-[12.5px] font-medium opacity-55 cursor-not-allowed"
                    disabled title="{{ $disabledTitle }}">+ Заявка вручную</button>

            {{-- Bell — Foundation Фаза 2 in-app notifications. --}}
            <livewire:notifications.bell wire:key="notif-bell-{{ $user->id ?? 'guest' }}" />

            {{-- Обновления — лента важных изменений системы (все роли).
                 Бейдж непрочитанных сбрасывается при открытии раздела
                 (Updates\Index::mount → users.updates_seen_at). --}}
            <a href="{{ route('updates.index') }}"
               class="relative inline-flex items-center justify-center w-8 h-8 rounded-md text-fg-2 hover:text-fg-1 hover:bg-[var(--bg-surface-2)]"
               title="Обновления{{ $updatesUnread > 0 ? ' — новых: '.$updatesUnread : '' }}">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="1.5"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m3 11 18-5v12L3 14v-3z"/>
                    <path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>
                </svg>
                @if($updatesUnread > 0)
                    <span class="absolute -top-0.5 -right-0.5 inline-flex items-center justify-center rounded-full bg-[var(--accent)] text-[var(--fg-on-accent)] font-semibold"
                          style="min-width:15px;height:15px;font-size:9.5px;padding:0 3px;line-height:15px;">{{ $updatesUnread > 9 ? '9+' : $updatesUnread }}</span>
                @endif
            </a>

            {{-- Документация — Lucide circle-help. Открывает /docs (Controller
                 редиректит на профильный раздел роли, если есть). --}}
            <a href="{{ route('docs.index') }}"
               class="relative inline-flex items-center justify-center w-8 h-8 rounded-md text-fg-2 hover:text-fg-1 hover:bg-[var(--bg-surface-2)]"
               title="Документация">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="1.5"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
                    <line x1="12" x2="12.01" y1="17" y2="17"/>
                </svg>
            </a>

            {{-- «Связь с создателем» — иконка ▲ с badge непрочитанных ответов.
                 Сам Livewire-компонент рендерит кнопку с data-support-trigger;
                 глобальный JS-делегат в layouts/app.blade.php собирает context
                 и диспатчит open-support-modal. --}}
            <livewire:support.trigger wire:key="support-trigger-{{ $user->id ?? 'guest' }}" />
        @endauth

        {{-- User avatar dropdown --}}
        @auth
            <x-dropdown align="right" width="48">
                <x-slot name="trigger">
                    <button type="button"
                            class="inline-flex items-center gap-2 px-1 py-1 text-sm text-[var(--fg-2)] hover:text-[var(--fg-1)] hover:bg-[var(--bg-hover)] rounded-md transition-colors">
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-[var(--neutral-300)] text-[var(--fg-1)] font-semibold text-[11px]">
                            {{ $userInitials ?: '?' }}
                        </span>
                        <svg class="fill-current w-3 h-3 text-[var(--fg-3)]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                        </svg>
                    </button>
                </x-slot>
                <x-slot name="content">
                    <x-dropdown-link :href="route('profile.edit')">{{ __('Profile') }}</x-dropdown-link>
                    <x-dropdown-link :href="route('docs.index')">Документация</x-dropdown-link>
                    <x-dropdown-link :href="route('support.my')">Мои обращения</x-dropdown-link>
                    @if($user?->hasRole('admin'))
                        <x-dropdown-link :href="route('support.inbox')">Обращения · инбокс</x-dropdown-link>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-dropdown-link :href="route('logout')"
                            onclick="event.preventDefault(); this.closest('form').submit();">
                            {{ __('Log Out') }}
                        </x-dropdown-link>
                    </form>
                </x-slot>
            </x-dropdown>
        @endauth
    </div>

    {{-- Строка поиска — отдельная вторая строка топбара во всю ширину:
         в первой строке поиск сжимался nav-ссылками до ~100px. Высоты:
         48 (row1) + 1 (border-t) + 38 (row2) + 1 (border-b nav) = 88px
         = var(--topbar-h). ⌘K / Ctrl+K фокусируют поле. --}}
    @auth
        <div class="h-[38px] px-4 border-t border-border-subtle flex items-center">
            <form method="GET" action="{{ route('requests.index') }}"
                  class="w-full max-w-[960px] relative"
                  x-data
                  @keydown.window.meta.k.prevent="$refs.q.focus()"
                  @keydown.window.ctrl.k.prevent="$refs.q.focus()">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-[var(--fg-3)] text-[14px] pointer-events-none select-none">⌕</span>
                <input type="search" name="q" x-ref="q"
                       value="{{ request()->routeIs('requests.*') ? request()->query('q') : '' }}"
                       placeholder="Поиск по заявкам, клиентам, артикулам, № счёта / КП…"
                       class="w-full h-[28px] pl-8 pr-12 border border-[var(--border)] rounded-md bg-[var(--bg-app)] text-[var(--fg-1)] text-[13px] outline-none focus:border-[var(--sky-500)]">
                <kbd class="hidden md:block absolute right-2 top-[5px] font-mono text-[10.5px] font-medium text-[var(--fg-3)] border border-[var(--border)] px-1 py-0.5 rounded bg-[var(--bg-surface)]">⌘ K</kbd>
            </form>
        </div>
    @endauth
</nav>
