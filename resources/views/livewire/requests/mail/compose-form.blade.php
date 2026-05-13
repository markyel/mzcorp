{{-- Compose / Reply / Reply-all форма (Phase 1.9). --}}
{{-- Inline в табе «Переписка». Стили опираются на classes из layouts/app.blade.php
     (.btn, .ds-card, токены `border`, `surface`, `fg-*`). --}}
<div class="compose-form">
    @if($open)
        <div class="ds-card p-4 mt-3" style="background: var(--bg-surface-2);">
            <div class="flex items-center justify-between mb-3">
                <div class="text-[13px] font-semibold text-fg-1">
                    @switch($mode)
                        @case('reply') Ответ @break
                        @case('reply_all') Ответ всем @break
                        @default Новое сообщение
                    @endswitch
                </div>
                <button type="button" wire:click="close" class="text-fg-3 hover:text-fg-1 text-[12px]">✕ Свернуть</button>
            </div>

            <div class="space-y-2.5">
                {{-- От: --}}
                <div class="flex items-center gap-2 text-[12px]">
                    <span class="text-fg-3 uppercase tracking-wider font-semibold w-[60px]">От:</span>
                    <span class="text-fg-1 mono">{{ $this->mailboxLabel ?? '—' }}</span>
                </div>

                {{-- Кому --}}
                <div class="flex items-start gap-2">
                    <label class="text-[12px] text-fg-3 uppercase tracking-wider font-semibold w-[60px] pt-1.5">Кому</label>
                    <input type="text" wire:model.live.debounce.1500ms="toRaw"
                           class="flex-1 h-[32px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]"
                           placeholder="email@клиента; ещё@клиент.ru" />
                </div>
                @error('toRaw') <div class="text-red-700 text-[12px] ml-[68px]">{{ $message }}</div> @enderror

                {{-- Cc --}}
                <div class="flex items-start gap-2">
                    <label class="text-[12px] text-fg-3 uppercase tracking-wider font-semibold w-[60px] pt-1.5">Cc</label>
                    <input type="text" wire:model.live.debounce.1500ms="ccRaw"
                           class="flex-1 h-[32px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]"
                           placeholder="(опционально)" />
                </div>

                {{-- Тема --}}
                <div class="flex items-start gap-2">
                    <label class="text-[12px] text-fg-3 uppercase tracking-wider font-semibold w-[60px] pt-1.5">Тема</label>
                    <input type="text" wire:model.live.debounce.1500ms="subject"
                           class="flex-1 h-[32px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                </div>
                @error('subject') <div class="text-red-700 text-[12px] ml-[68px]">{{ $message }}</div> @enderror

                {{-- Body --}}
                <div>
                    <textarea wire:model.live.debounce.1500ms="bodyHtml"
                              rows="10"
                              class="w-full px-3 py-2 border border-border-strong rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] resize-vertical"
                              style="font-family: var(--font-sans); line-height: 1.55;"></textarea>
                    @error('bodyHtml') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>

                {{-- Attachments --}}
                <div>
                    <div class="text-[12px] text-fg-3 uppercase tracking-wider font-semibold mb-1.5">Вложения</div>
                    @php $atts = $this->attachments; @endphp
                    @if($atts->isNotEmpty())
                        <div class="flex flex-wrap gap-2 mb-2">
                            @foreach($atts as $att)
                                <span class="att inline-flex items-center gap-2 px-2 py-1 border border-border rounded-md bg-surface text-[12px]">
                                    <span class="text-fg-1">{{ $att->filename }}</span>
                                    <span class="text-fg-3">· {{ number_format($att->size_bytes / 1024, 0) }} KB</span>
                                    <button type="button"
                                            wire:click="removeAttachment({{ $att->id }})"
                                            class="text-red-700 hover:text-red-900 ml-1">×</button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    <div class="flex items-center gap-2">
                        <input type="file" wire:model="newFiles" multiple class="text-[12px]" />
                        <button type="button" wire:click="uploadAttachments" class="btn btn-sm" @if(empty($newFiles)) disabled @endif>📎 Прикрепить</button>
                        @error('newFiles.*') <span class="text-red-700 text-[12px]">{{ $message }}</span> @enderror
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="flex items-center gap-2 pt-3 mt-3 border-t border-border-subtle">
                <button type="button" wire:click="send" class="btn btn-primary"
                        wire:loading.attr="disabled" wire:target="send">
                    <span wire:loading.remove wire:target="send">Отправить</span>
                    <span wire:loading wire:target="send">Отправляем…</span>
                </button>
                <button type="button" wire:click="discard" class="btn"
                        wire:confirm="Удалить черновик?">Удалить черновик</button>
                <span class="text-fg-3 text-[12px] ml-auto">
                    Автосохранение
                    <span wire:loading wire:target="updatedSubject,updatedToRaw,updatedCcRaw,updatedBodyHtml" class="text-amber-700">…</span>
                </span>
            </div>
        </div>
    @endif
</div>
