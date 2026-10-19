{{-- Ссылка на вложение — всегда во встроенном просмотрщике, не в новой вкладке:
       картинка → лайтбокс (open-image), PDF → предпросмотр PDF (open-pdf), остальное (Excel, Word, архив) → скачать.
     Оба просмотрщика подключены в layouts.app. Ожидает $url (просмотр, inline), $name, $mime (опц.),
     Необязательные параметры — с префиксом file*: @include отдаёт шаблону и переменные родителя ($label, $dl…), чужие не должны подмешиваться.
     $fileDl (опц., адрес «Скачать», по умолчанию $url), $fileClass (опц.), $fileStyle (опц.), $fileLabel (опц., HTML вместо имени). --}}
@php
    $mime = (string) ($mime ?? '');
    $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
    $isImage = (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    $isPdf = $mime === 'application/pdf' || $ext === 'pdf';
    $dl = $fileDl ?? $url;
    $class = $fileClass ?? 'chip chip-neutral max-w-[260px] truncate';
    $style = $fileStyle ?? null;
    $label = $fileLabel ?? null;
@endphp
@if($isImage)
    <a href="{{ $url }}" title="{{ $name }}" class="{{ $class }}" style="cursor:zoom-in;{{ $style }}"
       x-on:click.prevent="$dispatch('open-image', {{ \Illuminate\Support\Js::from(['src' => $url, 'name' => $name, 'dl' => $dl]) }})">{!! $label ?? '🖼 '.e($name) !!}</a>
@elseif($isPdf)
    <a href="{{ $url }}" title="{{ $name }}" class="{{ $class }}" style="cursor:pointer;{{ $style }}"
       x-on:click.prevent="$dispatch('open-pdf', {{ \Illuminate\Support\Js::from(['src' => $url, 'name' => $name, 'dl' => $dl]) }})">{!! $label ?? '📄 '.e($name) !!}</a>
@else
    <a href="{{ $dl }}" download title="{{ $name }} — скачать" class="{{ $class }}" @if($style) style="{{ $style }}" @endif>{!! $label ?? '📎 '.e($name) !!}</a>
@endif
