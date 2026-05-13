<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:click.self="close">
            <div class="ds-card p-5 w-full max-w-[560px]" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-3">
                    Редактирование позиции
                </h3>

                <form wire:submit="save" class="space-y-3">
                    <div>
                        <label class="block text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Название</label>
                        <input type="text" wire:model="parsedName"
                               class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                        @error('parsedName') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Артикул</label>
                            <input type="text" wire:model="parsedArticle"
                                   class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono" />
                            @error('parsedArticle') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="block text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Бренд</label>
                            <input type="text" wire:model="parsedBrand"
                                   class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                            @error('parsedBrand') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Кол-во</label>
                            <input type="number" step="0.001" min="0" wire:model="parsedQty"
                                   class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono" />
                            @error('parsedQty') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="block text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Ед. изм.</label>
                            <input type="text" maxlength="20" wire:model="parsedUnit"
                                   class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                            @error('parsedUnit') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Комментарий поставщику</label>
                        <textarea wire:model="supplierNote" rows="2" maxlength="1000"
                                  class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] resize-none"></textarea>
                        @error('supplierNote') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                        <button type="submit" class="btn btn-primary"
                                wire:loading.attr="disabled" wire:target="save">Сохранить</button>
                        <button type="button" wire:click="close" class="btn">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
