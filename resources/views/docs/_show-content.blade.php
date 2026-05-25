@php
    $sectionLabel = match($page->section) {
        'common' => 'Общее',
        'manager' => 'Менеджер',
        'rop' => 'РОП',
        'secretary' => 'Секретарь',
        'director' => 'Директорат',
        default => $page->section,
    };
@endphp

<div class="text-[12.5px] text-fg-3 mb-2 flex items-center gap-2">
    <a href="{{ route('docs.index') }}" class="hover:underline">Документация</a>
    <span>›</span>
    <span>{{ $sectionLabel }}</span>
    <span>›</span>
    <span class="text-fg-1">{{ $page->title }}</span>
</div>

<article class="doc-content ds-card p-6">
    {!! $html !!}
</article>
