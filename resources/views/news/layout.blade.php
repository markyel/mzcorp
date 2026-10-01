<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('services.marketing.news.brand') }}</title>
    @hasSection('description')<meta name="description" content="@yield('description')">@endif
    @yield('meta')
    <link rel="alternate" type="application/rss+xml" title="{{ config('services.marketing.news.feed_title') }}" href="{{ route('news.rss') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">
    <style>
        /* Токены — из design/colors_and_type.css: один фирменный красный, холодные нейтральные. */
        :root {
            --bg: oklch(98.6% 0.003 250); --surface: #fff; --surface-2: oklch(96.8% 0.005 250);
            --fg-1: oklch(16% 0.020 250); --fg-2: oklch(34% 0.018 250); --fg-3: oklch(52% 0.015 250);
            --border: oklch(93.5% 0.007 250); --accent: #D32027; --ok: oklch(48% 0.130 160); --ok-bg: oklch(97% 0.030 160);
            --link: oklch(46% 0.155 235);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: oklch(17% 0.012 250); --surface: oklch(21% 0.014 250); --surface-2: oklch(25% 0.015 250);
                --fg-1: oklch(95% 0.005 250); --fg-2: oklch(84% 0.010 250); --fg-3: oklch(68% 0.012 250);
                --border: oklch(30% 0.015 250); --accent: oklch(66% 0.200 25); --ok: oklch(78% 0.110 160); --ok-bg: oklch(28% 0.045 160);
                --link: oklch(78% 0.110 235); color-scheme: dark;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg-1); font: 16px/1.65 Inter, "Segoe UI", Roboto, Arial, sans-serif; padding-inline: 16px; }
        .wrap { max-width: 760px; margin: 0 auto; }
        .top { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding-block: 20px; border-bottom: 1px solid var(--border); }
        .brand { font-weight: 700; font-size: 18px; color: var(--fg-1); text-decoration: none; }
        .brand b { color: var(--accent); }
        .top nav { display: flex; gap: 16px; font-size: 14px; }
        .top nav a { color: var(--fg-3); text-decoration: none; }
        .top nav a:hover { color: var(--fg-1); }
        main { padding-block: 32px 48px; }
        h1 { font-size: 30px; line-height: 1.2; margin: 0 0 10px; text-wrap: balance; }
        h2 { font-size: 20px; line-height: 1.3; margin: 36px 0 8px; text-wrap: balance; }
        .meta { color: var(--fg-3); font-size: 14px; margin-bottom: 20px; }
        .lead { font-size: 18px; color: var(--fg-2); margin: 0 0 8px; }
        p { margin: 0 0 12px; }
        a { color: var(--link); }
        .intro { color: var(--fg-2); }
        .items { display: grid; gap: 14px; margin-top: 12px; }
        .item { display: grid; grid-template-columns: 132px 1fr; gap: 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px; align-items: start; }
        .item img { width: 132px; height: 116px; object-fit: contain; background: #fff; border-radius: 8px; }
        .item .name { font-weight: 600; color: var(--fg-1); text-decoration: none; }
        .item .name:hover { text-decoration: underline; }
        .sku { font-family: "JetBrains Mono", Consolas, monospace; font-size: 13px; color: var(--fg-3); }
        .price { font-variant-numeric: tabular-nums; font-weight: 600; margin-top: 4px; }
        .stock { display: inline-block; font-size: 12px; font-weight: 600; padding: 1px 8px; border-radius: 999px; color: var(--ok); background: var(--ok-bg); margin-top: 4px; }
        .stock.order { color: var(--fg-3); background: var(--surface-2); }
        .note { color: var(--fg-2); font-size: 15px; margin: 6px 0 0; }
        .closing { margin-top: 28px; padding-top: 18px; border-top: 1px solid var(--border); color: var(--fg-2); }
        .preview { background: oklch(97.5% 0.030 85); color: oklch(45% 0.120 65); border-radius: 8px; padding: 8px 12px; font-size: 14px; margin-bottom: 18px; }
        .list { display: grid; gap: 0; }
        .list a.row { display: grid; gap: 4px; padding: 18px 0; border-bottom: 1px solid var(--border); text-decoration: none; color: inherit; }
        .list a.row b { font-size: 19px; line-height: 1.3; }
        .list a.row span { color: var(--fg-3); font-size: 14px; }
        footer { border-top: 1px solid var(--border); padding-block: 20px 32px; color: var(--fg-3); font-size: 14px; }
        @media (max-width: 520px) {
            h1 { font-size: 24px; }
            .item { grid-template-columns: 88px 1fr; gap: 12px; }
            .item img { width: 88px; height: 80px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <a class="brand" href="{{ route('news.index') }}"><b>{{ config('services.marketing.news.brand') }}</b> · обзор недели</a>
        <nav>
            <a href="{{ config('services.marketing.news.site_url') }}">Каталог</a>
            <a href="{{ route('news.rss') }}">RSS</a>
        </nav>
    </header>
    <main>
        @yield('content')
    </main>
    <footer>
        {{ config('services.marketing.news.brand') }} — запчасти для лифтов и эскалаторов.
        Каталог: <a href="{{ config('services.marketing.news.site_url') }}">{{ preg_replace('~^https?://(www\.)?~', '', config('services.marketing.news.site_url')) }}</a>,
        заявки: {{ config('services.marketing.news.email') }}.
    </footer>
</div>
</body>
</html>
