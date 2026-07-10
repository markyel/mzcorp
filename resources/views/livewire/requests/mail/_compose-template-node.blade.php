{{-- Рекурсивный узел inline-меню вставки шаблона в composer'е.
     Папка — заголовок, шаблон — кнопка вставки (insertTemplateById → ComposeForm).
     Ожидает $node, $depth. --}}
<div wire:key="ct-{{ $node->id }}">
    @if($node->is_folder)
        <div class="flex items-center gap-2 py-1 text-fg-2 font-semibold text-[12px]"
             style="padding-left: {{ 4 + $depth * 16 }}px;">
            <span>📁</span><span class="truncate">{{ $node->name }}</span>
        </div>
    @else
        <button type="button"
                wire:click="insertTemplateById({{ $node->id }})"
                x-on:click="open = false"
                class="w-full text-left flex items-center gap-2 py-1.5 rounded hover:bg-surface-2 text-[13px]"
                style="padding-left: {{ 4 + $depth * 16 }}px;"
                title="{{ $node->subject ? 'Тема: '.$node->subject : 'Вставить шаблон' }}">
            <span>📄</span>
            <span class="text-fg-1 truncate">{{ $node->name }}</span>
        </button>
    @endif

    @foreach($node->childrenRecursive as $child)
        @include('livewire.requests.mail._compose-template-node', ['node' => $child, 'depth' => $depth + 1])
    @endforeach
</div>
