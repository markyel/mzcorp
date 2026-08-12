<div>
    @if($open)
        <div class="fixed inset-0 z-[9998] flex items-start justify-center overflow-y-auto p-4"
             style="background: rgba(0,0,0,0.45)"
             wire:key="send-client-doc-modal"
             x-data x-on:keydown.escape.window="$wire.close()">
            <div class="ds-card w-full max-w-2xl mt-12 shadow-xl" x-on:click.stop>
                <div class="ds-card-header">
                    <h3 class="text-[15px] font-semibold text-fg-1">
                        Отправить клиенту {{ $focus === 'invoice' ? 'счёт' : 'КП' }}
                    </h3>
                    <span class="flex-1"></span>
                    <button type="button" wire:click="close" class="btn btn-sm">✕</button>
                </div>
                <div class="ds-card-body space-y-3">
                    {{-- Тема (с [M-код] / № 1С) --}}
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Тема (ответ на последнее письмо клиента)</label>
                        <input type="text" wire:model="subject"
                               class="w-full px-2.5 py-1.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
                    </div>

                    {{-- Текст письма (шаблон, редактируемый) --}}
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Текст письма</label>
                        <textarea wire:model="bodyText" rows="5"
                                  class="w-full px-2.5 py-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500 resize-y"></textarea>
                        @error('bodyText')<div class="text-[11.5px] mt-1" style="color:var(--red-700)">{{ $message }}</div>@enderror
                    </div>

                    {{-- Слоты вложений: КП / Счёт / произвольные --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="rounded-md border border-border-subtle p-2.5">
                            <label class="block text-[12px] font-medium text-fg-1 mb-1">КП (из 1С)</label>
                            <input type="file" wire:model="kpFiles" multiple
                                   class="block w-full text-[12px] text-fg-2 file:mr-2 file:px-2 file:py-1 file:border file:border-border file:rounded file:bg-surface-2 file:text-[12px]">
                            <div wire:loading wire:target="kpFiles" class="text-[11px] text-fg-4 mt-1">загрузка…</div>
                            <div class="text-[10.5px] text-fg-4 mt-1">Будет обработан как КП → «КП отправлено»</div>
                            @error('kpFiles')<div class="text-[11px]" style="color:var(--red-700)">{{ $message }}</div>@enderror
                            @error('kpFiles.*')<div class="text-[11px]" style="color:var(--red-700)">{{ $message }}</div>@enderror
                        </div>
                        <div class="rounded-md border border-border-subtle p-2.5">
                            <label class="block text-[12px] font-medium text-fg-1 mb-1">Счёт (из 1С)</label>
                            <input type="file" wire:model="invoiceFiles" multiple
                                   class="block w-full text-[12px] text-fg-2 file:mr-2 file:px-2 file:py-1 file:border file:border-border file:rounded file:bg-surface-2 file:text-[12px]">
                            <div wire:loading wire:target="invoiceFiles" class="text-[11px] text-fg-4 mt-1">загрузка…</div>
                            <div class="text-[10.5px] text-fg-4 mt-1">Будет обработан как счёт → «Счёт выставлен»</div>
                            @error('invoiceFiles.*')<div class="text-[11px]" style="color:var(--red-700)">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="rounded-md border border-border-subtle p-2.5">
                        <label class="block text-[12px] font-medium text-fg-1 mb-1">Произвольные файлы (необязательно)</label>
                        <input type="file" wire:model="extraFiles" multiple
                               class="block w-full text-[12px] text-fg-2 file:mr-2 file:px-2 file:py-1 file:border file:border-border file:rounded file:bg-surface-2 file:text-[12px]">
                        <div wire:loading wire:target="extraFiles" class="text-[11px] text-fg-4 mt-1">загрузка…</div>
                    </div>

                    {{-- Копия (CC) --}}
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Копия (CC) — через запятую, необязательно</label>
                        <input type="text" wire:model="ccRaw" placeholder="email@example.com, ..."
                               class="w-full px-2.5 py-1.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
                    </div>
                </div>
                <div class="ds-card-body border-t border-border flex items-center justify-end gap-2">
                    <button type="button" wire:click="close" class="btn btn-sm">Отмена</button>
                    <button type="button" wire:click="send" wire:loading.attr="disabled"
                            wire:target="send,kpFiles,invoiceFiles,extraFiles" class="btn btn-sm btn-primary">
                        <span wire:loading.remove wire:target="send">Отправить клиенту</span>
                        <span wire:loading wire:target="send">Отправка…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
