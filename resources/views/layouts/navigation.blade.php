@php
    /**
     * Top-bar 48px: brand wordmark + workspace pill + nav links + user menu.
     * Дизайн-токены тянутся через Tailwind config (см. tailwind.config.js).
     * Левый rail (56px) добавим в Phase 2, когда появятся вторичные scopes.
     */
    $user = auth()->user();

    $mailboxesActive = \App\Models\Mailbox::query()->where('is_active', true)->count();
    $mailboxesError  = \App\Models\Mailbox::query()
        ->where('is_active', true)
        ->whereNotNull('last_error_at')
        ->whereColumn('last_error_at', '>', \Illuminate\Support\Facades\DB::raw('COALESCE(last_synced_at, \'1970-01-01\')'))
        ->count();
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

    $navLinks = [];
    $navLinks[] = ['route' => 'dashboard', 'label' => 'Дашборд', 'pattern' => 'dashboard'];
    if ($user?->hasAnyRole(['manager', 'head_of_sales', 'director', 'secretary'])) {
        $navLinks[] = ['route' => 'requests.index', 'label' => 'Заявки', 'pattern' => 'requests.*'];
    }
    if ($user?->hasAnyRole(['head_of_sales', 'director'])) {
        $navLinks[] = ['route' => 'mail-rules.index', 'label' => 'Правила почты', 'pattern' => 'mail-rules.*'];
    }
@endphp

<nav class="bg-surface border-b border-border sticky top-0 z-30" style="height: var(--topbar-h)">
    <div class="h-full max-w-[1440px] mx-auto px-4 flex items-center gap-3">

        {{-- Brand --}}
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 shrink-0" aria-label="MyLift CRM">
            <img src="{{ asset('images/mylift-wordmark.svg') }}" alt="MyLift CRM" class="h-6 w-auto">
        </a>

        {{-- Workspace pill --}}
        @auth
            <div class="hidden sm:flex items-center gap-1.5 px-2.5 py-[5px] border border-border rounded-md text-fg-2"
                 style="font-size: 12.5px"
                 title="Активных ящиков: {{ $mailboxesActive }}{{ $mailboxesError ? ', с ошибкой: '.$mailboxesError : '' }}">
                <span class="inline-block w-1.5 h-1.5 rounded-full {{ $workspaceDot }}"></span>
                <span>{{ $mailboxesActive }} {{ $boxLabel }}</span>
            </div>
        @endauth

        {{-- Nav links --}}
        @auth
            <div class="hidden sm:flex items-center gap-0.5 ml-1">
                @foreach($navLinks as $link)
                    @php $active = request()->routeIs($link['pattern']); @endphp
                    <a href="{{ route($link['route']) }}"
                       class="relative px-3 py-2 text-sm rounded-md transition-colors
                              {{ $active ? 'text-fg-1 font-semibold' : 'text-fg-2 hover:text-fg-1 hover:bg-hover' }}">
                        {{ $link['label'] }}
                        @if($active)
                            <span class="absolute left-3 right-3 -bottom-px h-0.5 bg-accent rounded-full"></span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endauth

        <div class="flex-1"></div>

        {{-- User menu --}}
        @auth
            <div class="hidden sm:block">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button type="button"
                                class="inline-flex items-center gap-2 px-2 py-1 text-sm text-fg-2 hover:text-fg-1 hover:bg-hover rounded-md transition-colors">
                            <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-neutral-200 text-fg-1 font-semibold text-xs">
                                {{ \Illuminate\Support\Str::of($user?->name ?? '?')->substr(0, 1)->upper() }}
                            </span>
                            <span class="hidden md:inline">{{ $user?->name }}</span>
                            <svg class="fill-current w-3 h-3 text-fg-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                            </svg>
                        </button>
                    </x-slot>
                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">{{ __('Profile') }}</x-dropdown-link>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <x-dropdown-link :href="route('logout')"
                                onclick="event.preventDefault(); this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>
        @endauth
    </div>
</nav>
