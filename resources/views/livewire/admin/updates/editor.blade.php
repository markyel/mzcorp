<div>
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
    </style>

    <form wire:submit="save" class="space-y-4">
        <div class="ds-card">
            <div class="ds-card-body space-y-4">
                <div>
                    <label class="block text-[12.5px] font-medium text-fg-2 mb-1">Заголовок</label>
                    <input type="text" wire:model="title" maxlength="200"
                           class="w-full h-[36px] px-3 border border-[var(--border)] rounded-md bg-[var(--bg-app)] text-fg-1 text-[13px] outline-none focus:border-[var(--sky-500)]"
                           placeholder="Например: Переработан алгоритм распределения заявок">
                    @error('title') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label class="block text-[12.5px] font-medium text-fg-2 mb-1">
                        Краткое содержание <span class="text-fg-3 font-normal">(для превью на дашборде; если пусто — возьмём начало текста)</span>
                    </label>
                    <textarea wire:model="excerpt" rows="2" maxlength="300"
                              class="w-full px-3 py-2 border border-[var(--border)] rounded-md bg-[var(--bg-app)] text-fg-1 text-[13px] leading-relaxed outline-none focus:border-[var(--sky-500)]"
                              placeholder="1-2 предложения сути обновления"></textarea>
                    @error('excerpt') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label class="block text-[12.5px] font-medium text-fg-2 mb-1">
                        Текст <span class="text-fg-3 font-normal">(Markdown: ## заголовки, **жирный**, - списки, [ссылка](url))</span>
                    </label>
                    <textarea wire:model.live.debounce.400ms="body" rows="12"
                              class="w-full px-3 py-2 border border-[var(--border)] rounded-md bg-[var(--bg-app)] text-fg-1 text-[13px] font-mono leading-relaxed outline-none focus:border-[var(--sky-500)]"
                              placeholder="Что изменилось и почему это важно для участников…"></textarea>
                    @error('body') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>

                <label class="inline-flex items-center gap-2 text-[13px] text-fg-1 cursor-pointer">
                    <input type="checkbox" wire:model="isPublished" class="rounded border-border">
                    Опубликовано (видно всем участникам)
                </label>
            </div>
        </div>

        @if(trim($body) !== '')
            <div class="ds-card">
                <div class="ds-card-header"><h3>Предпросмотр</h3></div>
                <div class="ds-card-body">
                    <div class="changelog-prose">{!! $this->previewHtml !!}</div>
                </div>
            </div>
        @endif

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="inline-flex items-center gap-1.5 h-[36px] px-4 rounded-md bg-[var(--accent)] text-[var(--fg-on-accent)] border border-[var(--accent)] text-[13px] font-medium hover:opacity-90">
                Сохранить
            </button>
            <a href="{{ route('updates.manage') }}" class="text-fg-2 hover:text-fg-1 text-[13px]">Отмена</a>
        </div>
    </form>
</div>
