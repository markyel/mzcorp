@php $managers = $this->managers; @endphp

{{-- Корневой div получает flex-1, чтобы trigger-кнопка ровно делила место
     с соседом «⏸ Пауза» в parent flex-row. --}}
<div class="flex-1">
    <button type="button"
            wire:click="show"
            class="btn btn-sm w-full">⊘ Переподчинить</button>

    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:mousedown.self="close">
            <div class="ds-card p-5 w-full max-w-[480px]" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-1">
                    Переподчинение заявки <span class="mono text-fg-2">{{ $request->internal_code }}</span>
                </h3>
                <div class="text-[12px] text-fg-3 mb-4">
                    Текущий менеджер:
                    <span class="text-fg-1 font-medium">{{ $request->assignedUser?->name ?? '— не назначен —' }}</span>
                </div>

                <form wire:submit="save" class="space-y-3">
                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Новый менеджер</label>
                        <select wire:model="newAssigneeId"
                                class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]">
                            <option value="">— выберите —</option>
                            @foreach($managers as $m)
                                <option value="{{ $m->id }}">{{ $m->name }} · {{ $m->email }}</option>
                            @endforeach
                        </select>
                        @error('newAssigneeId') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                        @if($managers->isEmpty())
                            <div class="text-amber-700 text-[12px] mt-1">Нет активных менеджеров. Создайте или восстановите кого-нибудь в разделе «Менеджеры».</div>
                        @endif
                    </div>

                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Причина (опционально)</label>
                        <textarea wire:model="reason" rows="2" maxlength="200"
                                  placeholder="Например: уход в отпуск; нагрузка; sticky-привязка"
                                  class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] resize-none"></textarea>
                    </div>

                    <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                        <button type="submit" class="btn btn-primary" @if($managers->isEmpty()) disabled @endif>Переподчинить</button>
                        <button type="button" wire:click="close" class="btn">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
