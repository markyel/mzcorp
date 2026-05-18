{{-- Foundation §6.2 + дизайн 04b-request-positions.html (комбо-режим):
     По умолчанию позиция = compact-row (header only). Менеджер жмёт
     иконку ❓/▾ → раскрывается полная карточка со slot-grid,
     quick-chips, free-text textarea, enrichment плитой и history.
     Сматчённые с каталогом позиции — slots/chips скрыты даже в
     expanded (уточнять нечего); textarea и history остаются.

     Ожидаемые переменные:
       $item — RequestItem с eager-loaded brand, kbCategory, catalogItem,
               imageAttachment, clarificationQuestions.batch
       $slots — array от PositionSlotResolver::resolve($item)
       $isImageAttachment — closure для проверки картинки
       $canEditItems — bool
       $items — Collection всех позиций (для merge sub-menu в dropdown)
       $expanded — bool, true = раскрыть всё содержимое карточки
--}}
@php
    $isExpanded = (bool) ($expanded ?? false);
    $qaStatus = $item->quality_assessment_status;
    $qaConfig = match ($qaStatus) {
        'sufficient' => ['chip-ok',     'данных достаточно'],
        'insufficient' => ['chip-warn', 'данных мало'],
        'not_covered' => ['chip-neutral', 'нет правил'],
        'assessment_failed' => ['chip-danger', 'ошибка KB'],
        'internal_catalog_pending' => ['chip-info', 'внутренний SKU · ждёт каталог'],
        'internal_catalog_not_found' => ['chip-danger', 'нет в каталоге'],
        default => null,
    };
    $itemImg = $item->imageAttachment;
    $itemImgIsImage = $itemImg && $isImageAttachment($itemImg);
    $ci = $item->catalogItem;
    $price = $ci?->price;
    $stock = $ci?->stock_available;
    $qty = (float) ($item->parsed_qty ?? 0);
    $total = ($price !== null && $qty > 0) ? ((float) $price * $qty) : null;

    // M-SKU для линка на mylift.ru. Логика parity с _item-row.blade.php:
    // (1) $ci найден → используем $ci->sku (authoritative MyLift SKU);
    // (2) $ci нет, но в parsed_article есть M\d{4,} токен → берём его
    //     (internal_catalog_pending кейс: SKU есть, каталог ещё не догружен).
    $mylinkSku = null;
    if ($ci) {
        $mylinkSku = $ci->sku;
    } elseif (preg_match('/(?<![\p{L}\p{N}_])(M\d{4,})(?![\p{L}\p{N}_])/u', (string) $item->parsed_article, $mm)) {
        $mylinkSku = $mm[1];
    }

    // Defensive: legacy "null"/"—" из старых LLM-ответов — не считаем ответом.
    $_clarHasAnswer = function ($q) {
        $a = trim((string) $q->answer);
        if (in_array(mb_strtolower($a), ['null', 'none', '—', '-', 'n/a'], true)) {
            return false;
        }
        return $a !== '';
    };
    $hasPendingClarification = $item->clarificationQuestions->isNotEmpty()
        && $item->clarificationQuestions->contains(fn ($q) => ! $_clarHasAnswer($q)
            && in_array($q->batch?->status, ['sent', 'answered'], true));
    $hasJustAnswered = $item->clarificationQuestions->isNotEmpty()
        && $item->clarificationQuestions->contains($_clarHasAnswer);
    $clarQAnswered = $item->clarificationQuestions->filter($_clarHasAnswer)->count();
    $clarQTotal = $item->clarificationQuestions->count();

    $pendingSuggCount = is_array($item->quality_assessment_payload['enrichment_suggestions'] ?? null)
        ? count(array_filter(
            $item->quality_assessment_payload['enrichment_suggestions'],
            fn ($s) => is_array($s) && ($s['status'] ?? 'pending') === 'pending',
        )) : 0;

    // Tone: amber если ждём ответ, emerald если есть свежий ответ,
    // sky для просто раскрытой карточки, neutral в compact.
    $cardTone = $hasPendingClarification ? 'border-amber-300'
        : ($hasJustAnswered ? 'border-emerald-300'
        : ($isExpanded ? 'border-sky-300' : 'border-border'));
    $cardBg = $isExpanded
        ? ($hasPendingClarification ? 'bg-gradient-to-b from-amber-50/40 to-transparent'
            : ($hasJustAnswered ? 'bg-gradient-to-b from-emerald-50/40 to-transparent'
            : 'bg-gradient-to-b from-sky-50/30 to-transparent'))
        : 'bg-surface';

    $isCatalogBound = (bool) $item->catalog_item_id;

    $itemPreviewUrl = $itemImgIsImage ? route('attachments.preview', $itemImg) : null;
    $itemDownloadUrl = $itemImgIsImage ? route('attachments.download', $itemImg) : null;
