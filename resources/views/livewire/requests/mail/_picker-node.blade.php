{{-- Рекурсивный узел пикера. Папка — заголовок, шаблон — кнопка вставки.
     Ожидает $node, $depth. --}}
<div wire:key="pick-{{ $node->id }}">
    @if($node->is_folder)
        <div class="flex items-center gap-2 py-1.5 text-fg-2 font-semibold"
             style="padding-left: {{ 4 + $depth * 18 }}px;">
            <span>📁</span><span class="truncate">{{ $node->name }}</span>
        </div>
    @else
        <button type="button" wire:click="insert({{ $node->id }})"
                class="w-full text-left flex items-center gap-2 py-1.5 rounded hover:bg-surface-2"
                style="padding-left: {{ 4 + $depth * 18 }}px;"
                title="{{ $node->subject ? 'Тема: '.$node->subject : 'Вставить шаблон' }}">
            <span>📄</span>
            <span class="text-fg-1 truncate">{{ $node->name }}</span>
            @if($node->subject)
                <span class="text-fg-3 text-[11px] truncate hidden sm:inline">· {{ $node->subject }}</span>
            @endif
        </button>
    @endif

    @foreach($node->childrenRecursive as $child)
        @include('livewire.requests.mail._picker-node', ['node' => $child, 'depth' => $depth + 1])
    @endforeach
</div>
