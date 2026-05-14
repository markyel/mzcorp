{{-- Foundation §6.2 + дизайн 04b-request-positions.html:
     Slot-based card одной позиции с заголовком, slot grid, историей
     уточнений и (опц.) панелью «Спросить ещё».

     Ожидаемые переменные:
       $item — RequestItem с eager-loaded brand, kbCategory, catalogItem,
               imageAttachment, clarificationQuestions.batch
       $slots — array от PositionSlotResolver::resolve($item)
       $progress — array от PositionSlotResolver::progress($slots)
       $isImageAttachment — closure для проверки картинки
       $canEditItems — bool
       $items — Collection всех позиций (для merge sub-menu в dropdown)
--}}
@php
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

    // Card border tone: amber если данных мало (нужны уточнения),
    // emerald если только что обогащено (есть applied suggestions),
    // дефолт-border иначе.
    $hasPendingClarification = $item->clarificationQuestions->isNotEmpty()
        && $item->clarificationQuestions->contains(fn ($q) => trim((string) $q->answer) === '');
    $hasJustAnswered = $item->clarificationQuestions->isNotEmpty()
        && $item->clarificationQuestions->contains(fn ($q) => trim((string) $q->answer) !== '');
    $cardTone = $hasPendingClarification ? 'border-amber-300 bg-gradient-to-b from-amber-50/40 to-transparent'
        : ($hasJustAnswered ? 'border-emerald-300 bg-gradient-to-b from-emerald-50/40 to-transparent'
        : 'border-border');

    // Image url shortcuts.
    $itemPreviewUrl = $itemImgIsImage ? route('attachments.preview', $itemImg) : null;
    $itemDownloadUrl = $itemImgIsImage ? route('attachments.download', $itemImg) : null;
@endphp

