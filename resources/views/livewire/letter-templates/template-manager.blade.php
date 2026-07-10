<div class="ds-card p-5">
    @php $canEdit = $this->canEdit(); @endphp

    @if(session('status'))
        <div class="mb-4 p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
            {{ session('status') }}
        </div>
    @endif

    {{-- Тулбар: создать в корне. --}}
    @if($canEdit)
        <div class="flex items-center gap-2 mb-4">
            <button type="button" wire:click="newTemplate" class="btn btn-sm btn-primary">＋ Шаблон в корень</button>
            <button type="button" wire:click="newFolder" class="btn btn-sm">＋ Папка в корень</button>
        </div>
    @else
        <div class="mb-4 text-[12px] text-fg-3">Просмотр библиотеки шаблонов. Редактирование доступно менеджерам, РОП и админам.</div>
    @endif

    {{-- Inline-редактор. --}}
    @if($canEdit && ($creating || $editingId))
        <div class="border border-border-strong rounded-md p-4 mb-5 bg-surface-2">
            <div class="text-[13px] font-semibold text-fg-1 mb-3">
                @if($editingId)
                    Редактирование {{ $isFolder ? 'папки' : 'шаблона' }}
                @else
                    Новая {{ $isFolder ? 'папка' : 'шаблон' }}
                    @php $p = $this->folders->firstWhere('id', $parentId); @endphp
                    @if($p) <span class="text-fg-3 font-normal">в «{{ $p->name }}»</span> @endif
                @endif
            </div>

            <form wire:submit="save" class="space-y-3">
                <div>
                    <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Название</label>
                    <input type="text" wire:model="name"
                           class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]"
                           placeholder="{{ $isFolder ? 'Например: Гарантия' : 'Например: Отказ по гарантии' }}" />
                    @error('name') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>

                @if(! $isFolder)
                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Тема (опционально)</label>
                        <input type="text" wire:model="subject"
                               class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]"
                               placeholder="Подставится в тему письма, если она пуста" />
                        @error('subject') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Текст письма</label>
                        <textarea wire:model="body" rows="8"
                                  class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] resize-y"
                                  style="font-family: var(--font-sans); line-height: 1.55;"
                                  placeholder="Обычный текст. Подпись и цитата добавятся автоматически при отправке."></textarea>
                        @error('body') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>
                @endif

                <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                    <button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
                    <button type="button" wire:click="cancel" class="btn btn-sm">Отмена</button>
                </div>
            </form>
        </div>
    @endif

    {{-- Дерево. --}}
    @php $tree = $this->tree; @endphp
    @if($tree->isEmpty())
        <div class="text-[13px] text-fg-3 py-6 text-center border border-dashed border-border rounded-md">
            Пока нет шаблонов. @if($canEdit) Создайте первый через кнопки выше. @endif
        </div>
    @else
        <div class="text-[13px]">
            @foreach($tree as $node)
                @include('livewire.letter-templates._node', ['node' => $node, 'depth' => 0, 'canEdit' => $canEdit])
            @endforeach
        </div>
    @endif
</div>
