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
    // Менеджер по рекламациям (без других ролей): только заявки на чтение и
    // каталог — дашборда, клиентов и поставщиков у роли нет.
    $isClaimsOnly = $railUser?->hasRole('claims_manager')
        && ! $railUser->hasAnyRole(['manager', 'head_of_sales', 'secretary', 'director', 'admin', 'procurement']);

    // Единый список левого rail для всех страниц с 3-col shell.
    // active маркируется по значению атрибута компонента, не по route()
    // — позволяет вручную задать «к какому разделу относится текущая
    // страница» (на /dashboard/catalog/search active='catalog'
    // даже если внутренний роут другой).
    $rail = array_values(array_filter([
        $isClaimsOnly ? null : ['icon' => '⌂', 'label' => 'Дашборд', 'href' => route('dashboard'), 'key' => 'dashboard'],
        ['icon' => '≡', 'label' => 'Заявки',           'href' => route('requests.index'),   'key' => 'requests'],
        ['icon' => '⌕', 'label' => 'Поиск по каталогу', 'href' => route('catalog.search'),   'key' => 'catalog'],
    ]));

    if ($railUser?->hasAnyRole(['manager', 'head_of_sales', 'admin', 'director'])) {
        // Менеджер / РОП / админ / директорат — почтовый клиент. У менеджера
        // видимость = свои ящики; у РОПа/админа/директората — переключалка по ВСЕМ.
        $rail[] = ['icon' => '✉', 'label' => 'Почта', 'href' => route('mail.inbox'), 'key' => 'mail'];
    } elseif ($canSeeMail) {
        // Секретарь — org-wide витрина всей почты (надзор).
        $rail[] = ['icon' => '✉', 'label' => 'Почта', 'href' => route('mail.index'), 'key' => 'mail'];
    }

    if ($canSeeInvoices) {
        $rail[] = ['icon' => '₽', 'label' => 'Счета', 'href' => route('invoices.index'), 'key' => 'invoices'];
    }

    if (! $isClaimsOnly) {
        // «Клиенты» — реестр организаций/контактов. Доступен всем ролям, кроме рекламаций.
        $rail[] = ['icon' => '◈', 'label' => 'Клиенты', 'href' => route('clients.index'), 'key' => 'clients'];

        // «Поставщики» — запросы расценки поставщикам (SupplierInquiry). Все роли, кроме рекламаций.
        $rail[] = ['icon' => '◇', 'label' => 'Поставщики', 'href' => route('suppliers.index'), 'key' => 'suppliers'];
    }

    // «Снабжение» — топ позиций, сдерживающих выдачу КП + запросы поставщикам.
    if ($canSeeProcurement) {
        $rail[] = ['icon' => '⛏', 'label' => 'Снабжение', 'href' => route('procurement.index'), 'key' => 'procurement'];
    }

    // «Отчёты» — еженедельные персональные отчёты. Менеджер — свои, РОП/директор/
    // админ — все (гейт продублирован на роуте и в компоненте).
    if ($railUser?->hasAnyRole(['manager', 'head_of_sales', 'director', 'admin'])) {
        $rail[] = ['icon' => '▤', 'label' => 'Отчёты', 'href' => route('reports.weekly'), 'key' => 'reports'];
    }

    // «Честный знак» — коды маркировки из PDF в файл поставки.
    // Директорат / РОП / секретарь / снабжение / админ (гейт продублирован на роуте).
    if ($railUser?->hasAnyRole(['head_of_sales', 'director', 'secretary', 'procurement', 'admin'])) {
        $rail[] = ['icon' => '⧉', 'label' => 'Честный знак', 'href' => route('honest-sign.index'), 'key' => 'honest-sign'];
    }

    // «Синхронизация каталога» — выгрузка 1С с актуализированными ценами и остатками. Видна всем;
    // загружает и применяет секретарь и руководство (гейт — на роуте загрузки и в компоненте).
    if ($railUser !== null) {
        $rail[] = ['icon' => '⟳', 'label' => 'Синхронизация каталога', 'href' => route('catalog-sync.index'), 'key' => 'catalog-sync'];
    }

    // Phase 2 placeholder'ы — disabled (без href), показываются для
    // структуры UI.
    $railDisabled = [
        ['icon' => '⌗', 'label' => 'Правила'],
        ['icon' => '▦', 'label' => 'KB'],
        ['sep' => true],
        ['icon' => '◷', 'label' => 'SLA'],
    ];

    // Иконки — Lucide outline, stroke 1.5 (design/README.md → Iconography), 20px.
    // До 29.10.2026 были символы шрифта 13px — мелкие и неочевидные. Ключ —
    // символ из списка выше, чтобы не переписывать сам список.
    $railSvg = [
        '⌂' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        '≡' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        '⌕' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        '✉' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        '₽' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M9 7h4a2.5 2.5 0 0 1 0 5H9"/><path d="M9 7v10"/><path d="M8 15h5"/>',
        '◈' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        '◇' => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
        '⛏' => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><polyline points="3.29 7 12 12 20.71 7"/><path d="m7.5 4.27 9 5.15"/>',
        '▤' => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        '⧉' => '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M8 7v10"/><path d="M12 7v10"/><path d="M17 7v10"/>',
        '⟳' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        '⌗' => '<path d="m3 17 2 2 4-4"/><path d="m3 7 2 2 4-4"/><path d="M13 6h8"/><path d="M13 12h8"/><path d="M13 18h8"/>',
        '▦' => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
        '◷' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        '⚙' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
    ];
    $svg = fn (string $glyph) => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($railSvg[$glyph] ?? '').'</svg>';

    // Подпись под иконкой — короче полного названия (оно остаётся в подсказке).
    $caption = [
        'Поиск по каталогу' => 'Каталог',
        'Синхронизация каталога' => 'Синхр. каталога',
    ];

    // Стили — inline: новые Tailwind-классы без пересборки CSS на проде не работают.
    // Колонка закреплена под шапкой (nav sticky top-0, высота --topbar-h) и
    // прокручивается сама, если пунктов больше, чем влезает по высоте.
    $itemStyle = 'width: 64px; min-height: 52px; padding: 6px 2px 5px; gap: 3px;';
    $captionStyle = 'font-size: 10.5px; line-height: 1.15; font-weight: 500; text-align: center; max-width: 62px;';
