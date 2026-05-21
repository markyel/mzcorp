{{--
    Copy-to-clipboard кнопка. Использование:
        <x-copy-button :value="$mylinkSku" />
        <x-copy-button value="M01935" />
        <x-copy-button :value="$sku" title="Скопировать M-артикул" />

    Параметры:
        $value (required) — что копируем в буфер.
        $title (optional) — tooltip. По умолчанию «Скопировать в буфер».
        $size (optional)  — 'sm' (default 16px) | 'md' (18px).

    Alpine-state, без Livewire round-trip. На успех на ~1.5с
    подсвечивается «✓ скопировано».
--}}
@props([
    'value' => '',
    'title' => 'Скопировать в буфер',
    'size' => 'sm',
])

@php
    $dims = $size === 'md' ? 'h-[18px] px-1.5 text-[12px]' : 'h-[16px] px-1 text-[10.5px]';
@endphp

<button type="button"
        x-data="{ copied: false, copyValue: @js((string) $value) }"
        x-on:click.stop.prevent="
            const text = copyValue;
            const finish = () => { copied = true; setTimeout(() => copied = false, 1500); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(finish).catch(() => {
                    // Fallback на legacy execCommand.
                    const ta = document.createElement('textarea');
                    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                    document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy'); finish(); } catch (e) {}
                    document.body.removeChild(ta);
                });
            } else {
                const ta = document.createElement('textarea');
                ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); finish(); } catch (e) {}
                document.body.removeChild(ta);
            }
        "
        x-bind:title="copied ? '✓ скопировано' : @js($title)"
        x-bind:class="copied
            ? 'inline-flex items-center justify-center {{ $dims }} rounded-sm border bg-emerald-50 border-emerald-300 text-emerald-700 transition-colors'
            : 'inline-flex items-center justify-center {{ $dims }} rounded-sm border bg-app border-border text-fg-3 hover:bg-surface-2 hover:text-fg-1 transition-colors'"
        class="inline-flex items-center justify-center {{ $dims }} rounded-sm border bg-app border-border text-fg-3 hover:bg-surface-2 hover:text-fg-1 transition-colors">
    <span x-show="!copied">📋</span>
    <span x-show="copied" x-cloak>✓</span>
</button>
