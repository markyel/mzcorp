{{-- Вложения письма: фото (лайтбокс), PDF (предпросмотр), прочее — скачать.
     Общий для вкладки «Переписка» и «Обзора». Параметры: $msg,
     $isImageAttachment, $isPdfAttachment. --}}
                                        @if($msg->attachments->isNotEmpty())
                                            @php
                                                // Галерея картинок этого письма для листания в лайтбоксе:
                                                // ← / → переключают между картинками одного письма.
                                                $msgImgs = $msg->attachments->filter(fn ($a) => $isImageAttachment($a))->values();
                                                $msgGallery = $msgImgs->map(fn ($a) => [
                                                    'src' => route('attachments.preview', $a),
                                                    'name' => $a->filename,
                                                    'dl' => route('attachments.download', $a),
                                                ])->all();
                                                $msgImgIdx = 0;
                                            @endphp
                                            <div class="mt-3 flex flex-wrap gap-2"
                                                 x-data="{ items: @js($msgGallery) }">
                                                @foreach($msg->attachments as $att)
                                                    @php
                                                        $isImg = $isImageAttachment($att);
                                                        $previewUrl = route('attachments.preview', $att);
                                                        $downloadUrl = route('attachments.download', $att);
                                                    @endphp
                                                    @if($isImg)
                                                        {{-- Image thumbnail → клик открывает лайтбокс с
                                                             пролистыванием всех картинок этого письма. --}}
                                                        <button type="button"
                                                                x-on:click="$dispatch('open-image', { items: items, index: {{ $msgImgIdx }} })"
                                                                class="block border border-border rounded-md overflow-hidden bg-surface hover:border-border-strong transition-colors text-left"
                                                                title="{{ $att->filename }}">
                                                            <img src="{{ $previewUrl }}"
                                                                 alt="{{ $att->filename }}"
                                                                 loading="lazy"
                                                                 class="w-[140px] h-[100px] object-cover block bg-app">
                                                            <div class="px-2 py-1 max-w-[140px] text-[11px] text-fg-3">
                                                                <span class="block truncate text-fg-1">{{ $att->filename }}</span>
                                                                @if($att->size_bytes)
                                                                    <span>{{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>
                                                                @endif
                                                            </div>
                                                        </button>
                                                        @php $msgImgIdx++; @endphp
                                                    @elseif($isPdfAttachment($att))
                                                        {{-- PDF → предпросмотр в модалке (iframe), как фото в лайтбоксе. --}}
                                                        <button type="button"
                                                                x-on:click="$dispatch('open-pdf', { src: @js($previewUrl), name: @js($att->filename), dl: @js($downloadUrl) })"
                                                                class="inline-flex items-center gap-1.5 px-2.5 py-1.5 border border-border rounded-md bg-surface text-[12px] text-fg-1 hover:bg-hover text-left"
                                                                title="Открыть предпросмотр — {{ $att->filename }}">
                                                            <span class="inline-block w-4 h-5 bg-red-50 border border-red-300 rounded-sm text-red-700 text-[7px] font-bold text-center leading-5">PDF</span>
                                                            <span class="truncate max-w-[240px]">{{ $att->filename }}</span>
                                                            @if($att->size_bytes)
                                                                <span class="text-fg-3 text-[11px]">· {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>
                                                            @endif
                                                            <span class="text-sky-700 text-[11px]">👁 просмотр</span>
                                                        </button>
                                                    @else
                                                        <a href="{{ $downloadUrl }}"
                                                           class="inline-flex items-center gap-1.5 px-2.5 py-1.5 border border-border rounded-md bg-surface text-[12px] text-fg-1 hover:bg-hover">
                                                            <span class="inline-block w-4 h-5 bg-red-50 border border-red-300 rounded-sm text-red-700 text-[7px] font-bold text-center leading-5">
                                                                {{ Str::upper(Str::afterLast($att->filename, '.')) ?: 'BIN' }}
                                                            </span>
                                                            <span class="truncate max-w-[280px]">{{ $att->filename }}</span>
                                                            @if($att->size_bytes)
                                                                <span class="text-fg-3 text-[11px]">· {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>
                                                            @endif
                                                        </a>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif
