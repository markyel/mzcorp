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

                {{-- Body (plain text — подпись и цитата автоматически добавятся
                     при отправке, см. OutgoingMailMimeBuilder::composeFinalBody). --}}
                <div>
                    <textarea wire:model.live.debounce.1500ms="bodyText"
                              rows="10"
                              placeholder="Напишите ответ клиенту обычным текстом…"
                              class="w-full px-3 py-2 border border-border-strong rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] resize-vertical"
                              style="font-family: var(--font-sans); line-height: 1.55;"></textarea>
                    @error('bodyText') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>

                {{-- Preview: подпись + цитата исходного письма.
                     Не редактируется. При send приклеивается автоматически.
                     Цитата — plain-текст в <pre>: надёжнее iframe-srcdoc
                     (тот давал нулевую высоту до Alpine fit'а). --}}
                @php
                    $sig = $this->signaturePreview;
                    $quotePlain = $this->quotePreviewPlain;
                @endphp
                @if($sig || $quotePlain)
                    <details class="border border-border rounded-md bg-surface">
                        <summary class="cursor-pointer px-3 py-2 text-[12px] text-fg-3 select-none hover:bg-surface-2">
                            При отправке к письму добавятся:
                            @if($sig) <span class="text-fg-1 font-medium">подпись</span> @endif
                            @if($sig && $quotePlain) <span>+</span> @endif
                            @if($quotePlain) <span class="text-fg-1 font-medium">цитата исходного письма</span> @endif
                            <span> · нажмите чтобы посмотреть</span>
                        </summary>
                        <div class="border-t border-border-subtle">
                            @if($sig)
                                <div class="px-3 py-2 text-[12px] text-fg-2 border-b border-border-subtle bg-surface-2">
                                    <div class="text-[11px] text-fg-3 uppercase tracking-wider mb-1 font-semibold">Подпись</div>
                                    <pre class="whitespace-pre-wrap font-sans m-0 text-fg-1">{{ $sig }}</pre>
                                </div>
                            @endif
                            @if($quotePlain)
                                <div class="px-3 py-2">
                                    <div class="text-[11px] text-fg-3 uppercase tracking-wider mb-1.5 font-semibold">Цитата исходного письма</div>
                                    <pre class="whitespace-pre-wrap font-sans m-0 text-fg-2 text-[12px] leading-[1.5] max-h-[260px] overflow-auto pl-3 border-l-2 border-border-strong">{{ $quotePlain }}</pre>
                                </div>
                            @endif
                        </div>
                    </details>
                @endif

                {{-- Attachments --}}
                <div>
                    <div class="text-[12px] text-fg-3 uppercase tracking-wider font-semibold mb-1.5">
                        Вложения
                        @php $atts = $this->attachments; @endphp
                        @if($atts->isNotEmpty())
                            <span class="text-fg-1 ml-1">({{ $atts->count() }})</span>
                        @endif
                    </div>

                    {{-- Список уже прикреплённых файлов. --}}
                    @if($atts->isNotEmpty())
                        <div class="flex flex-wrap gap-2 mb-2">
                            @foreach($atts as $att)
                                <span class="att inline-flex items-center gap-2 px-2.5 py-1.5 border border-border rounded-md bg-surface text-[12px]"
                                      wire:key="att-{{ $att->id }}">
                                    <span class="inline-block w-4 h-5 bg-red-50 border border-red-300 rounded-sm text-red-700 text-[7px] font-bold text-center leading-5">
                                        {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::afterLast($att->filename, '.')) ?: 'FILE' }}
                                    </span>
                                    <span class="text-fg-1 max-w-[260px] truncate" title="{{ $att->filename }}">{{ $att->filename }}</span>
                                    <span class="text-fg-3">· {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>
                                    <button type="button"
                                            wire:click="removeAttachment({{ $att->id }})"
                                            wire:confirm="Удалить вложение {{ $att->filename }}?"
                                            class="text-red-700 hover:text-red-900 ml-1 text-[14px] leading-none"
                                            title="Удалить">×</button>
                                </span>
                            @endforeach
                        </div>
                    @endif

                    {{-- Drop-zone: добавить файлы кликом или drag&drop.
                         Прикрепляется автоматически после выбора
                         (см. ComposeForm::updatedNewFiles). --}}
                    <div x-data="{
                            isDragging: false,
                            handleDrop(event) {
                                this.isDragging = false;
                                const dropped = event.dataTransfer?.files;
                                if (!dropped || dropped.length === 0) return;
                                const input = $refs.fileInput;
                                const dt = new DataTransfer();
                                // Сохраняем уже выбранные (если есть) + добавляем новые.
                                if (input.files) {
                                    for (const f of input.files) dt.items.add(f);
                                }
                                for (const f of dropped) dt.items.add(f);
                                input.files = dt.files;
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                        }"
                        @dragenter.prevent="isDragging = true"
                        @dragover.prevent="isDragging = true"
                        @dragleave.prevent="isDragging = false"
                        @drop.prevent="handleDrop($event)"
                        :class="isDragging ? 'border-[var(--sky-500)] bg-[var(--sky-50)]' : 'border-border'"
                        class="border border-dashed rounded-md px-3 py-3 transition-colors cursor-pointer"
                        @click="$refs.fileInput.click()">
                        <input type="file" wire:model="newFiles" multiple
                               x-ref="fileInput"
                               class="hidden" />
                        <div class="flex items-center gap-2 text-[12px] pointer-events-none">
                            <span class="text-fg-2">📎</span>
                            <span class="text-fg-1" x-show="!isDragging">
                                Перетащите файлы сюда или
                                <span class="underline text-fg-1">нажмите для выбора</span>
                            </span>
                            <span class="text-[var(--sky-700)] font-medium" x-show="isDragging" x-cloak>
                                Отпустите, чтобы прикрепить
                            </span>
                            <span class="flex-1"></span>
                            <span wire:loading wire:target="newFiles,uploadAttachments" class="text-amber-700">📎 Загружаем…</span>
                            @if($atts->isEmpty())
                                <span wire:loading.remove wire:target="newFiles,uploadAttachments" class="text-fg-3">до 25 МБ/файл</span>
                            @endif
                        </div>
                    </div>
                    @error('newFiles.*') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
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
                    <span wire:loading wire:target="updatedSubject,updatedToRaw,updatedCcRaw,updatedBodyText" class="text-amber-700">…</span>
                </span>
            </div>
        </div>
    @endif
</div>
