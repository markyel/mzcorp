<div>
    {{-- Стили рендера markdown — встроенно, без новых Tailwind-классов
         (чтобы не требовался npm run build на проде). --}}
    <style>
        .changelog-prose { color: var(--fg-1); font-size: 13.5px; line-height: 1.6; }
        .changelog-prose h1, .changelog-prose h2, .changelog-prose h3 { font-weight: 600; color: var(--fg-1); margin: 0.8em 0 0.4em; line-height: 1.3; }
        .changelog-prose h1 { font-size: 17px; }
        .changelog-prose h2 { font-size: 15.5px; }
        .changelog-prose h3 { font-size: 14px; }
        .changelog-prose p { margin: 0.5em 0; }
        .changelog-prose ul, .changelog-prose ol { margin: 0.5em 0; padding-left: 1.4em; }
        .changelog-prose ul { list-style: disc; }
        .changelog-prose ol { list-style: decimal; }
        .changelog-prose li { margin: 0.2em 0; }
        .changelog-prose a { color: var(--sky-700, #0369a1); text-decoration: underline; }
        .changelog-prose code { background: var(--bg-surface-2, #f1f5f9); padding: 0.1em 0.35em; border-radius: 4px; font-size: 0.92em; }
        .changelog-prose strong { font-weight: 600; }
        .changelog-prose blockquote { border-left: 3px solid var(--border-strong, #cbd5e1); padding-left: 0.8em; color: var(--fg-2); margin: 0.6em 0; }
        .changelog-prose table { border-collapse: collapse; margin: 0.6em 0; }
        .changelog-prose th, .changelog-prose td { border: 1px solid var(--border, #e5e7eb); padding: 0.3em 0.6em; text-align: left; }
    </style>

    @if($this->canManage)
        <div class="flex justify-end mb-3">
            <a href="{{ route('updates.manage') }}"
               class="inline-flex items-center gap-1.5 h-[30px] px-3 border border-[var(--border-strong)] rounded-md bg-[var(--bg-surface)] text-fg-1 text-[12.5px] font-medium hover:bg-[var(--bg-hover)]">
                ✎ Управление
            </a>
        </div>
    @endif

    @if($this->entries->isEmpty())
        <div class="ds-card">
            <div class="ds-card-body">
                <div class="text-center text-fg-3 py-10 text-[13px]">Обновлений пока нет.</div>
            </div>
        </div>
    @else
        <div class="space-y-3">
            @foreach($this->entries as $entry)
                <div class="ds-card" wire:key="upd-{{ $entry->id }}">
                    <div class="ds-card-body">
                        <div class="text-[11.5px] text-fg-3 mb-1">
                            {{ optional($entry->published_at)->translatedFormat('d MMMM Y') }}
                        </div>
                        <h2 class="text-[16px] font-semibold text-fg-1 leading-snug mb-2">{{ $entry->title }}</h2>
                        <div class="changelog-prose">
                            {!! \Illuminate\Support\Str::markdown($entry->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $this->entries->links() }}
        </div>
    @endif
</div>
