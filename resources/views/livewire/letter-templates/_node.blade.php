{{-- Рекурсивный узел дерева шаблонов. Ожидает $node, $depth, $canEdit. --}}
<div wire:key="lt-{{ $node->id }}">
    <div class="flex items-center gap-2 py-1.5 border-b border-border-subtle hover:bg-surface-2 rounded"
         style="padding-left: {{ 8 + $depth * 20 }}px;">
        <span class="shrink-0">
            @if($node->is_folder) 📁 @else 📄 @endif
        </span>
        <span class="text-fg-1 {{ $node->is_folder ? 'font-semibold' : '' }} truncate" title="{{ $node->name }}">
            {{ $node->name }}
        </span>
        @unless($node->is_folder)
            @if($node->subject)
                <span class="text-fg-3 text-[11px] truncate hidden md:inline" title="Тема: {{ $node->subject }}">· {{ $node->subject }}</span>
            @endif
        @endunless

        <span class="flex-1"></span>

        @if($canEdit)
            <div class="flex items-center gap-1 shrink-0">
                <button type="button" wire:click="moveUp({{ $node->id }})" class="text-fg-3 hover:text-fg-1 px-1" title="Выше">▲</button>
                <button type="button" wire:click="moveDown({{ $node->id }})" class="text-fg-3 hover:text-fg-1 px-1" title="Ниже">▼</button>
                @if($node->is_folder)
                    <button type="button" wire:click="newTemplate({{ $node->id }})" class="text-[var(--sky-700)] hover:underline px-1.5 text-[12px]" title="Добавить шаблон в папку">＋шаблон</button>
                    <button type="button" wire:click="newFolder({{ $node->id }})" class="text-[var(--sky-700)] hover:underline px-1.5 text-[12px]" title="Добавить подпапку">＋папка</button>
                @endif
                <button type="button" wire:click="edit({{ $node->id }})" class="text-fg-2 hover:text-fg-1 px-1.5 text-[12px]">Изм.</button>
                <button type="button" wire:click="delete({{ $node->id }})"
                        wire:confirm="{{ $node->is_folder ? 'Удалить папку «'.$node->name.'» вместе со всем содержимым?' : 'Удалить шаблон «'.$node->name.'»?' }}"
                        class="text-red-700 hover:text-red-900 px-1.5 text-[12px]">Удал.</button>
            </div>
        @endif
    </div>

    @foreach($node->childrenRecursive as $child)
        @include('livewire.letter-templates._node', ['node' => $child, 'depth' => $depth + 1, 'canEdit' => $canEdit])
    @endforeach
</div>
