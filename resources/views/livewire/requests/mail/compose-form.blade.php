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
                     Не редактируется. При send приклеивается автоматически. --}}
                @php
                    $sig = $this->signaturePreview;
                    $quoteHtml = $this->quotePreviewHtml;
                @endphp
                @if($sig || $quoteHtml)
                    <details class="border border-border rounded-md bg-surface">
                        <summary class="cursor-pointer px-3 py-2 text-[12px] text-fg-3 select-none">
                            При отправке к письму добавятся:
                            @if($sig) <span class="text-fg-1 font-medium">подпись</span> @endif
                            @if($sig && $quoteHtml) <span>+</span> @endif
                            @if($quoteHtml) <span class="text-fg-1 font-medium">цитата исходного письма</span> @endif
                            <span class="text-fg-3"> · нажмите чтобы посмотреть</span>
                        </summary>
                        <div class="border-t border-border-subtle">
                            @if($sig)
                                <div class="px-3 py-2 text-[12px] text-fg-2 border-b border-border-subtle bg-surface-2">
                                    <div class="text-[11px] text-fg-3 uppercase tracking-wider mb-1 font-semibold">Подпись</div>
                                    <pre class="whitespace-pre-wrap font-sans m-0">{{ $sig }}</pre>
                                </div>
                            @endif
                            @if($quoteHtml)
                                <div class="px-3 py-2">
                                    <div class="text-[11px] text-fg-3 uppercase tracking-wider mb-1.5 font-semibold">Цитата исходного письма</div>
                                    <iframe
                                        sandbox="allow-same-origin"
                                        srcdoc="{{ $quoteHtml }}"
                                        loading="lazy"
                                        class="w-full block border border-border-subtle rounded bg-surface"
                                        style="height: 180px;"
                                        x-data
                                        x-init="
                                            const fit = () => {
                                                try {
                                                    const h = $el.contentDocument && $el.contentDocument.documentElement
                                                        ? $el.contentDocument.documentElement.scrollHeight
                                                        : 180;
                                                    $el.style.height = Math.min(h + 4, 260) + 'px';
                                                } catch (e) {}
                                            };
                                            $el.addEventListener('load', () => {
                                                try {
                                                    const doc = $el.contentDocument;
                                                    if (!doc) return;
                                                    const s = doc.createElement('style');
                                                    s.textContent = 'html,body{margin:0;padding:0}body{padding:6px 10px;font:12px/1.5 system-ui,-apple-system,Segoe UI,Inter,sans-serif;color:#444;background:#fafafa;}body *{max-width:100%}img{max-width:100%;height:auto}';
                                                    (doc.head || doc.documentElement).appendChild(s);
                                                    fit();
                                                } catch (e) {}
                                            });
                                        "
                                    ></iframe>
                                </div>
                            @endif
                        </div>
                    </details>
                @endif

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
                    <span wire:loading wire:target="updatedSubject,updatedToRaw,updatedCcRaw,updatedBodyText" class="text-amber-700">…</span>
                </span>
            </div>
        </div>
    @endif
</div>
