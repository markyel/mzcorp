<div>
    @if(! $expanded)
        <button type="button"
                wire:click="toggle"
                class="text-sky-700 hover:underline text-[12.5px] inline-flex items-center gap-1"
                title="Добавить новую позицию вручную">
            + добавить позицию
        </button>
    @else
        <form wire:submit="save"
              class="ds-card p-3 space-y-2.5 border border-border bg-surface"
              wire:keydown.escape="cancel">
            <div class="flex items-center gap-2">
                <span class="text-[11px] uppercase tracking-wider text-fg-3 font-semibold">
                    Новая позиция
                </span>
                <span class="flex-1"></span>
                <span class="text-[11px] text-fg-3">источник: ручной ввод</span>
            </div>

            <div>
                <label class="block text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                    Название <span class="text-red-700">*</span>
                </label>
                <input type="text"
                       wire:model="name"
                       autofocus
                       placeholder="Например: Отводка левая"
                       class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                @error('name') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Бренд</label>
                    <input type="text"
                           wire:model="brand"
                           placeholder="например, Wittur"
                           class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                    @error('brand') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Артикул</label>
                    <input type="text"
                           wire:model="article"
                           class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono" />
                    @error('article') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="grid grid-cols-[1fr_1fr_2fr] gap-3">
                <div>
                    <label class="block text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Кол-во <span class="text-red-700">*</span></label>
                    <input type="number"
                           step="0.001"
                           min="0.001"
                           wire:model="qty"
                           class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono" />
                    @error('qty') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Ед. изм.</label>
                    <input type="text"
                           wire:model="unit"
                           maxlength="20"
                           class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                    @error('unit') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Комментарий поставщику</label>
                    <input type="text"
                           wire:model="note"
                           maxlength="1000"
                           class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                    @error('note') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="flex items-center gap-2 pt-1">
                <button type="submit"
                        class="btn btn-primary"
                        wire:loading.attr="disabled"
                        wire:target="save">
                    Добавить позицию
                </button>
                <button type="button"
                        wire:click="cancel"
                        class="btn">
                    Отмена
                </button>
                <span class="flex-1"></span>
                <span class="text-[11px] text-fg-3" wire:loading wire:target="save">сохранение…</span>
            </div>
        </form>
    @endif
</div>
