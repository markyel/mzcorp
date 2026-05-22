<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:click.self="close">
            <div class="ds-card p-5 w-full max-w-[520px]" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-3">
                    📋 Выставить счёт
                </h3>
                <div class="text-[12px] text-fg-3 mb-3">
                    Счёт выставляется в 1С (вне MyLift). Здесь только трекаем номер,
                    срок и оплату. По истечении срока счёт автоматически становится
                    «просроченным», заявка возвращается в «ожидает счёт» — можно
                    перевыставить.
                </div>

                <form wire:submit="save" class="space-y-3">
                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                            Номер счёта *
                        </label>
                        <input type="text" wire:model="invoiceNumber"
                               maxlength="64" placeholder="например: 2026-АА-001"
                               class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] mono outline-none focus:border-[var(--sky-500)]" />
                        @error('invoiceNumber') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                                Дата выставления *
                            </label>
                            <input type="date" wire:model="issuedAt"
                                   max="{{ now()->addDay()->format('Y-m-d') }}"
                                   class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                            @error('issuedAt') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                                Срок (рабочих дней) *
                            </label>
                            <input type="number" wire:model="validityDays"
                                   min="1" max="60"
                                   class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] mono outline-none focus:border-[var(--sky-500)]" />
                            <div class="text-[11px] text-fg-3 mt-1">
                                По умолчанию {{ (int) config('services.invoices.default_validity_business_days', 5) }} раб. дн. (с учётом календаря РФ).
                            </div>
                            @error('validityDays') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                            Комментарий (опц.)
                        </label>
                        <textarea wire:model="comment" rows="2" maxlength="1000"
                                  placeholder="Например: выставлен со скидкой 5% по согласованию с РОПом"
                                  class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] resize-none"></textarea>
                        @error('comment') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    </div>

                    <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                        <button type="submit" class="btn btn-primary"
                                wire:loading.attr="disabled" wire:target="save">
                            Выставить счёт
                        </button>
                        <button type="button" wire:click="close" class="btn">Отмена</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
