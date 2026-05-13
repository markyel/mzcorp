<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:click.self="close">
            <div class="ds-card p-5 w-full max-w-[520px]" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-1">
                    ⊘ Закрыть заявку как потеря
                </h3>
                <div class="text-[12px] text-fg-3 mb-3">
                    Это терминальный статус. Открыть заявку обратно нельзя — только создать
                    новую вручную из текущей. Выберите причину для аналитики.
                </div>

                <form wire:submit="save" class="space-y-3">
                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                            Причина закрытия
                        </label>
                        <select wire:model.live="reason"
                                class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]">
                            <option value="">— выберите —</option>
                            @foreach($reasons as $r)
                                <option value="{{ $r['value'] }}">{{ $r['label'] }}</option>
                            @endforeach
                        </select>
                        @error('reason') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>

                    @php
                        $selected = collect($reasons)->firstWhere('value', $reason);
                        $needsComment = $selected['needsComment'] ?? false;
                    @endphp

                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                            Комментарий
                            @if($needsComment)
                                <span class="text-red-700">*</span>
                            @else
                                <span class="text-fg-3 normal-case">(опционально)</span>
                            @endif
                        </label>
                        <textarea wire:model="comment" rows="3" maxlength="2000"
                                  placeholder="Подробности отказа — для дашборда РОПа и истории"
                                  class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] resize-none"></textarea>
                        @error('comment') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                        <button type="submit" class="btn btn-danger"
                                wire:loading.attr="disabled" wire:target="save"
                                @if(! $reason) disabled @endif>
                            Закрыть как потеря
                        </button>
                        <button type="button" wire:click="close" class="btn">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
