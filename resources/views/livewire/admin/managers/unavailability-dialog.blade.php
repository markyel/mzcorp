<div>
    @if($open && $user)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:click.self="close">
            <div class="ds-card p-5 w-full max-w-[520px]" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-1">
                    Пометить менеджера «недоступен»
                </h3>
                <div class="text-[12.5px] text-fg-3 mb-3">
                    «{{ $user->name }}» не будет получать новые заявки до выбранной даты.
                </div>

                <form wire:submit="save" class="space-y-3">
                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                            Возвращается
                        </label>
                        <input type="date" wire:model="until"
                               min="{{ $minAllowed }}" max="{{ $maxAllowed }}"
                               class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                        <div class="text-[11.5px] text-fg-3 mt-1">
                            После этой даты менеджер автоматически снова в распределении (по факту запроса).
                        </div>
                        @error('until') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                            Причина
                        </label>
                        <textarea wire:model="reason" rows="2" maxlength="500"
                                  placeholder="Например: отпуск до 30.05, командировка в Казань, больничный…"
                                  class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] resize-none"></textarea>
                        @error('reason') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>

                    <label class="flex items-start gap-2 text-[12.5px] cursor-pointer">
                        <input type="checkbox" wire:model="reassign" class="mt-0.5">
                        <span class="text-fg-1">
                            Передать активные заявки другим менеджерам
                            <div class="text-fg-3 text-[11.5px]">
                                AssignmentService раскидает по round-robin / sticky. Заявки на паузе и закрытые — не трогаем.
                            </div>
                        </span>
                    </label>

                    <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                        <button type="submit" class="btn btn-primary"
                                wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">Сохранить</span>
                            <span wire:loading wire:target="save">Передаём заявки…</span>
                        </button>
                        <button type="button" wire:click="close" class="btn">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