@endphp

<aside class="border-r border-[var(--border)] bg-[var(--bg-sidebar)] flex flex-col items-center py-2 gap-0.5"
       style="position: sticky; top: var(--topbar-h); height: calc(100vh - var(--topbar-h)); overflow-y: auto; overflow-x: hidden; align-self: start; z-index: 20;">
    @foreach($rail as $r)
        @php
            $isActive = $active === $r['key'];
            $base = 'rounded-md flex flex-col items-center justify-center relative shrink-0';
            $cls = $isActive
                ? "$base text-[var(--accent)] bg-[var(--bg-surface)]"
                : "$base text-[var(--fg-2)] hover:text-[var(--fg-1)] hover:bg-[var(--bg-hover)]";
        @endphp
        <a href="{{ $r['href'] }}" class="{{ $cls }}" style="{{ $itemStyle }}" title="{{ $r['label'] }}">
            @if($isActive)<span class="absolute top-2 bottom-2 w-0.5 bg-[var(--accent)] rounded-r" style="left: -4px;"></span>@endif
            {!! $svg($r['icon']) !!}
            <span style="{{ $captionStyle }}">{{ $caption[$r['label']] ?? $r['label'] }}</span>
            @if($r['key'] === 'mail')
                {{-- Число выставляет опрос новой почты (layouts/app.blade.php). --}}
                <span class="rail-mail-badge" data-mail-signal-badge hidden style="top: 3px; right: 10px;"></span>
            @endif
        </a>
    @endforeach

    @foreach($railDisabled as $r)
        @if(isset($r['sep']))
            <div class="w-6 h-px bg-[var(--border)] my-1.5 shrink-0"></div>
        @else
            <div class="rounded-md flex flex-col items-center justify-center text-[var(--fg-4)] cursor-not-allowed opacity-60 shrink-0"
                 style="{{ $itemStyle }}"
                 title="{{ $r['label'] }} — {{ $disabledTitle }}">
                {!! $svg($r['icon']) !!}
                <span style="{{ $captionStyle }}">{{ $r['label'] }}</span>
            </div>
        @endif
    @endforeach

    <div class="mt-auto"></div>
    <div class="w-6 h-px bg-[var(--border)] my-1.5 shrink-0"></div>
    <div class="rounded-md flex flex-col items-center justify-center text-[var(--fg-4)] cursor-not-allowed opacity-60 shrink-0"
         style="{{ $itemStyle }}"
         title="Настройки — {{ $disabledTitle }}">
        {!! $svg('⚙') !!}
        <span style="{{ $captionStyle }}">Настройки</span>
    </div>
</aside>
