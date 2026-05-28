<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:mousedown.self="close">
            <div class="ds-card p-5 w-full max-w-[480px]" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-3">
                    ⏰ Клиент отложил решение
                </h3>

                <form wire:submit="save" class="space-y-3">
                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                            Вернуться к заявке
                        </label>
                        <input type="date" wire:model="until"
                               min="{{ $minAllowed }}" max="{{ $maxAllowed }}"
                               class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                        <div class="text-[11.5px] text-fg-3 mt-1">
                            Дата, к которой клиент обещал ответить. В этот день заявка попадёт в «Просрочено» и подсветится в пуле.
                        </div>
                        @error('until') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                            Комментарий <span class="text-fg-4 font-normal normal-case tracking-normal">(опционально)</span>
                        </label>
                        <textarea wire:model="comment" rows="2" maxlength="500"
                                  placeholder="Например: клиент уходит в командировку до 20.05, обещал отписаться по возвращении"
                                  class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] resize-none"></textarea>
                        @error('comment') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                        <button type="submit" class="btn btn-primary"
                                wire:loading.attr="disabled" wire:target="save">
                            Отложить
                        </button>
                        <button type="button" wire:click="close" class="btn">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
