@php
    /**
     * Внутренний layout раздела «Документация».
     * Параметры:
     *   $sections  — array<DocSection> доступных пользователю
     *   $current   — ?DocPage (null для overview)
     *   $slot      — основной контент (rendered HTML или blade)
     */
    $currentKey = isset($current) ? $current->section . '/' . $current->slug : null;
@endphp

<div class="max-w-[1280px] mx-auto px-6 py-6 grid gap-6"
     style="grid-template-columns: 260px 1fr;">
    {{-- Sidebar --}}
    <aside class="text-[13px] sticky top-[calc(var(--topbar-h)+24px)] self-start max-h-[calc(100vh-var(--topbar-h)-48px)] overflow-auto pr-2">
        <a href="{{ route('docs.index') }}"
           class="block mb-3 text-[12px] uppercase tracking-wider text-fg-3 font-semibold hover:text-fg-1">
            Документация
        </a>

        @foreach($sections as $section)
            <div class="mb-4">
                <div class="px-2 mb-1 text-[11.5px] uppercase tracking-wider text-fg-3 font-semibold">
                    {{ $section->title }}
                </div>
                <ul class="space-y-px">
                    @foreach($section->pages as $page)
                        @php $isActive = $currentKey === ($page->section . '/' . $page->slug); @endphp
                        <li>
                            <a href="{{ route('docs.show', ['section' => $page->section, 'slug' => $page->slug]) }}"
                               class="block px-2 py-1.5 rounded-md leading-snug
                                      {{ $isActive
                                           ? 'bg-[var(--bg-selected,var(--sky-50))] text-fg-1 font-semibold border-l-2 border-[var(--sky-500)]'
                                           : 'text-fg-2 hover:text-fg-1 hover:bg-[var(--bg-hover)]' }}">
                                {{ $page->title }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </aside>

    {{-- Content --}}
    <main class="min-w-0">
        {{ $slot }}
    </main>
</div>
