<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'MyLift CRM') }}</title>

        {{-- Шрифты дизайн-системы. Inter — основной UI; JetBrains Mono — коды/таймстампы. --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased bg-app text-fg-1">
        <div class="min-h-screen flex flex-col">
            @include('layouts.navigation')

            {{-- Опциональная sub-шапка для legacy-страниц (профиль, mail-rules).
                 Новые экраны (dashboard, requests) рисуют subnav внутри себя. --}}
            @isset($header)
                <header class="bg-surface border-b border-border-subtle">
                    <div class="max-w-[1440px] mx-auto px-6 py-3">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            @php
                // Auto-detect active по route name если не передано явно.
                $resolvedRailActive = ($railActive ?? null) ?: match (true) {
                    request()->routeIs('dashboard')         => 'dashboard',
                    request()->routeIs('requests.*')        => 'requests',
                    request()->routeIs('catalog.*')         => 'catalog',
                    request()->routeIs('mail.index')        => 'mail',
                    request()->routeIs('invoices.*')        => 'invoices',
                    default                                  => null,
                };
                $showRail = ($rail ?? true);
            @endphp

            @auth
                @if($showRail)
                    {{-- Глобальный rail (56px) + main. Wrapper'ы могут отключить
                         через :rail="false" если строят свой grid с доп. колонками
                         (pool — rail+list+main, catalog.search — rail+main внутри). --}}
                    <main class="flex-1 grid" style="grid-template-columns: 56px 1fr; min-height: calc(100vh - var(--topbar-h));">
                        <x-left-rail :active="$resolvedRailActive" />
                        <div class="min-w-0">
                            {{ $slot }}
                        </div>
                    </main>
                @else
                    <main class="flex-1">
                        {{ $slot }}
                    </main>
                @endif
            @else
                <main class="flex-1">
                    {{ $slot }}
                </main>
            @endauth
        </div>
    </body>
</html>
