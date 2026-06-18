<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:mousedown.self="close">
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
                        $isSpam = $reason === 'spam';
                        $isSupplier = $reason === 'supplier_reply';
                    @endphp

                    {{-- Переписка с поставщиком: занести весь ящик в стоп-лист? --}}
                    @if($isSupplier)
                        <div class="rounded border border-sky-200 bg-sky-50 p-3 space-y-2">
                            <label class="flex items-start gap-2 cursor-pointer text-[13px]">
                                <input type="checkbox" wire:model="addToBlocklist" class="mt-0.5">
                                <span>
                                    Занести отправителя в стоп-лист как <b>поставщика</b>
                                    @if($senderInfo['email'])<span class="font-mono text-[12px] block text-fg-3">{{ $senderInfo['email'] }}</span>@endif
                                </span>
                            </label>
                            <div class="text-[11px] text-sky-800">
                                <b>С галкой:</b> ВСЕ письма с этого ящика будут считаться перепиской поставщика — не создают заявок, но читаются в разделе «Поставщики».<br>
                                <b>Без галки:</b> помечается только этот тред; с адреса по-прежнему могут приходить клиентские заявки.
                            </div>
                        </div>
                    @endif

                    {{-- Spam scope: только при reason=Spam --}}
                    @if($isSpam)
                        <div class="rounded border border-amber-200 bg-amber-50 p-3">
                            <div class="text-[12px] uppercase tracking-wider text-amber-800 font-semibold mb-2">
                                Что добавить в стоп-лист
                            </div>
                            @if($senderInfo['email'])
                                <div class="space-y-2 text-[13px]">
                                    <label class="flex items-start gap-2 cursor-pointer">
                                        <input type="radio" wire:model="blocklistScope" value="email" class="mt-1">
                                        <span>
                                            Только адрес: <span class="font-mono text-[12px]">{{ $senderInfo['email'] }}</span>
                                        </span>
                                    </label>
                                    @if($senderInfo['domain'])
                                        <label class="flex items-start gap-2 cursor-pointer">
                                            <input type="radio" wire:model="blocklistScope" value="domain" class="mt-1">
                                            <span>
                                                Весь домен: <span class="font-mono text-[12px]">{{ $senderInfo['domain'] }}</span>
                                                <span class="text-fg-3 block text-[11px]">включая поддомены</span>
                                            </span>
                                        </label>
                                    @endif
                                </div>
                                <div class="text-[11px] text-amber-700 mt-2">
                                    Будущие письма от выбранного источника не будут создавать заявок.
                                    Уже открытые заявки остаются — закройте их вручную при необходимости.
                                </div>
                            @else
                                <div class="text-[12px] text-amber-800">
                                    У заявки нет исходного письма — нельзя автоматически определить отправителя.
                                    Выберите другую причину или добавьте адрес в стоп-лист вручную.
                                </div>
                            @endif
                        </div>
                    @endif

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
