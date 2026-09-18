{{-- Узел дерева шаблонов в композере почтового клиента: папка — заголовок,
     шаблон — кнопка вставки (Composer::insertTemplateById).
     Ожидает $node, $depth. --}}
<div wire:key="mct-{{ $node->id }}">
    @if($node->is_folder)
        <div class="tplfolder" style="padding-left: {{ 6 + $depth * 14 }}px">📁 {{ $node->name }}</div>
    @else
        <button type="button" class="tplitem" style="padding-left: {{ 6 + $depth * 14 }}px"
                wire:click="insertTemplateById({{ $node->id }})"
                @click="tplOpen = false"
                title="{{ $node->subject ? 'Тема: '.$node->subject : 'Вставить шаблон' }}">
            📄 {{ $node->name }}
        </button>
    @endif

    @foreach($node->childrenRecursive as $child)
        @include('livewire.mail._composer-template-node', ['node' => $child, 'depth' => $depth + 1])
    @endforeach
</div>
