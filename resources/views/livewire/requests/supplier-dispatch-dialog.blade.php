<div>
    @if($open)
        <div style="position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.55);display:flex;align-items:center;justify-content:center;padding:24px"
             wire:mousedown.self="close">
            <div class="ds-card p-5 w-full max-w-[680px] max-h-[88vh] overflow-y-auto" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-1">📦 Запросить расценку у поставщиков</h3>
                <div class="text-[12px] text-fg-3 mb-3">
                    На каждого поставщика уйдёт одно письмо со списком его позиций (из вашего ящика).
                    Поставщики подбираются по матрице ассортимента. Ответы попадут в раздел «Поставщики».
                </div>

                @php $anyMatch = collect($rows)->where('supplier_count', '>', 0)->isNotEmpty(); @endphp

                <div class="overflow-x-auto border border-border rounded-md mb-3">
                    <table class="w-full text-[12.5px]">
                        <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                            <tr>
                                <th class="px-2 py-2 text-center w-[34px]"></th>
                                <th class="px-2 py-2 text-left">Позиция</th>
                                <th class="px-2 py-2 text-left">Поставщики</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $r)
                                <tr class="border-b border-border-subtle">
                                    <td class="px-2 py-2 text-center">
                                        @if($r['supplier_count'] > 0)
                                            <input type="checkbox" wire:model="selected.{{ $r['id'] }}">
                                        @else
                                            <span title="Нет поставщика по этой позиции">—</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-2">
                                        <div class="text-fg-1">{{ \Illuminate\Support\Str::limit($r['name'], 60) }}</div>
                                        <div class="text-[11px] text-fg-4">{{ trim(implode(' · ', array_filter([$r['article'], $r['brand'], $r['qty']]))) }}</div>
                                    </td>
                                    <td class="px-2 py-2">
                                        @if($r['supplier_count'] > 0)
                                            <span class="chip chip-sky text-[10.5px]">{{ $r['supplier_count'] }}</span>
                                            <span class="text-[11px] text-fg-3">{{ $r['suppliers'] }}</span>
                                        @else
                                            <span class="text-[11px] text-amber-700">нет поставщика — добавьте в реестр</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-3 py-6 text-center text-fg-3">Нет активных позиций.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @error('selected') <div class="text-red-700 text-[12px] mb-2">{{ $message }}</div> @enderror

                <div class="mb-3">
                    <label class="block text-[11.5px] text-fg-3 mb-1">Примечание к запросу <span class="text-fg-4">(необязательно)</span></label>
                    <textarea wire:model="note" rows="2" placeholder="Напр.: срочно; нужен аналог; уточните срок доставки"
                              class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                </div>

                <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                    <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send"
                            class="btn btn-primary" @disabled(! $anyMatch)>
                        <span wire:loading.remove wire:target="send">Отправить запросы</span>
                        <span wire:loading wire:target="send">Отправляю…</span>
                    </button>
                    <button type="button" wire:click="close" class="btn">Отмена</button>
                    @unless($anyMatch)
                        <span class="text-[11.5px] text-amber-700">Ни по одной позиции нет поставщика в реестре.</span>
                    @endunless
                </div>
            </div>
        </div>
    @endif
</div>
