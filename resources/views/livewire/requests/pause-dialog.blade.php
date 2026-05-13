<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:click.self="close">
            <div class="ds-card p-5 w-full max-w-[480px]" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-3">
                    ⏸ Поставить заявку на паузу
                </h3>

                <form wire:submit="save" class="space-y-3">
                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                            Возобновить
                        </label>
                        <input type="date" wire:model="until"
                               min="{{ $minAllowed }}" max="{{ $maxAllowed }}"
                               class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                        <div class="text-[11.5px] text-fg-3 mt-1">
                            Максимум {{ $maxDays }} дн. (до {{ \Illuminate\Support\Carbon::parse($maxAllowed)->format('d.m.Y') }}).
                            После этой даты заявка автоматически вернётся в работу.
                        </div>
                        @error('until') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                            Причина
                        </label>
                        <textarea wire:model="reason" rows="3" maxlength="500"
                                  placeholder="Например: каникулы у поставщиков до 15.01; жду уточнение по габаритам от инженера"
                                  class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] resize-none"></textarea>
                        @error('reason') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                        <button type="submit" class="btn btn-primary"
                                wire:loading.attr="disabled" wire:target="save">
                            Поставить на паузу
                        </button>
                        <button type="button" wire:click="close" class="btn">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
