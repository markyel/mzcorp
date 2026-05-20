@props([
    /** Активный раздел: 'dashboard' | 'requests' | 'catalog' | null. */
    'active' => null,
])
@php
    $disabledTitle = 'Доступно в Phase 2';

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

    // Phase 2 placeholder'ы — disabled (без href), показываются для
    // структуры UI.
    $railDisabled = [
        ['icon' => '✉', 'label' => 'Почта'],
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
