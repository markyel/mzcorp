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
    // «Снабжение» — раздел снабженца (топ позиций-блокеров КП + запросы
    // поставщикам по M-артикулу). Снабжение + менеджер (частый инициатор) +
    // РОП/директор/админ. Секретарю не показываем.
    $canSeeProcurement = $railUser?->hasAnyRole(['procurement', 'manager', 'head_of_sales', 'director', 'admin']);

    // Единый список левого rail для всех страниц с 3-col shell.
    // active маркируется по значению атрибута компонента, не по route()
    // — позволяет вручную задать «к какому разделу относится текущая
    // страница» (на /dashboard/catalog/search active='catalog'
    // даже если внутренний роут другой).
    $rail = [
        ['icon' => '⌂', 'label' => 'Дашборд',          'href' => route('dashboard'),        'key' => 'dashboard'],
        ['icon' => '≡', 'label' => 'Заявки',           'href' => route('requests.index'),   'key' => 'requests'],
        ['icon' => '⌕', 'label' => 'Поиск по каталогу', 'href' => route('catalog.search'),   'key' => 'catalog'],
    ];

    if ($canSeeMail) {
        $rail[] = ['icon' => '✉', 'label' => 'Почта', 'href' => route('mail.index'), 'key' => 'mail'];
    } elseif ($railUser?->hasRole('manager')) {
        // Менеджеру раздел «Почта» = переписка недоступных коллег (не-заявочная),
        // где видны назначенные ему письма. Своей общей почты у менеджера нет.
        $rail[] = ['icon' => '✉', 'label' => 'Почта', 'href' => route('mail.absent'), 'key' => 'mail'];
    }

    if ($canSeeInvoices) {
        $rail[] = ['icon' => '₽', 'label' => 'Счета', 'href' => route('invoices.index'), 'key' => 'invoices'];
    }

    // «Клиенты» — реестр организаций/контактов. Доступен всем ролям.
    $rail[] = ['icon' => '◈', 'label' => 'Клиенты', 'href' => route('clients.index'), 'key' => 'clients'];

    // «Поставщики» — запросы расценки поставщикам (SupplierInquiry). Все роли.
    $rail[] = ['icon' => '◇', 'label' => 'Поставщики', 'href' => route('suppliers.index'), 'key' => 'suppliers'];

    // «Снабжение» — топ позиций, сдерживающих выдачу КП + запросы поставщикам.
    if ($canSeeProcurement) {
        $rail[] = ['icon' => '⛏', 'label' => 'Снабжение', 'href' => route('procurement.index'), 'key' => 'procurement'];
    }

    // «Честный знак» — коды маркировки из PDF в файл поставки.
    // Директорат / РОП / секретарь / админ (гейт продублирован на роуте).
    if ($railUser?->hasAnyRole(['head_of_sales', 'director', 'secretary', 'admin'])) {
        $rail[] = ['icon' => '⧉', 'label' => 'Честный знак', 'href' => route('honest-sign.index'), 'key' => 'honest-sign'];
    }

    // Phase 2 placeholder'ы — disabled (без href), показываются для
    // структуры UI.
    $railDisabled = [
        ['icon' => '⌗', 'label' => 'Правила'],
        ['icon' => '▦', 'label' => 'KB'],
        ['sep' => true],
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
