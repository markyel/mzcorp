<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: flex-start; justify-content: center; padding: 60px 24px 24px;"
             wire:click.self="close">
            <div class="ds-card p-5 w-full max-w-[760px] max-h-[80vh] flex flex-col" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-1">
                    Сменить фото позиции
                </h3>
                <div class="text-[12px] text-fg-3 mb-3">
                    Vision-привязка не всегда точная: модель часто берёт общий план там, где есть closeup с маркировкой. Выберите нужное фото вручную или уберите привязку.
                </div>

                {{-- Subject — текущая позиция. --}}
                @php $subject = $this->subjectItem; @endphp
                @if($subject)
                    @php
                        $subjImg = $subject->imageAttachment;
                        $subjImgIsImage = $subjImg && str_starts_with((string) $subjImg->mime_type, 'image/');
                    @endphp
                    <div class="border border-border rounded-md bg-surface-2 px-3 py-2.5 mb-3 flex gap-3 items-start">
                        @if($subjImgIsImage)
                            <div class="w-12 h-12 border border-border rounded-sm overflow-hidden bg-app block shrink-0">
                                <img src="{{ route('attachments.preview', $subjImg) }}"
                                     alt="{{ $subjImg->filename }}"
                                     class="w-12 h-12 object-cover block">
                            </div>
                        @else
                            <div class="w-12 h-12 border border-border rounded-sm bg-app flex items-center justify-center text-[9px] text-fg-3 shrink-0">img</div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] text-fg-3 uppercase tracking-wider font-semibold mb-0.5">
                                Позиция #{{ $subject->position }}
                            </div>
                            <div class="font-medium text-[13px] text-fg-1 leading-tight">{{ $subject->parsed_name ?: '(без названия)' }}</div>
                            <div class="text-[11.5px] text-fg-3 mt-1 flex flex-wrap items-center gap-x-1.5">
                                @if($subject->parsed_brand)
                                    <span>{{ $subject->parsed_brand }}</span>
                                @endif
                                @if($subject->parsed_article)
                                    <span class="mono">· {{ $subject->parsed_article }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Grid фото-вложений. --}}
                @php $photos = $this->photoAttachments; @endphp
                <div class="overflow-auto flex-1 -mx-1 px-1">
                    @if($photos->isEmpty())
                        <div class="py-8 text-center text-fg-3 text-[12.5px]">
                            У письма нет image-вложений.
                        </div>
                    @else
                        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 gap-2.5">
                            {{-- «Без фото» — снять привязку. --}}
                            @php $noneSelected = $selectedAttachmentId === null; @endphp
                            <button type="button"
                                    wire:click="selectAttachment(null)"
                                    class="aspect-square border rounded-md flex flex-col items-center justify-center gap-1 transition-colors
                                           {{ $noneSelected
                                               ? 'border-[var(--red-500)] bg-[var(--red-50)]'
                                               : 'border-border bg-app hover:border-fg-3' }}">
                                <span class="text-[20px]">⊘</span>
                                <span class="text-[10.5px] {{ $noneSelected ? 'text-[var(--red-700)] font-semibold' : 'text-fg-3' }}">без фото</span>
                            </button>

                            @foreach($photos as $att)
                                @php
                                    $isSelected = $selectedAttachmentId === $att->id;
                                    $previewUrl = route('attachments.preview', $att);
                                    $sizeKb = $att->size_bytes ? round($att->size_bytes / 1024) : null;
                                @endphp
                                <button type="button"
                                        wire:key="att-{{ $att->id }}"
                                        wire:click="selectAttachment({{ $att->id }})"
                                        class="relative aspect-square border-2 rounded-md overflow-hidden transition-colors group
                                               {{ $isSelected
                                                   ? 'border-[var(--accent)] ring-2 ring-[var(--accent)]/30'
                                                   : 'border-border hover:border-fg-3' }}">
                                    <img src="{{ $previewUrl }}"
                                         alt="{{ $att->filename ?: ('att #' . $att->id) }}"
                                         loading="lazy"
                                         class="w-full h-full object-cover" />
                                    @if($isSelected)
                                        <span class="absolute top-1 right-1 w-5 h-5 rounded-full bg-[var(--accent)] text-white text-[11px] font-bold flex items-center justify-center shadow">✓</span>
                                    @endif
                                    <span class="absolute bottom-0 left-0 right-0 px-1.5 py-0.5 text-[10px] text-white bg-black/55 truncate">
                                        #{{ $att->id }}{{ $sizeKb ? ' · ' . $sizeKb . ' KB' : '' }}
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                @error('selectedAttachmentId') <div class="text-red-700 text-[12px] mt-2">{{ $message }}</div> @enderror

                <div class="flex items-center gap-2 pt-3 mt-3 border-t border-border-subtle">
                    <button type="button" wire:click="save" class="btn btn-primary"
                            wire:loading.attr="disabled" wire:target="save">
                        @if($selectedAttachmentId === null)
                            Снять фото
                        @else
                            Привязать выбранное
                        @endif
                    </button>
                    <button type="button" wire:click="close" class="btn">Отмена</button>
                </div>
            </div>
        </div>
    @endif
</div>
