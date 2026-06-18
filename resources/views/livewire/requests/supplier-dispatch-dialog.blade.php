<div>
    @if($open)
        <div style="position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.55);display:flex;align-items:center;justify-content:center;padding:24px"
             wire:mousedown.self="close">
            <div class="ds-card p-5 w-full max-w-[700px] max-h-[88vh] overflow-y-auto" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-1">📦 Запросить расценку у поставщиков</h3>
                <div class="text-[12px] text-fg-3 mb-3">
                    Поставщики подобраны по матрице ассортимента под {{ $preview['total_items'] }} активн. позиц.
                    Отметьте, кому отправить — каждому уйдёт письмо с его позициями (из вашего ящика). Ответы попадут в раздел «Поставщики».
                </div>

                @if(count($preview['groups']) === 0)
                    <div class="text-[13px] text-amber-700 py-4">
                        Ни по одной позиции нет поставщика в реестре. Добавьте поставщиков в разделе «Поставщики» → «Реестр» (с описанием ассортимента) и попробуйте снова.
                    </div>
                @else
                    <div class="border border-border rounded-md mb-3 divide-y divide-border-subtle">
                        @foreach($preview['groups'] as $g)
                            <label class="flex items-start gap-2 px-3 py-2 cursor-pointer hover:bg-hover">
                                <input type="checkbox" wire:model="selected.{{ $g['id'] }}" class="mt-1">
                                <span class="flex-1">
                                    <span class="text-[13px] text-fg-1 font-medium">{{ $g['name'] }}</span>
                                    <span class="chip chip-sky text-[10px] ml-1">{{ $g['item_count'] }} поз.</span>
                                    <span class="block text-[11px] text-fg-4 mt-0.5">{{ \Illuminate\Support\Str::limit($g['items'], 90) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @if($preview['no_supplier'] > 0)
                        <div class="text-[11.5px] text-amber-700 mb-2">Позиций без поставщика в реестре: {{ $preview['no_supplier'] }}.</div>
                    @endif
                    @error('selected') <div class="text-red-700 text-[12px] mb-2">{{ $message }}</div> @enderror

                    <div class="mb-3">
                        <label class="block text-[11.5px] text-fg-3 mb-1">Примечание к запросу <span class="text-fg-4">(необязательно)</span></label>
                        <textarea wire:model="note" rows="2" placeholder="Напр.: срочно; нужен аналог; уточните срок доставки"
                                  class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                    </div>
                @endif

                <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                    <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send"
                            class="btn btn-primary" @disabled(count($preview['groups']) === 0)>
                        <span wire:loading.remove wire:target="send">Отправить запросы</span>
                        <span wire:loading wire:target="send">Отправляю…</span>
                    </button>
                    <button type="button" wire:click="close" class="btn">Отмена</button>
                </div>
            </div>
        </div>
    @endif
</div>
