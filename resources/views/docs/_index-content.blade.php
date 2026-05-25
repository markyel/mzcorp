<div class="doc-content">
    <h1 class="text-[24px] font-semibold text-fg-1 mb-1">Документация MyLift CRM</h1>
    <p class="text-[13.5px] text-fg-2 mb-6">
        Рукописные гайды по ролям. Слева — оглавление; кнопка
        <span class="font-mono text-[12px] px-1 py-0.5 border border-border rounded">?</span>
        в шапке системы возвращает вас сюда из любого места.
    </p>

    @if(empty($sections))
        <div class="ds-card p-6 text-center text-fg-3">
            Документация для вашей роли пока не загружена.
        </div>
    @else
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
            @foreach($sections as $section)
                <div class="ds-card p-4">
                    <div class="text-[11.5px] uppercase tracking-wider text-fg-3 font-semibold mb-1">{{ $section->title }}</div>
                    <ul class="space-y-1">
                        @foreach($section->pages as $page)
                            <li>
                                <a href="{{ route('docs.show', ['section' => $page->section, 'slug' => $page->slug]) }}"
                                   class="text-[13px] text-fg-1 hover:underline font-medium">
                                    {{ $page->title }}
                                </a>
                                @if($page->excerpt)
                                    <div class="text-[12px] text-fg-3 leading-snug">{{ $page->excerpt }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
</div>
