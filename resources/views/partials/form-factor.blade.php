{{-- Форм-фактор позиции каталога: схема габаритов (A, B, C… — как размеры позиции) вместо кода; по клику — крупно
     во встроенном просмотрщике (open-image). Схемы нет — код, как раньше. Ожидает $ffCode, $ffSize (px, высота схемы),
     $ffChipClass (опц., оформление кода без схемы). Параметры с префиксом ff: @include отдаёт и переменные родителя. --}}
@php
    $ffUrl = \App\Models\CatalogItem::formFactorImage($ffCode ?? null);
    $ffSize = (int) ($ffSize ?? 32);
@endphp
@if($ffUrl)
    <a href="{{ $ffUrl }}" title="Форм-фактор {{ $ffCode }} — схема габаритов" class="inline-flex items-center gap-1 align-middle" style="cursor:zoom-in"
       x-data x-on:click.prevent.stop="$dispatch('open-image', {{ \Illuminate\Support\Js::from(['src' => $ffUrl, 'name' => 'Форм-фактор '.$ffCode, 'dl' => $ffUrl]) }})">
        <img src="{{ $ffUrl }}" alt="Форм-фактор {{ $ffCode }}" loading="lazy" width="{{ $ffSize }}" height="{{ $ffSize }}"
             style="width:{{ $ffSize }}px;height:{{ $ffSize }}px;object-fit:contain;background:#fff;border:1px solid var(--border-subtle, #e5e7eb);border-radius:4px">
    </a>
@elseif(! empty($ffCode))
    <span class="{{ $ffChipClass ?? 'inline-flex items-center px-1.5 rounded-sm bg-surface-2 text-fg-2' }}">{{ $ffCode }}</span>
@endif
