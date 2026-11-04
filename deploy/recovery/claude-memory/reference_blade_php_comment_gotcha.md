---
name: reference_blade_php_comment_gotcha
description: "Грабля: Blade-комментарий {{-- --}} внутри @php-блока = PHP syntax error → 500 (особенно с кириллицей)"
metadata:
  node_type: memory
  type: reference
---

**НЕ вставлять `{{-- ... --}}` внутри `@php ... @endphp`.** Внутри `@php` это сырой PHP, и `{{-- текст --}}` парсится как PHP → фатальная syntax error → 500 на всей странице (Blade view compile). С кириллицей особенно: `syntax error, unexpected identifier "только"`. Внутри `@php` использовать PHP-комментарии `//` или `/* */`. Кейс 2026-08-19: правка `suppliers/show.blade.php` (фильтр инлайн-PDF) уронила `/dashboard/suppliers/*` 500-й. Проверять blade после правки: `php artisan view:cache` на проде (компилит ВСЕ шаблоны, ловит syntax error; затем `view:clear`). Связано: [[reference_tailwind_arbitrary_classes]] (другая blade/деплой-грабля).
