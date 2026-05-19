<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: flex-start; justify-content: center; padding: 60px 24px 24px;"
             wire:click.self="close">
            <div class="ds-card p-5 w-full {{ $comparing ? 'max-w-[1200px]' : 'max-w-[1040px]' }} max-h-[90vh] flex flex-col" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-1">
                    Привязать позицию к каталогу
                </h3>
                <div class="text-[12px] text-fg-3 mb-3">
                    Найдите подходящий товар каталога и нажмите «Привязать».
                </div>

                {{-- Subject — позиция заявки, к которой подбираем каталог. --}}
                @php $subject = $this->subjectItem; @endphp
                @if($subject)
                    @php
                        $subjQa = $subject->quality_assessment_status;
                        $subjQaConfig = match ($subjQa) {
                            'sufficient' => ['chip-ok', 'данных достаточно'],
                            'insufficient' => ['chip-attn', 'данных мало'],
                            'not_covered' => ['chip-neutral', 'нет правил'],
                            'assessment_failed' => ['chip-over', 'ошибка KB'],
                            'internal_catalog_pending' => ['chip-info', 'внутренний SKU · ждёт каталог'],
                            'internal_catalog_not_found' => ['chip-danger', 'нет в каталоге'],
                            default => null,
                        };
                        $subjExtracted = is_array($subject->quality_assessment_payload['extracted_parameters'] ?? null)
                            ? $subject->quality_assessment_payload['extracted_parameters']
                            : [];
                        $subjImg = $subject->imageAttachment;
                        $subjImgIsImage = $subjImg && str_starts_with((string) $subjImg->mime_type, 'image/');
                        // imgs / galleryItems нужны в обоих режимах (visual
                        // subject-плашка + compare-row). Раньше определялись
                        // внутри visual-блока, после оборачивания visual в
                        // if(!comparing) compare-режим падал с
                        // ErrorException Undefined variable galleryItems.
                        // (без at-токенов в комментарии: Blade pre-processor
                        // парсит at-if/at-endif даже внутри php-блока как
                        // реальные директивы и ломает баланс.)
                        $imgs = $this->emailImages;
                        $galleryItems = $imgs->map(fn ($i) => [
                            'src' => route('attachments.preview', $i),
                            'name' => $i->filename,
                            'dl' => route('attachments.download', $i),
                        ])->values()->all();
                    @endphp
                    {{-- Visual subject-плашка только в обычном режиме. В compare
                         subject и так рендерится первой строкой compare-стека —
                         дублирование съедает 30-40% высоты модала и заставляет
                         body-scroll отрабатывать вместо modal-scroll. --}}
                    @if(! $comparing)
                    <div class="border border-border rounded-md bg-surface-2 px-3 py-2.5 mb-3 flex gap-3 items-start">
                        {{-- Галерея всех image-вложений письма. Linked
                             (тот, что Vision привязал к этой позиции) — c
                             sky-ring, чтобы оператор сразу видел, какое
                             фото уже привязано, и мог сравнить с остальными.
                             Click открывает полноразмерный лайтбокс через
                             dispatch('open-image') — тот же глобальный
                             listener, что в Detail.
                             imgs / galleryItems определены в outer php-блоке
                             (line 27+) чтобы быть доступными и в compare-режиме. --}}
                        @if($imgs->isNotEmpty())
                            <div class="shrink-0 flex flex-col gap-1" style="max-width: 108px;"
                                 x-data="{ items: @js($galleryItems) }">
                                <div class="grid grid-cols-2 gap-1">
                                    @foreach($imgs->take(2) as $idx => $img)
                                        @php $isLinked = $subject && $subject->image_attachment_id === $img->id; @endphp
                                        <button type="button"
                                                x-on:click="$dispatch('open-image', { items: items, index: {{ $idx }} })"
                                                class="w-12 h-12 rounded-sm overflow-hidden bg-app block {{ $isLinked ? 'ring-2 ring-sky-500 border-0' : 'border border-border' }}"
                                                title="{{ $img->filename }}{{ $isLinked ? ' · привязано к этой позиции' : '' }}">
                                            <img src="{{ route('attachments.preview', $img) }}"
                                                 alt="{{ $img->filename }}"
                                                 loading="lazy"
                                                 class="w-12 h-12 object-cover block">
                                        </button>
                                    @endforeach
                                </div>
                                @if($imgs->count() > 2)
                                    <button type="button"
                                            x-on:click="$dispatch('open-image', { items: items, index: 2 })"
                                            class="text-[10px] text-sky-700 hover:text-sky-900 text-center"
                                            title="Открыть в просмотрщике с пролистыванием">
                                        +{{ $imgs->count() - 2 }} ещё →
                                    </button>
                                @endif
                            </div>
                        @elseif($subjImgIsImage)
                            {{-- Fallback: у заявки нет email_message_id (manual),
                                 но у позиции стоит привязанное фото. --}}
                            <button type="button"
                                    x-on:click="$dispatch('open-image', { src: @js(route('attachments.preview', $subjImg)), name: @js($subjImg->filename), dl: @js(route('attachments.download', $subjImg)) })"
                                    class="w-12 h-12 border border-border rounded-sm overflow-hidden bg-app block shrink-0"
                                    title="{{ $subjImg->filename }} — открыть">
                                <img src="{{ route('attachments.preview', $subjImg) }}"
                                     alt="{{ $subjImg->filename }}"
                                     class="w-12 h-12 object-cover block">
                            </button>
                        @else
                            <div class="w-12 h-12 border border-border rounded-sm bg-app flex items-center justify-center text-[9px] text-fg-3 shrink-0">img</div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] text-fg-3 uppercase tracking-wider font-semibold mb-0.5">
                                Ищем для позиции
                                @if($subject->request)
                                    <span class="mono text-fg-2 normal-case">· {{ $subject->request->internal_code }} · поз. {{ $subject->position }}</span>
                                @endif
                            </div>
                            <div class="font-medium text-[13px] text-fg-1 leading-tight">{{ $subject->parsed_name ?: '(без названия)' }}</div>
                            <div class="text-[11.5px] text-fg-3 mt-1 flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                                @if($subject->brand)
                                    <span class="inline-flex items-center px-1.5 rounded-sm bg-emerald-50 text-emerald-800 font-semibold text-[10.5px]">{{ $subject->brand->name }}</span>
                                @elseif($subject->parsed_brand)
                                    <span>{{ $subject->parsed_brand }}</span>
                                @endif
                                @if($subject->kbCategory)
                                    <span class="inline-flex items-center px-1.5 rounded-sm bg-sky-50 text-sky-800 font-medium text-[10.5px]">{{ $subject->kbCategory->name }}</span>
                                @endif
                                @if($subjQaConfig)
                                    <span class="chip {{ $subjQaConfig[0] }} text-[10.5px]"><span class="dot"></span>{{ $subjQaConfig[1] }}</span>
                                @endif
                                @if($subject->parsed_article)
                                    <span class="mono text-fg-2">{{ $subject->parsed_article }}</span>
                                @endif
                                @if($subject->parsed_qty)
                                    <span class="text-fg-2">· {{ rtrim(rtrim((string) $subject->parsed_qty, '0'), '.') }} {{ $subject->parsed_unit }}</span>
                                @endif
                                @if($subject->supplier_note)
                                    <span class="inline-flex items-center px-1.5 rounded-sm bg-amber-50 text-amber-700 font-medium text-[10.5px]">
                                        {{ \Illuminate\Support\Str::limit($subject->supplier_note, 60) }}
                                    </span>
                                @endif
                                {{-- KB extracted_parameters inline в той же строке —
                                     раньше были отдельным блоком, занимали лишнюю
                                     высоту шапки. --}}
                                @if(! empty($subjExtracted))
                                    @foreach(array_slice($subjExtracted, 0, 6, true) as $slug => $value)
                                        <span class="mono"><span class="text-fg-3">{{ $slug }}:</span> <span class="text-fg-2">{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</span></span>
                                    @endforeach
                                    @if(count($subjExtracted) > 6)
                                        <span class="text-fg-3 mono">… +{{ count($subjExtracted) - 6 }}</span>
                                    @endif
                                @endif
                            </div>
                            @if($subject->catalogItem)
                                <div class="text-[11.5px] text-fg-3 mt-1.5 pt-1.5 border-t border-border-subtle">
                                    Сейчас привязана:
                                    <span class="mono text-fg-1">{{ $subject->catalogItem->sku }}</span>
                                    · {{ $subject->catalogItem->brand ?: '—' }}
                                    @if($subject->catalogItem->brand_article)
                                        · <span class="mono">{{ $subject->catalogItem->brand_article }}</span>
                                    @endif
                                    @if($subject->catalogItem->price !== null)
                                        · {{ number_format((float) $subject->catalogItem->price, 2, '.', ' ') }} ₽
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                    @endif
                @endif

                @if(! $comparing)
                {{-- Tabs --}}
                <div class="flex gap-1 mb-3 border-b border-border-subtle">
                    <button type="button" wire:click="setMode('text')"
                            class="px-3 py-1.5 text-[12px] font-medium border-b-2 {{ $mode === 'text' ? 'border-[var(--accent)] text-fg-1' : 'border-transparent text-fg-3 hover:text-fg-1' }}">
                        🔎 По тексту
                    </button>
                    <button type="button" wire:click="setMode('similar')"
                            class="px-3 py-1.5 text-[12px] font-medium border-b-2 {{ $mode === 'similar' ? 'border-[var(--accent)] text-fg-1' : 'border-transparent text-fg-3 hover:text-fg-1' }}">
                        ✨ Похожие из каталога
                    </button>
                    <span class="flex-1"></span>
                    {{-- Compare-toolbar справа. Активен когда выбраны 1-3 позиций. --}}
                    @if(count($compareIds) > 0)
                        <button type="button" wire:click="enterCompare"
                                class="btn btn-sm self-center"
                                title="Открыть сравнение: позиция заявки vs выбранные каталожные кандидаты">
                            ⚖️ Сравнить ({{ count($compareIds) }})
                        </button>
                        <button type="button" wire:click="clearCompare"
                                class="btn btn-sm self-center"
                                title="Снять все галочки">
                            ✕
                        </button>
                    @endif
                </div>

                {{-- Chip-row фильтров (общий для text + similar). Применяются
                     к results post-fetch в applyChipFilters. Default OFF —
                     toggle'ом меняется без перезагрузки. Каждый chip показывает
                     значение которое будет применено (бренд subject'а,
                     keyword KB-категории, размеры из parsed_name). --}}
                @php
                    $subjBrand = $subject?->brand?->name ?: $subject?->parsed_brand;
                    $subjCategoryName = $subject?->kbCategory?->name;
                    $subjDims = $this->subjectDimensions;
                @endphp
                @if($subjBrand || $subjCategoryName || ! empty($subjDims))
                    <div class="flex flex-wrap items-center gap-1.5 mb-2 text-[11.5px]">
                        <span class="text-fg-3 uppercase tracking-wider text-[10px] font-semibold mr-1">Фильтры:</span>
                        @if($subjBrand)
                            <button type="button" wire:click="toggleBrandFilter"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-sm border {{ $filterBrand ? 'bg-sky-100 border-sky-300 text-sky-900' : 'bg-surface-2 border-border text-fg-2 hover:bg-surface' }}"
                                    title="Показывать только позиции каталога с brand = «{{ $subjBrand }}»">
                                <span class="text-[10px]">{{ $filterBrand ? '✓' : '+' }}</span>
                                <span>бренд:</span>
                                <span class="font-semibold">{{ $subjBrand }}</span>
                            </button>
                        @endif
                        @if($subjCategoryName)
                            <button type="button" wire:click="toggleCategoryFilter"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-sm border {{ $filterCategory ? 'bg-emerald-100 border-emerald-300 text-emerald-900' : 'bg-surface-2 border-border text-fg-2 hover:bg-surface' }}"
                                    title="Показывать каталог где name / unit_name / part_type содержит ключевое слово категории">
                                <span class="text-[10px]">{{ $filterCategory ? '✓' : '+' }}</span>
                                <span>категория:</span>
                                <span class="font-semibold">{{ $subjCategoryName }}</span>
                            </button>
                        @endif
                        @if(! empty($subjDims))
                            <button type="button" wire:click="toggleDimsFilter"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-sm border {{ $filterDims ? 'bg-amber-100 border-amber-300 text-amber-900' : 'bg-surface-2 border-border text-fg-2 hover:bg-surface' }}"
                                    title="Показывать каталог где хотя бы один размер (size_a..f) совпадает с одним из {{ implode(', ', $subjDims) }} ±5 мм">
                                <span class="text-[10px]">{{ $filterDims ? '✓' : '+' }}</span>
                                <span>размер:</span>
                                <span class="font-semibold mono">{{ implode(' · ', $subjDims) }}</span>
                                <span class="text-[10px] text-fg-3">мм ±5</span>
                            </button>
                        @endif
                    </div>
                @endif

                {{-- Chip-row «узел» — fallback когда KB-категория не определена.
                     Distinct unit_name из текущих результатов с counts (top-8).
                     Exclusive single-select: кликнул → оставляем только этот
                     узел; кликнул снова → снимаем фильтр. --}}
                @php $availableUnits = $this->availableUnits; @endphp
                @if(! empty($availableUnits))
                    <div class="flex flex-wrap items-center gap-1.5 mb-2 text-[11.5px]">
                        <span class="text-fg-3 uppercase tracking-wider text-[10px] font-semibold mr-1">Узел:</span>
                        @foreach($availableUnits as $unitName => $unitCount)
                            <button type="button"
                                    wire:click="toggleUnitFilter(@js($unitName))"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-sm border {{ $filterUnit === $unitName ? 'bg-violet-100 border-violet-300 text-violet-900' : 'bg-surface-2 border-border text-fg-2 hover:bg-surface' }}"
                                    title="Показывать только каталог где unit_name = «{{ $unitName }}»">
                                <span class="text-[10px]">{{ $filterUnit === $unitName ? '✓' : '+' }}</span>
                                <span>{{ $unitName }}</span>
                                <span class="text-fg-3">({{ $unitCount }})</span>
                            </button>
                        @endforeach
                        @if($filterUnit !== null)
                            <button type="button" wire:click="toggleUnitFilter(@js($filterUnit))"
                                    class="text-fg-3 hover:text-red-700 text-[12px] ml-1"
                                    title="Снять фильтр узла">✕</button>
                        @endif
                    </div>
                @endif

                @if($mode === 'text')
                    <input type="text" wire:model.live.debounce.300ms="query"
                           autofocus
                           placeholder="например: M02016 или 3RT2016 или Кнопка вызывная"
                           class="w-full h-[36px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono mb-3" />

                    @error('query') <div class="text-red-700 text-[12px] mb-2">{{ $message }}</div> @enderror

                    @php $results = $this->textResults; @endphp

                    <div class="flex-1 min-h-0 overflow-y-auto overflow-x-hidden border border-border-subtle rounded-md" style="min-height: 0">
                        @if(mb_strlen(trim($query)) < 2)
                            <div class="px-3 py-6 text-center text-fg-3 text-[12px]">
                                Введите минимум 2 символа для поиска.
                            </div>
                        @elseif(empty($results))
                            <div class="px-3 py-6 text-center text-fg-3 text-[12px]">
                                @if($filterBrand || $filterCategory || $filterDims || $filterUnit !== null)
                                    Все кандидаты отфильтрованы chip'ами выше — снимите хотя бы один.
                                @else
                                    Ничего не найдено. Попробуйте «Похожие из каталога».
                                @endif
                            </div>
                        @else
                            @include('livewire.requests.items._catalog-results-table', [
                                'rows' => collect($results),
                                'selectedId' => $selectedCatalogId,
                                'compareIds' => $compareIds,
                            ])
                        @endif
                    </div>
                @else
                    {{-- similar mode --}}
                    <div class="flex gap-1.5 mb-2">
                        <input type="text"
                               wire:model="similarQuery"
                               wire:keydown.enter="applySimilarQuery"
                               placeholder="например: Плата ПКЛ-32, или ролик уравновешивания"
                               class="flex-1 h-[36px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                        <button type="button" wire:click="applySimilarQuery"
                                class="btn"
                                wire:loading.attr="disabled" wire:target="applySimilarQuery,similarResults">
                            🔍 Искать
                        </button>
                        @if($similarQueryActive !== '')
                            <button type="button" wire:click="resetSimilarQuery"
                                    class="btn"
                                    title="Вернуться к подбору по исходным данным позиции">
                                ↺ Сбросить
                            </button>
                        @endif
                    </div>
                    <div class="text-[11.5px] text-fg-3 mb-2 flex items-center gap-2">
                        @if($similarQueryActive !== '')
                            <span>Поиск по запросу: <span class="mono text-fg-2">«{{ $similarQueryActive }}»</span> · top-10.</span>
                        @else
                            <span>Vector-поиск по KB-эмбеддингам исходных данных позиции, top-10 по убыванию похожести.
                                Можно ввести свой запрос выше и нажать «Искать».</span>
                        @endif
                        <span wire:loading wire:target="similarResults,setMode,applySimilarQuery,resetSimilarQuery" class="text-amber-700">⏳ ищем…</span>
                    </div>

                    @php $simResults = $this->similarResults; @endphp

                    <div class="flex-1 min-h-0 overflow-y-auto overflow-x-hidden border border-border-subtle rounded-md" style="min-height: 0">
                        @if(empty($simResults))
                            <div class="px-3 py-6 text-center text-fg-3 text-[12px]"
                                 wire:loading.remove wire:target="similarResults,setMode">
                                @if($filterBrand || $filterCategory || $filterDims || $filterUnit !== null)
                                    Все кандидаты отфильтрованы chip'ами выше — снимите хотя бы один.
                                @else
                                    Не удалось получить похожие позиции (возможно, у позиции пусто название/бренд, либо
                                    эмбеддинг-сервис недоступен).
                                @endif
                            </div>
                        @else
                            @include('livewire.requests.items._catalog-results-table', [
                                'rows' => collect($simResults),
                                'selectedId' => $selectedCatalogId,
                                'compareIds' => $compareIds,
                            ])
                        @endif
                    </div>
                @endif
                @else
                    {{-- ─────────── Compare-панель (grid columns) ───────────
                         Колонки: subject + N catalog кандидатов рядом, чтобы
                         оператор мог визуально сравнить характеристики
                         столбец-в-столбец.

                         Фото cap 210x210 чтобы не растягивалось на всю
                         ширину колонки и не выпускало модал за viewport.

                         min-h-0 на flex-1 wrapper — критично: без него
                         внутренний overflow-y-auto не работает (flex-item
                         имеет min-height:auto по дефолту, контент пуш-ит
                         родителя за пределы 90vh, скролл уходит на body). --}}
                    @php
                        $cmp = $this->compareItems;
                        $cols = $cmp->count() + 1;
                    @endphp
                    <div class="flex items-center gap-2 mb-3">
                        <button type="button" wire:click="exitCompare" class="btn btn-sm">← К списку</button>
                        <span class="text-[12px] text-fg-3">Сравнение: позиция заявки vs {{ $cmp->count() }} {{ $cmp->count() === 1 ? 'кандидат' : ($cmp->count() < 5 ? 'кандидата' : 'кандидатов') }}</span>
                    </div>

                    {{-- inline style="min-height:0" дублирует Tailwind-класс min-h-0
                         на случай если CSS не пересобран после деплоя
                         (Tailwind 4 JIT генерирует классы только при npm run build). --}}
                    <div class="flex-1 min-h-0 overflow-y-auto overflow-x-auto" style="min-height: 0">
                        <div class="grid gap-3" style="grid-template-columns: repeat({{ $cols }}, minmax(280px, 1fr));">
                            {{-- ── Subject column (позиция заявки) ── --}}
                            <div class="border border-sky-300 rounded-md bg-sky-50/40 p-3 flex flex-col gap-2">
                                <div class="text-[10.5px] uppercase tracking-wider text-sky-700 font-semibold">Позиция заявки</div>
                                @if($subject)
                                    @php
                                        $startIdx = 0;
                                        foreach ($galleryItems as $i => $g) {
                                            if (($imgs[$i] ?? null) && $imgs[$i]->id === ($subject->image_attachment_id ?? null)) {
                                                $startIdx = $i;
                                                break;
                                            }
                                        }
                                    @endphp
                                    @if(! empty($galleryItems))
                                        <div x-data="{ idx: {{ $startIdx }}, items: @js($galleryItems) }"
                                             class="relative w-full max-w-[210px] mx-auto">
                                            <button type="button"
                                                    x-on:click="$dispatch('open-image', { items: items, index: idx })"
                                                    class="block w-full aspect-square rounded-sm overflow-hidden bg-app border border-border">
                                                <img :src="items[idx].src" :alt="items[idx].name" class="w-full h-full object-cover" loading="lazy">
                                            </button>
                                            <template x-if="items.length > 1">
                                                <div>
                                                    <button type="button"
                                                            x-on:click.stop="idx = (idx - 1 + items.length) % items.length"
                                                            title="Предыдущее фото"
                                                            style="position: absolute; left: 4px; top: 50%; transform: translateY(-50%); width: 28px; height: 28px; border-radius: 50%; border: none; background: rgba(0,0,0,0.55); color: white; font-size: 16px; cursor: pointer;">‹</button>
                                                    <button type="button"
                                                            x-on:click.stop="idx = (idx + 1) % items.length"
                                                            title="Следующее фото"
                                                            style="position: absolute; right: 4px; top: 50%; transform: translateY(-50%); width: 28px; height: 28px; border-radius: 50%; border: none; background: rgba(0,0,0,0.55); color: white; font-size: 16px; cursor: pointer;">›</button>
                                                    <div style="position: absolute; bottom: 4px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.55); color: white; padding: 1px 6px; border-radius: 8px; font-size: 10px; font-family: var(--font-mono);">
                                                        <span x-text="idx + 1"></span>/<span x-text="items.length"></span>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    @elseif($subjImgIsImage)
                                        <button type="button"
                                                x-on:click="$dispatch('open-image', { src: @js(route('attachments.preview', $subjImg)), name: @js($subjImg->filename), dl: @js(route('attachments.download', $subjImg)) })"
                                                class="block w-full max-w-[210px] mx-auto aspect-square rounded-sm overflow-hidden bg-app border border-border">
                                            <img src="{{ route('attachments.preview', $subjImg) }}" class="w-full h-full object-cover" loading="lazy">
                                        </button>
                                    @else
                                        <div class="w-full max-w-[210px] mx-auto aspect-square rounded-sm bg-app border border-border flex items-center justify-center text-[10px] text-fg-3">нет фото</div>
                                    @endif
                                    <div class="font-medium text-[13px] text-fg-1 leading-tight">{{ $subject->parsed_name ?: '(без названия)' }}</div>
                                    <dl class="text-[11.5px] grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5">
                                        <dt class="text-fg-3">Бренд:</dt>
                                        <dd class="text-fg-1">{{ $subject->brand?->name ?: $subject->parsed_brand ?: '—' }}</dd>
                                        <dt class="text-fg-3">Артикул:</dt>
                                        <dd class="mono text-fg-1 break-all">{{ $subject->parsed_article ?: '—' }}</dd>
                                        <dt class="text-fg-3">Категория:</dt>
                                        <dd class="text-fg-1">{{ $subject->kbCategory?->name ?: '—' }}</dd>
                                        <dt class="text-fg-3">Кол-во:</dt>
                                        <dd class="text-fg-1">{{ $subject->parsed_qty ? rtrim(rtrim((string) $subject->parsed_qty, '0'), '.') . ' ' . $subject->parsed_unit : '—' }}</dd>
                                        @if($subject->supplier_note)
                                            <dt class="text-fg-3">Примечание:</dt>
                                            <dd class="text-amber-800">{{ $subject->supplier_note }}</dd>
                                        @endif
                                    </dl>
                                    @if(! empty($subjExtracted))
                                        <div class="border-t border-border-subtle pt-1.5 mt-1 text-[11px]">
                                            <div class="text-fg-3 uppercase tracking-wider text-[10px] mb-0.5">KB-параметры</div>
                                            @foreach($subjExtracted as $slug => $value)
                                                <div class="mono"><span class="text-fg-3">{{ $slug }}:</span> <span class="text-fg-1">{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</span></div>
                                            @endforeach
                                        </div>
                                    @endif
                                @endif
                            </div>

                            {{-- ── Catalog columns ── --}}
                            @foreach($cmp as $c)
                                <div class="border border-border rounded-md bg-surface p-3 flex flex-col gap-2 {{ $selectedCatalogId === $c->id ? 'ring-2 ring-emerald-400' : '' }}">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">Каталог</span>
                                        <button type="button" wire:click="toggleCompare({{ $c->id }})"
                                                class="text-fg-3 hover:text-red-700 text-[12px]" title="Убрать">✕</button>
                                    </div>
                                    @if($c->photo_url)
                                        <a href="{{ $c->photo_url }}" target="_blank" rel="noopener noreferrer"
                                           class="block w-full max-w-[210px] mx-auto aspect-square rounded-sm overflow-hidden bg-app border border-border">
                                            <img src="{{ $c->photo_url }}" class="w-full h-full object-cover" loading="lazy" referrerpolicy="no-referrer">
                                        </a>
                                    @else
                                        <div class="w-full max-w-[210px] mx-auto aspect-square rounded-sm bg-app border border-border flex items-center justify-center text-[10px] text-fg-3">нет фото</div>
                                    @endif
                                    <div class="font-medium text-[13px] text-fg-1 leading-tight">{{ $c->name }}</div>
                                    <dl class="text-[11.5px] grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5">
                                        <dt class="text-fg-3">SKU:</dt>
                                        <dd class="mono text-fg-1">{{ $c->sku }}</dd>
                                        <dt class="text-fg-3">Бренд:</dt>
                                        <dd class="text-fg-1">{{ $c->brand ?: '—' }}</dd>
                                        <dt class="text-fg-3">Артикул:</dt>
                                        <dd class="mono text-fg-1 break-all">{{ $c->brand_article ?: '—' }}</dd>
                                        @php $articles = is_array($c->articles) ? array_filter($c->articles) : []; @endphp
                                        @if(count($articles) > 1)
                                            <dt class="text-fg-3">Все OEM:</dt>
                                            <dd class="mono text-fg-2 break-all text-[10.5px]">{{ implode(' · ', $articles) }}</dd>
                                        @endif
                                        @if($c->unit_name)
                                            <dt class="text-fg-3">Узел:</dt>
                                            <dd class="text-fg-1">{{ $c->unit_name }}</dd>
                                        @endif
                                        @if($c->part_type)
                                            <dt class="text-fg-3">Тип:</dt>
                                            <dd class="text-fg-1">{{ $c->part_type }}</dd>
                                        @endif
                                        @if($c->form_factor)
                                            <dt class="text-fg-3">Форм-фактор:</dt>
                                            <dd class="text-fg-1">{{ $c->form_factor }}</dd>
                                        @endif
                                        <dt class="text-fg-3">Цена:</dt>
                                        <dd class="mono text-fg-1">{{ $c->price !== null ? number_format((float) $c->price, 2, '.', ' ') . ' ₽' : '—' }}</dd>
                                        <dt class="text-fg-3">Наличие:</dt>
                                        <dd>
                                            @if($c->stock_available === null)
                                                <span class="text-fg-3">—</span>
                                            @elseif($c->stock_available > 0)
                                                <span class="text-emerald-700">{{ $c->stock_available }} шт</span>
                                            @else
                                                <span class="text-amber-700">нет</span>
                                            @endif
                                        </dd>
                                        @if($c->weight !== null)
                                            <dt class="text-fg-3">Вес:</dt>
                                            <dd class="text-fg-1">{{ rtrim(rtrim((string) $c->weight, '0'), '.') }} кг</dd>
                                        @endif
                                        @php
                                            $dims = array_filter([
                                                'A' => $c->size_a, 'B' => $c->size_b, 'C' => $c->size_c,
                                                'D' => $c->size_d, 'E' => $c->size_e, 'F' => $c->size_f,
                                            ], fn ($v) => $v !== null);
                                        @endphp
                                        @if(! empty($dims))
                                            <dt class="text-fg-3">Размеры:</dt>
                                            <dd class="text-fg-1 text-[10.5px]">
                                                @foreach($dims as $k => $v)
                                                    {{ $k }}={{ rtrim(rtrim((string) $v, '0'), '.') }}@if(! $loop->last) · @endif
                                                @endforeach
                                            </dd>
                                        @endif
                                        @if(! $c->is_active)
                                            <dt class="text-fg-3">Статус:</dt>
                                            <dd class="text-fg-3 uppercase text-[10px]">архив</dd>
                                        @endif
                                    </dl>
                                    <div class="pt-1.5 mt-auto border-t border-border-subtle flex gap-1.5">
                                        <button type="button" wire:click="selectCatalog({{ $c->id }})"
                                                class="btn btn-sm flex-1 {{ $selectedCatalogId === $c->id ? 'btn-primary' : '' }}">
                                            {{ $selectedCatalogId === $c->id ? '✓ Выбрано' : 'Выбрать' }}
                                        </button>
                                        <a href="https://mylift.ru/?text={{ urlencode($c->sku) }}&fn=find"
                                           target="_blank" rel="noopener noreferrer"
                                           class="btn btn-sm" title="Открыть на mylift.ru">↗</a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex items-center gap-2 pt-3 mt-3 border-t border-border-subtle">
                    <button type="button" wire:click="save" class="btn btn-primary"
                            @if(! $selectedCatalogId) disabled @endif
                            wire:loading.attr="disabled" wire:target="save">
                        Привязать
                    </button>
                    <button type="button" wire:click="close" class="btn">Отмена</button>
                    <span class="flex-1"></span>
                    @if($selectedCatalogId)
                        <span class="text-[12px] text-fg-3">
                            Выбран: <span class="mono text-fg-1">#{{ $selectedCatalogId }}</span>
                        </span>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
