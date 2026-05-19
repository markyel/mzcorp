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
                    {{-- ─────────── Compare-панель (rich grid) ───────────
                         Inverted layout: каждая строка = один параметр,
                         каждая колонка = один кандидат (+ subject слева).
                         Sticky-колонка имён параметров + sticky-строка с фото
                         делают сравнение читаемым при горизонтальной прокрутке.

                         min-h-0 на flex-1 wrapper — flexbox-gotcha, иначе
                         scroll уходит на body (flex-item имеет min-height:auto
                         по дефолту). --}}
                    @php
                        $cmp = $this->compareItems;
                        $compData = $this->comparisonData;
                        $candidates = $compData['candidates'] ?? [];
                        $sections = $compData['sections'] ?? [];
                        $subjQty = $compData['subjectQty'] ?? 0;
                        // Колонки grid: params(220px) + subject(280px) + N×280px
                        $gridCols = '220px 280px ' . str_repeat('280px ', count($candidates));
                    @endphp

                    {{-- HEAD bar: back + count + hint --}}
                    <div class="flex items-center gap-3 mb-2 flex-wrap">
                        <button type="button" wire:click="exitCompare" class="btn btn-sm">← К списку</button>
                        <span class="text-[12px] text-fg-3">
                            Сравнение: позиция заявки vs <b class="text-fg-1 font-medium">{{ $cmp->count() }}</b>
                            {{ $cmp->count() === 1 ? 'кандидат' : ($cmp->count() < 5 ? 'кандидата' : 'кандидатов') }}
                        </span>
                        <span class="text-fg-3">·</span>
                        <span class="text-[12px] text-fg-3">выровнено по KB-параметрам · различия подсвечены</span>
                    </div>

                    {{-- TOOLBAR: view-switcher + чекбоксы --}}
                    <div class="flex items-center gap-3 mb-3 flex-wrap text-[12px]">
                        <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">Показывать:</span>
                        <label class="inline-flex items-center gap-1.5 cursor-pointer">
                            <input type="checkbox" wire:click="toggleOnlyDiff" @checked($showOnlyDiff) class="w-3.5 h-3.5 accent-[var(--accent)]">
                            <span>только различия</span>
                        </label>
                        <label class="inline-flex items-center gap-1.5 cursor-pointer">
                            <input type="checkbox" wire:click="toggleHighlight" @checked($showHighlight) class="w-3.5 h-3.5 accent-[var(--accent)]">
                            <span>подсветка совпадений</span>
                        </label>
                        <label class="inline-flex items-center gap-1.5 cursor-pointer">
                            <input type="checkbox" wire:click="togglePriceStock" @checked($showPriceStock) class="w-3.5 h-3.5 accent-[var(--accent)]">
                            <span>цены и наличие</span>
                        </label>
                        <span class="flex-1"></span>
                        <span class="text-fg-3 mono">{{ count($candidates) }} кандидат(ов)</span>
                    </div>

                    {{-- COMPARE GRID --}}
                    <div class="flex-1 min-h-0 overflow-auto border border-border rounded-md bg-app"
                         style="min-height: 0">
                        <div class="grid" style="grid-template-columns: {{ $gridCols }}; width: max-content; min-width: 100%;">
                            {{-- ─── HEADER ROW (sticky top) ─── --}}
                            {{-- corner cell (sticky left+top) --}}
                            <div class="bg-surface-2 border-b border-r border-border"
                                 style="position: sticky; top: 0; left: 0; z-index: 5;"></div>

                            {{-- subject column header — sticky-left (закреплено)
                                 + sticky-top одновременно (corner-like). --}}
                            <div class="p-3 border-b bg-sky-50"
                                 style="position: sticky; top: 0; left: 220px; z-index: 4; border-right: 2px solid var(--sky-500); box-shadow: 1px 0 0 var(--border), 8px 0 12px -10px rgba(15,18,23,0.20);">
                                <div class="text-[10.5px] uppercase tracking-wider text-sky-700 font-semibold mb-2 flex items-center gap-1.5">
                                    <span class="w-1.5 h-1.5 rounded-full bg-sky-600"></span>
                                    Позиция заявки
                                    <span class="ml-auto inline-flex items-center gap-1 text-[10px] normal-case font-normal bg-surface border border-border px-1.5 py-0.5 rounded text-fg-3" title="Колонка закреплена слева при горизонтальной прокрутке">📌 закреплено</span>
                                </div>
                                <div class="aspect-[1.6/1] rounded-md bg-app border border-border overflow-hidden mb-2 relative">
                                    @if(! empty($galleryItems))
                                        <div x-data="{ idx: 0, items: @js($galleryItems) }" class="w-full h-full">
                                            <button type="button"
                                                    x-on:click="$dispatch('open-image', { items: items, index: idx })"
                                                    class="block w-full h-full">
                                                <img :src="items[idx].src" :alt="items[idx].name" class="w-full h-full object-cover">
                                            </button>
                                            <template x-if="items.length > 1">
                                                <div>
                                                    <button type="button" x-on:click.stop="idx = (idx - 1 + items.length) % items.length"
                                                            style="position: absolute; left: 6px; top: 50%; transform: translateY(-50%); width: 24px; height: 24px; border-radius: 50%; background: rgba(15,18,23,.55); color: white; border: none; cursor: pointer; font-size: 14px;">‹</button>
                                                    <button type="button" x-on:click.stop="idx = (idx + 1) % items.length"
                                                            style="position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 24px; height: 24px; border-radius: 50%; background: rgba(15,18,23,.55); color: white; border: none; cursor: pointer; font-size: 14px;">›</button>
                                                    <div style="position: absolute; bottom: 6px; left: 6px; background: rgba(15,18,23,.65); color: white; font-size: 10.5px; padding: 2px 6px; border-radius: 3px; font-family: var(--font-mono);"
                                                         x-text="(idx+1)+' / '+items.length"></div>
                                                </div>
                                            </template>
                                        </div>
                                    @else
                                        <div class="w-full h-full flex items-center justify-center text-[10px] text-fg-3">нет фото</div>
                                    @endif
                                </div>
                                <div class="font-semibold text-[13.5px] text-sky-700 leading-tight mb-1.5"
                                     style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                                    {{ $subject->parsed_name ?: '(без названия)' }}
                                </div>
                                <div class="flex items-center gap-2 text-[11.5px] text-fg-3 flex-wrap">
                                    @if($subject->brand?->name ?? $subject->parsed_brand)
                                        <span class="font-semibold text-[10.5px] bg-neutral-100 text-neutral-700 px-1.5 py-0.5 rounded uppercase">{{ $subject->brand?->name ?? $subject->parsed_brand }}</span>
                                    @endif
                                    @if($subject->parsed_article)
                                        <span class="mono text-fg-2">{{ $subject->parsed_article }}</span>
                                    @endif
                                </div>
                                @if($subjQty > 0)
                                    <div class="mt-2">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11.5px] bg-sky-50 text-sky-700">{{ $subjQty }} шт.</span>
                                    </div>
                                @endif
                            </div>

                            {{-- candidate columns header --}}
                            @foreach($candidates as $idx => $cm)
                                @php
                                    $c = $cm['catalog'];
                                    $score = $cm['score'];
                                    $pctClass = $score === null
                                        ? 'bg-neutral-100 text-fg-3'
                                        : ($score >= 0.85 ? 'bg-emerald-50 text-emerald-700' : ($score >= 0.70 ? 'bg-amber-50 text-amber-700' : 'bg-neutral-100 text-fg-3'));
                                    $isSelected = $selectedCatalogId === $c->id;
                                @endphp
                                <div class="p-3 border-b border-r border-border {{ $isSelected ? 'bg-emerald-50/30' : 'bg-surface' }}"
                                     style="position: sticky; top: 0; z-index: 3;">
                                    <div class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold mb-2 flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $score && $score >= 0.85 ? 'bg-emerald-600' : ($score && $score >= 0.70 ? 'bg-amber-600' : 'bg-neutral-400') }}"></span>
                                        Каталог · кандидат {{ $idx + 1 }}
                                        <span class="ml-auto flex items-center gap-1">
                                            <span class="text-[10.5px] mono bg-surface border border-border px-1.5 py-0.5 rounded">{{ $c->sku }}</span>
                                            <button type="button" wire:click="toggleCompare({{ $c->id }})"
                                                    class="w-[18px] h-[18px] border border-border rounded text-fg-3 hover:text-red-700 flex items-center justify-center text-[12px]"
                                                    title="Убрать из сравнения">×</button>
                                        </span>
                                    </div>
                                    <div class="aspect-[1.6/1] rounded-md bg-app border border-border overflow-hidden mb-2">
                                        @if($c->photo_url)
                                            <a href="{{ $c->photo_url }}" target="_blank" rel="noopener noreferrer" class="block w-full h-full">
                                                <img src="{{ $c->photo_url }}" class="w-full h-full object-cover" loading="lazy" referrerpolicy="no-referrer">
                                            </a>
                                        @else
                                            <div class="w-full h-full flex items-center justify-center text-[10px] text-fg-3">нет фото</div>
                                        @endif
                                    </div>
                                    <div class="font-semibold text-[13.5px] text-fg-1 leading-tight mb-1.5"
                                         style="display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
                                        {{ $c->name }}
                                    </div>
                                    <div class="flex items-center gap-2 text-[11.5px] text-fg-3 flex-wrap mb-2">
                                        @if($c->brand)
                                            <span class="font-semibold text-[10.5px] bg-neutral-100 text-neutral-700 px-1.5 py-0.5 rounded uppercase">{{ \Illuminate\Support\Str::limit($c->brand, 12, '') }}</span>
                                        @endif
                                        @if($c->brand_article)
                                            <span class="mono text-fg-2">{{ $c->brand_article }}</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-1.5 flex-wrap mb-2.5">
                                        @if($score !== null)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11.5px] font-medium {{ $pctClass }}">{{ (int) round($score * 100) }}%</span>
                                        @endif
                                        @if($c->price !== null)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[12px] mono bg-surface-2 border border-border text-fg-1 font-semibold">{{ number_format((float) $c->price, 0, '.', ' ') }} ₽</span>
                                        @endif
                                        @if($c->stock_available !== null)
                                            @if($c->stock_available <= 0)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11.5px] bg-surface-2 text-fg-3">нет</span>
                                            @elseif($subjQty > 0 && $c->stock_available >= $subjQty)
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11.5px] bg-emerald-50 text-emerald-700">{{ $c->stock_available }} шт</span>
                                            @else
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11.5px] bg-amber-50 text-amber-700">{{ $c->stock_available }} шт</span>
                                            @endif
                                        @endif
                                    </div>
                                    <div class="flex gap-1.5">
                                        <button type="button" wire:click="selectCatalog({{ $c->id }})"
                                                class="btn btn-sm flex-1 {{ $isSelected ? 'btn-primary' : '' }}">
                                            {{ $isSelected ? '✓ Выбрано' : 'Выбрать' }}
                                        </button>
                                        <a href="https://mylift.ru/?text={{ urlencode($c->sku) }}&fn=find"
                                           target="_blank" rel="noopener noreferrer"
                                           class="btn btn-sm" title="Открыть на mylift.ru">↗</a>
                                    </div>
                                </div>
                            @endforeach

                            {{-- ─── SECTIONS ─── --}}
                            @foreach($sections as $section)
                                {{-- skip "Цена и наличие" section if toggle off --}}
                                @if(! $showPriceStock && $section['title'] === 'Цена и наличие')
                                    @continue
                                @endif

                                {{-- section header row spans all columns; params-col sticky-left,
                                     subject-col тоже sticky-left чтобы цельная section-полоса
                                     не рвалась при горизонтальном scroll. --}}
                                <div class="px-3 py-1.5 bg-neutral-100 border-b border-r border-border text-[11px] font-bold text-fg-1 uppercase tracking-wider"
                                     style="position: sticky; left: 0; z-index: 2;">
                                    {{ $section['title'] }}
                                </div>
                                <div class="bg-neutral-100 border-b border-border"
                                     style="position: sticky; left: 220px; z-index: 1; border-right: 2px solid var(--sky-500);"></div>
                                @for($i = 0; $i < count($candidates); $i++)
                                    <div class="bg-neutral-100 border-b border-r border-border"></div>
                                @endfor

                                @foreach($section['rows'] as $row)
                                    {{-- skip rows where everything matches if showOnlyDiff --}}
                                    @if($showOnlyDiff && $row['allMatch'])
                                        @continue
                                    @endif

                                    {{-- param-name cell (sticky left) --}}
                                    <div class="px-3 py-2 bg-surface-2 border-b border-r border-border text-[11px] font-semibold text-fg-3 uppercase tracking-wider"
                                         style="position: sticky; left: 0; z-index: 1;">
                                        {{ $row['label'] }}
                                        @if($row['sublabel'])
                                            <small class="block normal-case font-normal text-[10.5px] text-fg-3 mt-0.5" style="letter-spacing:0">{{ $row['sublabel'] }}</small>
                                        @endif
                                    </div>

                                    {{-- subject cell — sticky-left (после params-col 220px) --}}
                                    @php $s = $row['subject']; @endphp
                                    <div class="px-3 py-2 border-b bg-sky-50/40 text-[12.5px]"
                                         style="position: sticky; left: 220px; z-index: 1; border-right: 2px solid var(--sky-500); box-shadow: 1px 0 0 var(--border), 8px 0 12px -10px rgba(15,18,23,0.20);">
                                        @if($s['status'] === 'req')
                                            <span class="inline-block px-2 py-0.5 rounded bg-sky-50 text-sky-700 font-medium {{ $s['mono'] ? 'mono' : '' }}">{{ $s['value'] }}</span>
                                        @elseif($s['status'] === 'empty')
                                            <span class="text-fg-3 italic">{{ $s['value'] }}</span>
                                        @else
                                            <span class="text-fg-1 {{ $s['mono'] ? 'mono' : '' }}">{{ $s['value'] }}</span>
                                        @endif
                                        @if(! empty($s['sub']))
                                            <small class="block text-[10.5px] text-fg-3 mt-0.5 italic">{{ $s['sub'] }}</small>
                                        @endif
                                    </div>

                                    {{-- candidate cells --}}
                                    @foreach($row['cells'] as $cell)
                                        <div class="px-3 py-2 border-b border-r border-border bg-surface text-[12.5px] relative">
                                            @php
                                                $classes = match ($cell['status']) {
                                                    'match' => $showHighlight ? 'text-fg-1' : 'text-fg-1',
                                                    'diff' => 'text-amber-700',
                                                    'bad' => 'text-red-700',
                                                    'empty' => 'text-fg-3 italic font-normal',
                                                    default => 'text-fg-1',
                                                };
                                                $monoClass = ! empty($cell['mono']) ? ' mono' : '';
                                                $boldClass = ! empty($cell['bold']) ? ' font-semibold' : '';
                                                $smallClass = ! empty($cell['small']) ? ' text-[11px]' : '';
                                            @endphp
                                            @if($cell['status'] === 'match' && $showHighlight)
                                                <span class="absolute left-1 top-2 text-emerald-700 font-bold text-[11px]">✓</span>
                                            @endif
                                            <span class="{{ $classes }}{{ $monoClass }}{{ $boldClass }}{{ $smallClass }} {{ $cell['status'] === 'match' && $showHighlight ? 'ml-3' : '' }}">{{ $cell['value'] }}</span>
                                            @if(! empty($cell['sub']))
                                                <small class="block text-[10.5px] text-fg-3 mt-0.5 italic">{{ $cell['sub'] }}</small>
                                            @endif
                                        </div>
                                    @endforeach
                                @endforeach
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
