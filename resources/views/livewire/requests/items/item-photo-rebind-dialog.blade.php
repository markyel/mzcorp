<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: flex-start; justify-content: center; padding: 60px 24px 24px;"
             wire:mousedown.self="close">
            <div class="ds-card p-5 w-full max-w-[760px] max-h-[80vh] flex flex-col" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-1">
                    Фото позиции
                </h3>
                <div class="text-[12px] text-fg-3 mb-3">
                    Можно привязать несколько фото и назначить одно главным (★) — оно показывается миниатюрой в списке позиций. Клик по фото — выбрать/снять, клик по звезде — сделать главным.
                </div>

                @php $photos = $this->photoAttachments; @endphp

                {{-- Subject — текущая позиция + превью главного фото. --}}
                @php $subject = $this->subjectItem; @endphp
                @if($subject)
                    @php
                        $mainAtt = $mainId ? $photos->firstWhere('id', $mainId) : null;
                        $mainIsImage = $mainAtt && str_starts_with((string) $mainAtt->mime_type, 'image/');
                    @endphp
                    <div class="border border-border rounded-md bg-surface-2 px-3 py-2.5 mb-3 flex gap-3 items-start">
                        @if($mainIsImage)
                            <div class="w-12 h-12 border border-border rounded-sm overflow-hidden bg-app block shrink-0 relative">
                                <img src="{{ route('attachments.preview', $mainAtt) }}"
                                     alt="{{ $mainAtt->filename }}"
                                     class="w-12 h-12 object-cover block">
                                <span class="absolute top-0 right-0 text-[11px] bg-amber-400 text-amber-950 px-1 rounded-bl-sm">★</span>
                            </div>
                        @else
                            <div class="w-12 h-12 border border-border rounded-sm bg-app flex items-center justify-center text-[9px] text-fg-3 shrink-0">img</div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] text-fg-3 uppercase tracking-wider font-semibold mb-0.5">
                                Позиция #{{ $subject->position }}
                                <span class="ml-1 text-fg-2 normal-case font-normal">· выбрано фото: {{ count($selectedIds) }}</span>
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
                <div class="overflow-auto flex-1 -mx-1 px-1">
                    @if($photos->isEmpty())
                        <div class="py-8 text-center text-fg-3 text-[12.5px]">
                            В письмах заявки нет image-вложений.
                        </div>
                    @else
                        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 gap-2.5">
                            @foreach($photos as $att)
                                @php
                                    $isSelected = in_array($att->id, $selectedIds, true);
                                    $isMain = $mainId === $att->id;
                                    $previewUrl = route('attachments.preview', $att);
                                    $sizeKb = $att->size_bytes ? round($att->size_bytes / 1024) : null;
                                @endphp
                                <div wire:key="att-{{ $att->id }}" class="relative">
                                    <button type="button"
                                            wire:click="toggleAttachment({{ $att->id }})"
                                            class="w-full aspect-square border-2 rounded-md overflow-hidden transition-colors block
                                                   {{ $isSelected
                                                       ? 'border-[var(--accent)] ring-2 ring-[var(--accent)]/30'
                                                       : 'border-border hover:border-fg-3' }}">
                                        <img src="{{ $previewUrl }}"
                                             alt="{{ $att->filename ?: ('att #' . $att->id) }}"
                                             loading="lazy"
                                             class="w-full h-full object-cover {{ $isSelected ? '' : 'opacity-90' }}" />
                                        @if($isSelected)
                                            <span class="absolute top-1 left-1 w-5 h-5 rounded-full bg-[var(--accent)] text-white text-[11px] font-bold flex items-center justify-center shadow">✓</span>
                                        @endif
                                        <span class="absolute bottom-0 left-0 right-0 px-1.5 py-0.5 text-[10px] text-white bg-black/55 truncate">
                                            #{{ $att->id }}{{ $sizeKb ? ' · ' . $sizeKb . ' KB' : '' }}
                                        </span>
                                    </button>

                                    {{-- Звезда «главное»: всегда видна, кликабельна. --}}
                                    <button type="button"
                                            wire:click="setMain({{ $att->id }})"
                                            class="absolute top-1 right-1 w-6 h-6 rounded-full flex items-center justify-center text-[13px] shadow transition-colors
                                                   {{ $isMain
                                                       ? 'bg-amber-400 text-amber-950'
                                                       : 'bg-black/45 text-white/80 hover:bg-amber-400 hover:text-amber-950' }}"
                                            title="{{ $isMain ? 'Главное фото' : 'Сделать главным' }}">
                                        {{ $isMain ? '★' : '☆' }}
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                @error('selectedIds') <div class="text-red-700 text-[12px] mt-2">{{ $message }}</div> @enderror

                <div class="flex items-center gap-2 pt-3 mt-3 border-t border-border-subtle">
                    <button type="button" wire:click="save" class="btn btn-primary"
                            wire:loading.attr="disabled" wire:target="save">
                        @if(count($selectedIds) === 0)
                            Сохранить (без фото)
                        @else
                            Сохранить · {{ count($selectedIds) }} фото
                        @endif
                    </button>
                    @if(count($selectedIds) > 0)
                        <button type="button" wire:click="clearAll" class="btn"
                                title="Снять все привязки">Очистить</button>
                    @endif
                    <button type="button" wire:click="close" class="btn">Отмена</button>
                </div>
            </div>
        </div>
    @endif
</div>
