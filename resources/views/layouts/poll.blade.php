{{-- Страницы опроса «Фото для календаря» для сотрудников, в том числе вне MyLift: без меню системы, во всю ширину
     (галерея). Просмотрщик фото — общий partials.image-lightbox (листание, масштаб). --}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Фото для календаря') · Мой ЗиП</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body style="margin: 0; background: var(--bg-app, #f5f6f8); color: var(--fg-1); font-family: var(--font-sans, Arial, sans-serif);">
    <header style="background: var(--bg-surface, #fff); border-bottom: 1px solid var(--border-subtle);">
        <div style="max-width: 1240px; margin: 0 auto; padding: 10px 16px; display: flex; align-items: center; gap: 12px;">
            <img src="{{ asset('images/mzcorp-logo.png') }}?v=3" alt="Мой ЗиП" style="height: 32px; width: auto;">
            <span style="font-size: 15px; font-weight: 600;">@yield('title', 'Фото для календаря')</span>
        </div>
    </header>
    <main style="max-width: 1240px; margin: 0 auto; padding: 16px;">
        @yield('content')
    </main>
    @include('partials.image-lightbox')
</body>
</html>