<div wire:key="position-card-{{ $item->id }}"
     class="bg-surface border {{ $cardTone }} rounded-md overflow-hidden mb-3 {{ $item->is_active ? '' : 'opacity-50' }}">

    {{-- HEADER --}}
    <div class="grid items-center px-[18px] py-3 border-b border-border-subtle gap-3"
         style="grid-template-columns: 28px 56px 1fr 130px 80px 90px 90px 90px 32px">
        {{-- Position number --}}
        <div class="text-fg-3 font-semibold text-[15px] text-right mono">{{ $item->position }}</div>

        {{-- Image --}}
        @if($itemImgIsImage)
            <button type="button"
                    x-on:click="$dispatch('open-image', { src: @js($itemPreviewUrl), name: @js($itemImg->filename), dl: @js($itemDownloadUrl) })"
                    class="w-[52px] h-[52px] border border-border rounded-[6px] overflow-hidden bg-app block"
                    title="{{ $itemImg->filename }} — открыть">
                <img src="{{ $itemPreviewUrl }}"
                     alt="{{ $itemImg->filename }}"
                     loading="lazy"
                     class="w-[52px] h-[52px] object-cover block">
            </button>
        @else
            <div class="w-[52px] h-[52px] border border-border rounded-[6px] bg-app flex items-center justify-center text-[9px] text-fg-3 mono"
                 title="Без привязки к фото">img</div>
        @endif

        {{-- Title --}}
        <div class="min-w-0">
            <div class="font-medium text-[14px] leading-tight text-fg-1 flex items-baseline gap-2 flex-wrap mb-1">
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
                    <span class="mono text-fg-2">арт. {{ $item->parsed_article }}</span>
                @endif
                @if($ci?->sku)
                    <span class="text-border-strong">·</span>
                    <span class="mono text-fg-3" title="каталожный SKU">{{ $ci->sku }}</span>
                @endif
            </div>
        </div>

        {{-- Status --}}
        <div>
            @if($hasPendingClarification)
                <span class="chip chip-warn text-[10.5px]"><span class="dot"></span>ждём ответ</span>
            @elseif($hasJustAnswered)
                <span class="chip chip-ok text-[10.5px]"><span class="dot"></span>уточнено</span>
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
    </div>

    {{-- SLOTS GRID — 4 колонки --}}
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

    {{-- ENRICHMENT SUGGESTIONS (Foundation §6.2 Phase C) --}}
    @php
        $allSuggestions = is_array($item->quality_assessment_payload['enrichment_suggestions'] ?? null)
            ? $item->quality_assessment_payload['enrichment_suggestions']
            : [];
        $pendingSuggestions = array_filter(
            $allSuggestions,
            fn ($s) => is_array($s) && ($s['status'] ?? 'pending') === 'pending',
        );
        $fieldLabels = [
            'parsed_article' => 'Артикул',
            'parsed_brand' => 'Бренд',
            'parsed_qty' => 'Кол-во',
        ];
    @endphp
    @if(! empty($pendingSuggestions) && ($canEditItems ?? false))
        <div class="px-[18px] py-2.5 bg-amber-50/50 border-t border-amber-200 space-y-1.5">
            @foreach($pendingSuggestions as $sugg)
                @php
                    $sid = (string) ($sugg['id'] ?? '');
                    $field = (string) ($sugg['field'] ?? '');
                    $val = (string) ($sugg['value'] ?? '');
                    $quote = (string) ($sugg['source_quote'] ?? '');
                    $confPct = (int) round(((float) ($sugg['confidence'] ?? 0)) * 100);
                @endphp
                <div class="rounded-md border border-amber-300 bg-amber-50 p-2 flex items-start gap-2.5"
                     wire:key="ensugg-card-{{ $item->id }}-{{ $sid }}">
                    <span class="text-[14px] leading-none mt-0.5">💡</span>
                    <div class="flex-1 min-w-0 text-[12px]">
                        <div class="text-amber-900">
                            <span class="font-semibold">Клиент прислал:</span>
                            <span class="text-fg-3">{{ $fieldLabels[$field] ?? $field }} →</span>
                            <span class="mono font-semibold text-fg-1">{{ $val }}</span>
                            <span class="text-amber-700 text-[10.5px]">· {{ $confPct }}%</span>
                        </div>
                        @if($quote !== '')
                            <div class="text-fg-2 text-[11px] mt-0.5 italic pl-2 border-l border-amber-400">
                                «{{ \Illuminate\Support\Str::limit($quote, 200) }}»
                            </div>
                        @endif
                    </div>
                    <div class="shrink-0 flex items-center gap-1.5">
                        <button type="button"
                                wire:click="applyEnrichmentSuggestion({{ $item->id }}, '{{ $sid }}')"
                                class="btn btn-sm btn-primary"
                                wire:confirm="Применить значение «{{ $val }}» к полю «{{ $fieldLabels[$field] ?? $field }}»?">
                            ✓ Применить
                        </button>
                        <button type="button"
                                wire:click="dismissEnrichmentSuggestion({{ $item->id }}, '{{ $sid }}')"
                                class="btn btn-sm"
                                title="Отклонить — клиент не имел в виду эту позицию">✕</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- HISTORY of clarifications per position (если есть) --}}
    @if($item->clarificationQuestions->isNotEmpty())
        <div class="px-[18px] py-3 bg-surface-2 border-t border-border-subtle text-[12.5px]">
            <div class="flex items-center gap-2 mb-2 text-fg-3 text-[10.5px] font-semibold uppercase tracking-wider">
                <span>История уточнений</span>
                <span class="inline-flex items-center px-1.5 rounded-full bg-surface border border-border text-fg-2 normal-case tracking-normal text-[10.5px] font-semibold">
                    {{ $item->clarificationQuestions->count() }}
                </span>
            </div>
            <div class="space-y-1.5">
                @foreach($item->clarificationQuestions as $cq)
                    @php
                        $cqBatch = $cq->batch;
                        $isSent = $cqBatch && in_array($cqBatch->status, ['sent', 'answered'], true);
                        $hasAnswer = trim((string) $cq->answer) !== '';
                        $stateClass = $hasAnswer ? 'bg-emerald-600' : ($isSent ? 'bg-amber-600' : 'bg-neutral-400');
                    @endphp
                    <div class="grid items-start gap-2"
                         style="grid-template-columns: 10px 1fr 110px">
                        <span class="w-2 h-2 rounded-full {{ $stateClass }} mt-1.5"></span>
                        <div class="min-w-0">
                            <div class="text-fg-1 leading-snug">
                                <b class="font-medium">{{ $cqBatch?->createdBy?->name ?? 'Менеджер' }}</b>
                                {{ $hasAnswer ? 'спросил:' : 'спросил:' }}
                                {{ $cq->question }}
                            </div>
                            @if($hasAnswer)
                                <div class="mt-1 px-2 py-1 rounded-sm bg-emerald-50 border-l-2 border-emerald-400 text-fg-1">
                                    <span class="text-emerald-700 font-semibold text-[10px] uppercase tracking-wider">Клиент:</span>
                                    <span class="ml-1">{{ $cq->answer }}</span>
                                </div>
                            @endif
                        </div>
                        <div class="text-right text-fg-3 mono text-[10.5px] leading-tight">
                            {{ $cqBatch?->sent_at?->format('d.m H:i') ?: '—' }}
                            <div>{{ $hasAnswer ? '✓' : '⏳' }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
