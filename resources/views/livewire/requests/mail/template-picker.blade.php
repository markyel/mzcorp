{{-- Модалка выбора шаблона письма. Открывается событием open-template-picker
     из footer composer'а. Клик по шаблону → insert-template → ComposeForm. --}}
<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 10000; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:mousedown.self="close">
            <div class="ds-card p-5 w-full max-w-[560px]" wire:click.stop
                 style="max-height: 80vh; display: flex; flex-direction: column;">
                <div class="flex items-center gap-2 mb-3">
                    <h3 class="text-[15px] font-semibold text-fg-1">Вставить шаблон</h3>
                    <span class="flex-1"></span>
                    <button type="button" wire:click="close" class="text-fg-3 hover:text-fg-1 text-[15px]" title="Закрыть">✕</button>
                </div>

                @php $tree = $this->tree; @endphp
                @if($tree->isEmpty())
                    <div class="text-[13px] text-fg-3 py-6 text-center border border-dashed border-border rounded-md">
                        Библиотека шаблонов пуста. Сохраните текущее письмо как шаблон,
                        либо создайте шаблоны в разделе «Шаблоны писем».
                    </div>
                @else
                    <div class="text-[13px] overflow-auto" style="flex: 1 1 auto;">
                        @foreach($tree as $node)
                            @include('livewire.requests.mail._picker-node', ['node' => $node, 'depth' => 0])
                        @endforeach
                    </div>
                @endif

                <div class="flex items-center pt-3 mt-3 border-t border-border-subtle">
                    <a href="{{ route('letter-templates.index') }}" target="_blank" rel="noopener"
                       class="text-[12px] text-[var(--sky-700)] hover:underline"
                       title="Открыть управление библиотекой шаблонов в новой вкладке">Управление шаблонами →</a>
                </div>
            </div>
        </div>
    @endif
</div>
