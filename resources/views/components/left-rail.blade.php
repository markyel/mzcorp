@props([
    /** Активный раздел: 'dashboard' | 'requests' | 'catalog' | 'mail' | 'invoices' | null. */
    'active' => null,
])
@php
    $disabledTitle = 'Доступно в Phase 2';

    $railUser = auth()->user();
    // «Почта» — read-only листинг всей почты по всем ящикам, доступен
    // head_of_sales / secretary / director / admin. Менеджерам не
    // показываем — у них своя карточка заявки с тред-табом.
    $canSeeMail = $railUser?->hasAnyRole(['head_of_sales', 'secretary', 'director', 'admin']);
    // «Счета» — все авторизованные роли. Менеджер видит scope='mine'
    // (только свои Invoice через request.assigned_user_id), привилегированные
    // — scope='all'. Фильтр scope принудительно ограничивается в Livewire.
    $canSeeInvoices = $railUser?->hasAnyRole(['manager', 'head_of_sales', 'secretary', 'director', 'admin']);
    // «Автозакрытые» — пул заявок, которые LLM закрыл как parser_no_content.
    // Видят head_of_sales / director / admin / secretary — могут восстановить.
    $canSeeAutoClosed = $railUser?->hasAnyRole(['head_of_sales', 'director', 'admin', 'secretary']);
    $autoClosedCount = 0;
    if ($canSeeAutoClosed) {
        $autoClosedCount = \App\Models\Request::query()
            ->whereNull('assigned_user_id')
            ->where('status', \App\Enums\RequestStatus::ClosedLost->value)
            ->where('closed_lost_reason', \App\Enums\ClosedLostReason::ParserNoContent->value)
            ->where('closed_at', '>=', now()->subDays(30))
            ->count();
    }

    // Единый список левого rail для всех страниц с 3-col shell.
    // active маркируется по значению атрибута компонента, не по route()
    // — позволяет вручную задать «к какому разделу относится текущая
    // страница» (на /dashboard/catalog/search active='catalog'
    // даже если внутренний роут другой).
    $rail = [
        ['icon' => '⌂', 'label' => 'Дашборд',          'href' => route('dashboard'),        'key' => 'dashboard'],
        ['icon' => '≡', 'label' => 'Заявки',           'href' => route('requests.index'),   'key' => 'requests'],
    ];

    if ($canSeeAutoClosed) {
        $rail[] = [
            'icon' => '↺',
            'label' => 'Автозакрытые' . ($autoClosedCount > 0 ? ' (' . $autoClosedCount . ')' : ''),
            'href' => route('requests.auto-closed'),
            'key' => 'auto-closed',
            'badge' => $autoClosedCount,
        ];
    }

    $rail[] = ['icon' => '⌕', 'label' => 'Поиск по каталогу', 'href' => route('catalog.search'),   'key' => 'catalog'];

    if ($canSeeMail) {
        $rail[] = ['icon' => '✉', 'label' => 'Почта', 'href' => route('mail.index'), 'key' => 'mail'];
    }

    if ($canSeeInvoices) {
        $rail[] = ['icon' => '₽', 'label' => 'Счета', 'href' => route('invoices.index'), 'key' => 'invoices'];
    }

    // Phase 2 placeholder'ы — disabled (без href), показываются для
    // структуры UI.
    $railDisabled = [
        ['icon' => '⌗', 'label' => 'Правила'],
        ['icon' => '▦', 'label' => 'KB'],
        ['sep' => true],
        ['icon' => '◇', 'label' => 'Поставщики'],
        ['icon' => '◷', 'label' => 'SLA'],
    ];
@endphp

<aside class="border-r border-[var(--border)] bg-[var(--bg-sidebar)] flex flex-col items-center py-2 gap-0.5">
    @foreach($rail as $r)
        @php
            $isActive = $active === $r['key'];
            $base = 'w-10 h-10 rounded-md flex items-center justify-center font-mono text-[13px] font-semibold relative';
            $cls = $isActive
                ? "$base text-[var(--accent)] bg-[var(--bg-surface)]"
                : "$base text-[var(--fg-3)] hover:text-[var(--fg-1)] hover:bg-[var(--bg-hover)]";
        @endphp
        <a href="{{ $r['href'] }}" class="{{ $cls }}" title="{{ $r['label'] }}">
            @if($isActive)<span class="absolute -left-2 top-2 bottom-2 w-0.5 bg-[var(--accent)] rounded-r"></span>@endif
            {{ $r['icon'] }}
            @if(!empty($r['badge']))
                <span class="absolute -top-0.5 -right-0.5 min-w-[14px] h-[14px] px-1 rounded-full bg-amber-500 text-white text-[9px] font-bold flex items-center justify-center leading-none">{{ $r['badge'] > 99 ? '99+' : $r['badge'] }}</span>
            @endif
        </a>
    @endforeach

    @foreach($railDisabled as $r)
        @if(isset($r['sep']))
            <div class="w-6 h-px bg-[var(--border)] my-1.5"></div>
        @else
            <div class="w-10 h-10 rounded-md flex items-center justify-center font-mono text-[13px] font-semibold text-[var(--fg-4)] cursor-not-allowed opacity-60"
                 title="{{ $r['label'] }} — {{ $disabledTitle }}">{{ $r['icon'] }}</div>
        @endif
    @endforeach

    <div class="mt-auto"></div>
    <div class="w-6 h-px bg-[var(--border)] my-1.5"></div>
    <div class="w-10 h-10 rounded-md flex items-center justify-center font-mono text-[13px] font-semibold text-[var(--fg-4)] cursor-not-allowed opacity-60"
         title="Настройки — {{ $disabledTitle }}">⚙</div>
</aside>