@endphp

<div wire:key="position-card-{{ $item->id }}"
     class="border {{ $cardTone }} {{ $cardBg }} rounded-md mb-1.5 {{ $item->is_active ? '' : 'opacity-50' }}">

    {{-- HEADER --}}
    <div class="grid items-center px-[12px] {{ $isExpanded ? 'py-2 border-b border-border-subtle' : 'py-1' }} gap-2.5 min-h-[44px]"
         style="grid-template-columns: 28px 44px minmax(0,1fr) 130px 80px 90px 80px 90px 28px 24px">
        {{-- Position number --}}
        <div class="text-fg-3 font-semibold text-[13px] text-right mono">{{ $item->position }}</div>

        {{-- Image. Если передан galleryItems+galleryIndex (Positions-таб) —
             dispatch'им items+index, чтобы лайтбокс показал стрелки и
             поддержку клавиш ← / →. Иначе — legacy single-image. --}}
        @if($itemImgIsImage)
            <button type="button"
                    @if(($galleryIndex ?? null) !== null && ! empty($galleryItems ?? null))
                        x-on:click="$dispatch('open-image', { items: @js($galleryItems), index: {{ $galleryIndex }} })"
                    @else
                        x-on:click="$dispatch('open-image', { src: @js($itemPreviewUrl), name: @js($itemImg->filename), dl: @js($itemDownloadUrl) })"
                    @endif
                    class="w-10 h-10 border border-border rounded-[6px] overflow-hidden bg-app block shrink-0"
                    title="{{ $itemImg->filename }} — открыть">
                <img src="{{ $itemPreviewUrl }}"
                     alt="{{ $itemImg->filename }}"
                     loading="lazy"
                     class="w-10 h-10 object-cover block">
            </button>
        @else
            <div class="w-10 h-10 border border-border rounded-[6px] bg-app flex items-center justify-center text-[9px] text-fg-3 mono shrink-0"
                 title="Без привязки к фото">img</div>
        @endif

        {{-- Title --}}
        <div class="min-w-0">
            <div class="font-medium text-[13px] leading-tight text-fg-1 flex items-baseline gap-1.5 flex-wrap">
                <span>{{ $item->parsed_name ?: '(без названия)' }}</span>
                @if($item->brand)
                    <span class="inline-flex items-center px-1.5 rounded-sm bg-neutral-100 text-neutral-700 font-semibold text-[10.5px] uppercase tracking-wider">{{ $item->brand->name }}</span>
                @elseif($item->parsed_brand)
                    <span class="inline-flex items-center px-1.5 rounded-sm bg-neutral-100 text-neutral-700 font-semibold text-[10.5px] uppercase tracking-wider">{{ $item->parsed_brand }}</span>
                @endif
                @if($qaConfig)
                    <span class="chip {{ $qaConfig[0] }} text-[10.5px]"><span class="dot"></span>{{ $qaConfig[1] }}</span>
                @endif
            </div>
            <div class="text-[11.5px] text-fg-3 flex items-center gap-2 flex-wrap">
                @if($item->kbCategory)
                    <span>{{ $item->kbCategory->name }}</span>
                @endif
                @if($item->parsed_article)
                    <span class="text-border-strong">·</span>
                    @if($mylinkSku && trim((string) $item->parsed_article) === $mylinkSku)
                        {{-- parsed_article === M-SKU → весь артикул кликабельный, дубль убран. --}}
                        <a href="https://mylift.ru/?text={{ urlencode($mylinkSku) }}&fn=find"
                           target="_blank" rel="noopener noreferrer"
                           class="mono text-sky-700 hover:text-sky-900 hover:underline"
                           title="Открыть на mylift.ru — каталог MyLift · {{ $ci?->brand_article ?: '—' }} · обн. {{ $ci?->last_imported_at?->format('d.m.Y') ?? '—' }}">арт. {{ $item->parsed_article }} ↗</a>
                    @else
                        <span class="mono text-fg-2">арт. {{ $item->parsed_article }}</span>
                        @if($mylinkSku)
                            <span class="text-border-strong">·</span>
                            <a href="https://mylift.ru/?text={{ urlencode($mylinkSku) }}&fn=find"
                               target="_blank" rel="noopener noreferrer"
                               class="mono text-sky-700 hover:text-sky-900 hover:underline"
                               title="Открыть на mylift.ru">{{ $mylinkSku }} ↗</a>
                        @endif
                    @endif
                @elseif($mylinkSku)
                    <span class="text-border-strong">·</span>
                    <a href="https://mylift.ru/?text={{ urlencode($mylinkSku) }}&fn=find"
                       target="_blank" rel="noopener noreferrer"
                       class="mono text-sky-700 hover:text-sky-900 hover:underline"
                       title="Открыть на mylift.ru">{{ $mylinkSku }} ↗</a>
                @endif
                @if($ci?->name)
                    <span class="text-border-strong">·</span>
                    <span class="text-fg-2 truncate max-w-[440px]"
                          title="Название из каталога MyLift: {{ $ci->name }}">📦 «{{ $ci->name }}»</span>
                @endif

                {{-- Phase 7: chip «📨 в КП» — сумма позиции в исходящих КП.
                     Несколько строк КП на одну позицию = split delivery. --}}
                @php
                    $quoteItems = $item->relationLoaded('outboundQuoteItems') ? $item->outboundQuoteItems : collect();
                    $quoteSum = (float) $quoteItems->sum(fn ($qi) => (float) ($qi->line_total ?? 0));
                    $quoteLines = $quoteItems->count();
                @endphp
                @if($quoteLines > 0)
                    <span class="text-border-strong">·</span>
                    <button type="button"
                            wire:click="setTab('quotes')"
                            class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded border bg-sky-50 text-sky-700 border-sky-200 hover:bg-sky-100 transition-colors text-[11px]"
                            title="Открыть таб «КП»{{ $quoteLines > 1 ? ' (split delivery: ' . $quoteLines . ' строк)' : '' }}">
                        📨 в КП:
                        <span class="mono font-semibold">{{ number_format($quoteSum, 2, '.', ' ') }} ₽</span>
                        @if($quoteLines > 1)
                            <span class="text-fg-3">×{{ $quoteLines }}</span>
                        @endif
                    </button>
                @endif
            </div>
        </div>

        {{-- Status — только enrichment-индикатор (clarification дублируется
             toggle-иконкой ❓ справа c badge'ом). --}}
        <div class="flex items-center gap-1 flex-wrap">
            @if($pendingSuggCount > 0)
                <span class="inline-flex items-center px-1 rounded-sm bg-amber-50 text-amber-800 text-[10px] font-semibold"
                      title="предложений обогащения к применению">
                    💡{{ $pendingSuggCount }}
                </span>
            @else
                <span class="text-[11px] text-fg-3">—</span>
            @endif
        </div>

        {{-- Qty --}}
        <div class="text-right mono text-[13px] text-fg-1">{{ rtrim(rtrim((string) $item->parsed_qty, '0'), '.') ?: '—' }} {{ $item->parsed_unit }}</div>

        {{-- Price --}}
        <div class="text-right mono text-[12px] {{ $price !== null ? 'text-fg-1' : 'text-fg-3' }}">
            {{ $price !== null ? number_format((float) $price, 2, '.', ' ') . ' ₽' : '—' }}
        </div>

        {{-- Stock --}}
        <div class="text-right text-[11.5px] {{ $stock === null ? 'text-fg-3' : ($stock > 0 ? 'text-emerald-700' : 'text-amber-700') }}">
            @if($stock === null) — @elseif($stock > 0) {{ $stock }} шт @else нет @endif
        </div>

        {{-- Sum --}}
        <div class="text-right mono text-[13px] font-semibold {{ $total !== null ? 'text-fg-1' : 'text-fg-3' }}">
            {{ $total !== null ? number_format($total, 2, '.', ' ') . ' ₽' : '—' }}
        </div>

        {{-- Actions menu (⋯) — реюз dropdown из _item-row.blade.php --}}
        @if(($canEditItems ?? false))
            <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                <button type="button" @click="open = !open"
                        class="text-fg-2 hover:text-fg-1 text-[16px] leading-none px-1 w-full text-center"
                        title="Действия">⋮</button>
                <div x-show="open" x-cloak x-transition.origin.top.right
                     class="absolute right-0 top-full mt-1 z-30 w-[220px] py-1 bg-surface border border-border rounded-md shadow-lg text-left text-[12.5px]">
                    @if($item->parsed_name)
                        <button type="button"
                                @click="open = false; $dispatch('open-item-edit', { itemId: {{ $item->id }} })"
                                class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">📝 Редактировать…</button>
                    @endif
                    @if($item->parsed_name || $item->parsed_article)
                        <button type="button"
                                @click="open = false; $wire.refreshItemCatalog({{ $item->id }})"
                                class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">🔄 Обновить из каталога</button>
                    @endif
                    @if($item->catalog_item_id)
                        <button type="button"
                                @click="open = false; $wire.unbindItemCatalog({{ $item->id }})"
                                class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">🔓 Отвязать от каталога</button>
                    @endif
                    @if($item->parsed_name || $item->parsed_article)
                        <button type="button"
                                @click="open = false; $dispatch('open-catalog-similar', { itemId: {{ $item->id }} })"
                                class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">🔍 Похожие из каталога…</button>
                    @endif
                    <button type="button"
                            @click="open = false; $dispatch('open-catalog-link', { itemId: {{ $item->id }} })"
                            class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">🔗 Привязать вручную…</button>
                    <button type="button"
                            @click="open = false; $dispatch('open-photo-rebind', { itemId: {{ $item->id }} })"
                            class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">📷 Сменить фото…</button>
                    @if($qaStatus === 'internal_catalog_pending')
                        <button type="button"
                                @click="open = false"
                                wire:click="markItemCatalogNotFound({{ $item->id }})"
                                wire:confirm="Подтвердить, что SKU «{{ $item->parsed_article }}» отсутствует в каталоге?"
                                class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">❌ Нет в каталоге</button>
                    @endif
                    <div class="my-1 border-t border-border-subtle"></div>
                    <button type="button"
                            @click="open = false"
                            wire:click="softDeleteItem({{ $item->id }})"
                            wire:confirm="Удалить позицию «{{ \Illuminate\Support\Str::limit($item->parsed_name ?: 'позиция #' . $item->position, 40) }}»?"
                            class="block w-full text-left px-3 py-1.5 hover:bg-red-50 text-red-700">🗑 Удалить позицию</button>
                </div>
            </div>
        @else
            <span class="text-fg-4 text-center">⋮</span>
        @endif

        {{-- TOGGLE: иконка ❓ с цветовым состоянием + WhatsApp-style
             badge с числом заданных вопросов.
             • серый ❓ — нет вопросов
             • красный — заданы, ответов нет
             • жёлтый — заданы, часть отвечена
             • зелёный — все отвечены
             Если есть pending enrichment suggestions — точка badge амбер. --}}
        @php
            $iconColor = match (true) {
                $clarQTotal === 0 => 'text-fg-3 hover:text-sky-700',
                $clarQAnswered === 0 => 'text-red-600',
                $clarQAnswered < $clarQTotal => 'text-amber-500',
                default => 'text-emerald-600',
            };
            $badgeColor = match (true) {
                $clarQAnswered === 0 => 'bg-red-600',
                $clarQAnswered < $clarQTotal => 'bg-amber-500',
                default => 'bg-emerald-600',
            };
        @endphp
        <button type="button"
                wire:click="togglePositionExpand({{ $item->id }})"
                class="relative text-center leading-none w-full {{ $iconColor }}"
                title="{{ $isExpanded ? 'Свернуть' : ($clarQTotal === 0 ? 'Раскрыть и спросить клиента' : 'Раскрыть (вопросы / ответы)') }}">
            <span class="text-[16px]">{{ $isExpanded ? '▾' : '❓' }}</span>
            @if($clarQTotal > 0 && ! $isExpanded)
                <span class="absolute -top-1 -right-0.5 inline-flex items-center justify-center min-w-[14px] h-[14px] px-1 rounded-full {{ $badgeColor }} text-white text-[9px] font-bold leading-none">
                    {{ $clarQTotal }}
                </span>
            @endif
        </button>
    </div>

    {{-- ====== EXPANDED-ONLY CONTENT ====== --}}
    @if($isExpanded)

    {{-- SLOTS GRID — 4 колонки (скрыто для catalog-bound: уточнять нечего) --}}
    @if(! $isCatalogBound)
    <div class="grid grid-cols-2 md:grid-cols-4 bg-border-subtle gap-px">
        @foreach($slots as $slot)
            @php
                $isFilled = $slot['status'] === 'filled';
                $tone = $isFilled
                    ? 'bg-surface'
                    : 'bg-gradient-to-b from-surface to-amber-50/60';
                $iconBefore = $isFilled
                    ? '<span class="text-emerald-700 font-bold text-[10px]">✓</span>'
                    : ($slot['required'] ? '<span class="text-amber-700 text-[10px]">*</span>' : '');
            @endphp
            <div class="{{ $tone }} px-3 py-2.5 flex flex-col gap-1 min-h-[56px]">
                <div class="text-fg-3 text-[10px] font-semibold uppercase tracking-wider flex items-center gap-1.5">
                    {!! $iconBefore !!}
                    <span>{{ $slot['label'] }}</span>
                </div>
                @if($isFilled)
                    <div class="text-fg-1 text-[12.5px] leading-tight tnum">{{ $slot['value'] }}</div>
                @else
                    <div class="text-amber-700 text-[12.5px] leading-tight flex items-center gap-1.5">
                        <span>—</span>
                        @if($canEditItems ?? false)
                            <button type="button"
                                    wire:click="$dispatch('clarification-add-slot-question', { itemId: {{ $item->id }}, slotKey: '{{ $slot['key'] }}', slotLabel: '{{ $slot['label'] }}', template: @js($slot['question_template']) })"
                                    class="ml-auto text-sky-700 hover:underline text-[11px]"
                                    title="Добавить в черновик уточнения">+ спросить</button>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
    @endif {{-- /SLOTS not catalog-bound --}}

    {{-- QUICK-CHIPS — универсальные шаблоны (скрыто для catalog-bound). --}}
    @if(($canEditItems ?? false) && ! $isCatalogBound)
        @php
            $quickChips = [
                ['icon' => '📷', 'label' => 'Фото шильдика',
                 'tpl' => 'Пришлите, пожалуйста, фото шильдика этой позиции.'],
                ['icon' => '🏷', 'label' => 'Марка/серия лифта',
                 'tpl' => 'Уточните, пожалуйста, марку и серию лифта, для которого нужна эта позиция.'],
                ['icon' => '📐', 'label' => 'Точные параметры',
                 'tpl' => 'Какие точные параметры нужны (диаметр / длина / мощность / напряжение)?'],
                ['icon' => '🔢', 'label' => 'Количество',
                 'tpl' => 'Уточните, пожалуйста, требуемое количество.'],
            ];
            $itemNameJs = trim((string) ($item->parsed_name ?: 'позиция #' . $item->position));
        @endphp
        <div class="px-[18px] py-2 bg-surface border-t border-border-subtle flex items-center gap-1.5 flex-wrap">
            <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold mr-1">Спросить:</span>
            @foreach($quickChips as $chip)
                <button type="button"
                        wire:click="$dispatch('clarification-add-slot-question', { itemId: {{ $item->id }}, slotKey: 'quick:{{ $loop->index }}', slotLabel: @js($chip['label']), template: @js($chip['tpl']), itemName: @js($itemNameJs) })"
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-sky-50 hover:bg-sky-100 text-sky-800 border border-sky-200 text-[11px] transition-colors"
                        title="{{ $chip['tpl'] }}">
                    <span>{{ $chip['icon'] }}</span>
                    <span>{{ $chip['label'] }}</span>
                </button>
            @endforeach
        </div>
    @endif

    {{-- FREE-TEXT QUESTION: произвольный вопрос по этой позиции.
         Alpine state-only: набираем текст, по «✓ Спросить» вызываем
         Detail::addFreeTextQuestion который dispatch'ит общий
         clarification-add-slot-question event. --}}
    @if(($canEditItems ?? false))
        <div x-data="{ q: '' }"
             class="px-[12px] py-2 bg-surface border-t border-border-subtle flex items-start gap-2">
            <div class="flex-1">
                <textarea x-model="q"
                          rows="2"
                          maxlength="800"
                          placeholder="Ваш вопрос по этой позиции (например: «уточните напряжение катушки»)"
                          class="w-full px-2.5 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-[var(--sky-500)] resize-y"></textarea>
            </div>
            <button type="button"
                    x-bind:disabled="q.trim() === ''"
                    x-on:click="$wire.addFreeTextQuestion({{ $item->id }}, q); q = ''"
                    class="btn btn-sm btn-primary shrink-0"
                    title="Добавить вопрос в черновик уточнений">
                ✓ Спросить
            </button>
        </div>
    @endif

    {{-- Phase E: Enrichment suggestions УБРАНЫ из карточки —
         теперь рендерятся одним блоком «Предложенные уточнения»
         над списком позиций (detail.blade.php). --}}

    {{-- History удалена из карточки — теперь общий блок «История
         уточнений» рендерится один раз под списком позиций
         (detail.blade.php). --}}

    @endif {{-- /EXPANDED-ONLY CONTENT --}}
</div>
