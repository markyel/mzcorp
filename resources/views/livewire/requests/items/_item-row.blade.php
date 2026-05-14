{{-- Общая строка позиции для table-tab Items.
     Используется в двух местах:
       1) основной список позиций текущей заявки (с actions: лупа + ⋮);
       2) sticky-positions блок (read-only, лупа = ссылка в чужую заявку).

     Ожидаемые переменные:
       $item — RequestItem (eager-loaded brand / kbCategory / catalogItem / imageAttachment)
       $isImageAttachment — closure для проверки картинки
       $readonly — bool (true → без menus, лупа → ссылка)
       $canEditItems — bool (только для main list)
       $items — Collection всех позиций текущей заявки (для merge sub-menu) --}}
@php
    $qaStatus = $item->quality_assessment_status;
    $qaConfig = match ($qaStatus) {
        'sufficient' => ['chip-ok',     'данных достаточно'],
        'insufficient' => ['chip-attn', 'данных мало'],
        'not_covered' => ['chip-neutral', 'нет правил'],
        'assessment_failed' => ['chip-over', 'ошибка KB'],
        'internal_catalog_pending' => ['chip-info', 'внутренний SKU · ждёт каталог'],
        'internal_catalog_not_found' => ['chip-danger', 'нет в каталоге'],
        default => null,
    };
    $extracted = is_array($item->quality_assessment_payload['extracted_parameters'] ?? null)
        ? $item->quality_assessment_payload['extracted_parameters']
        : [];
    $itemImg = $item->imageAttachment;
    $itemImgIsImage = $itemImg && $isImageAttachment($itemImg);
    $ci = $item->catalogItem;
    $price = $ci?->price;
    $stock = $ci?->stock_available;
    $qty = (float) ($item->parsed_qty ?? 0);
    $total = ($price !== null && $qty > 0) ? ((float) $price * $qty) : null;
    $stockTone = $stock === null ? 'text-fg-3' : ($stock > 0 ? 'text-emerald-700' : 'text-amber-700');
    $mylinkSku = null;
    if ($ci) {
        $mylinkSku = $ci->sku;
    } elseif (preg_match('/(?<![\p{L}\p{N}_])(M\d{4,})(?![\p{L}\p{N}_])/u', (string) $item->parsed_article, $mm)) {
        $mylinkSku = $mm[1];
    }
@endphp
<div wire:key="ri-{{ $item->id }}"
     class="grid items-center px-[18px] gap-2.5 py-2.5 border-b border-border-subtle text-[12.5px] {{ $item->is_active ? '' : 'opacity-50 bg-surface-2' }}"
     style="grid-template-columns: 24px 36px 1fr 110px 90px 100px 110px 56px">
    <span class="mono text-[12px] text-fg-3 text-right">{{ $item->position }}</span>

    @if($itemImgIsImage)
        @php
            $itemPreviewUrl = route('attachments.preview', $itemImg);
            $itemDownloadUrl = route('attachments.download', $itemImg);
        @endphp
        <button type="button"
                x-on:click="$dispatch('open-image', { src: @js($itemPreviewUrl), name: @js($itemImg->filename), dl: @js($itemDownloadUrl) })"
                class="w-8 h-8 border border-border rounded-sm overflow-hidden bg-app block shrink-0"
                title="{{ $itemImg->filename }} — открыть">
            <img src="{{ $itemPreviewUrl }}"
                 alt="{{ $itemImg->filename }}"
                 loading="lazy"
                 class="w-8 h-8 object-cover block">
        </button>
    @else
        <span class="w-8 h-8 border border-border rounded-sm bg-app flex items-center justify-center text-[9px] text-fg-3"
              title="Без привязки к фото">img</span>
    @endif

    <div class="min-w-0">
        <div class="font-medium text-fg-1 leading-snug">{{ $item->parsed_name ?: '(без названия)' }}</div>
        @if($ci && trim((string) $ci->name) !== '' && mb_strtolower(trim((string) $ci->name)) !== mb_strtolower(trim((string) $item->parsed_name)))
            <div class="text-[11.5px] text-fg-3 mt-0.5 leading-snug" title="Название из каталога MyLift (SKU {{ $ci->sku }})">
                <span class="text-fg-3">↳</span>
                <span class="text-fg-2">{{ $ci->name }}</span>
            </div>
        @endif
        <div class="text-[11.5px] text-fg-3 mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
            @if($item->brand)
                <span class="inline-flex items-center px-1.5 rounded-sm bg-emerald-50 text-emerald-800 font-semibold text-[10.5px]"
                      title="резолв KB по бренду">{{ $item->brand->name }}</span>
            @elseif($item->parsed_brand)
                <span title="бренд не резолвлен">{{ $item->parsed_brand }}</span>
            @endif
            @if($item->kbCategory)
                <span class="inline-flex items-center px-1.5 rounded-sm bg-sky-50 text-sky-800 font-medium text-[10.5px]"
                      title="{{ $item->kbCategory->slug }}">{{ $item->kbCategory->name }}</span>
            @endif
            @if($qaConfig)
                <span class="chip {{ $qaConfig[0] }} text-[10.5px]"
                      title="quality_assessment_status: {{ $qaStatus }}">
                    <span class="dot"></span>{{ $qaConfig[1] }}
                </span>
            @endif
            @if($item->parsed_article)<span class="mono text-fg-2">{{ $item->parsed_article }}</span>@endif
            @if($ci)
                <span class="inline-flex items-center px-1.5 rounded-sm bg-violet-50 text-violet-800 font-medium text-[10.5px]"
                      title="каталог MyLift: {{ $ci->sku }} · {{ $ci->brand_article ?: '—' }} · обновлено {{ $ci->last_imported_at?->format('d.m.Y') ?? '—' }}">
                    в каталоге · {{ $ci->sku }}
                </span>
            @endif
            @if($mylinkSku)
                <a href="https://mylift.ru/?text={{ urlencode($mylinkSku) }}&fn=find"
                   target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center gap-0.5 px-1.5 rounded-sm bg-sky-50 text-sky-700 hover:text-sky-900 hover:bg-sky-100 font-medium text-[10.5px]"
                   title="Открыть на mylift.ru">mylift.ru ↗</a>
            @endif
            @if($item->supplier_note)
                <span class="inline-flex items-center px-1.5 rounded-sm bg-amber-50 text-amber-700 font-medium text-[10.5px]">
                    {{ \Illuminate\Support\Str::limit($item->supplier_note, 50) }}
                </span>
            @endif
        </div>
        @if(! empty($extracted))
            <div class="text-[11px] text-fg-3 mt-1 flex flex-wrap gap-x-2 gap-y-0.5 mono">
                @foreach(array_slice($extracted, 0, 6, true) as $slug => $value)
                    <span><span class="text-fg-3">{{ $slug }}:</span> <span class="text-fg-2">{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</span></span>
                @endforeach
                @if(count($extracted) > 6)
                    <span class="text-fg-3">… +{{ count($extracted) - 6 }}</span>
                @endif
            </div>
        @endif
    </div>

    <span class="mono text-[12px] text-fg-1 text-right">{{ rtrim(rtrim((string) $item->parsed_qty, '0'), '.') ?: '—' }} {{ $item->parsed_unit }}</span>

    <span class="mono text-[12px] {{ $price !== null ? 'text-fg-1' : 'text-fg-3' }} text-right"
          title="{{ $ci ? 'из каталога, обновлено ' . ($ci->last_imported_at?->format('d.m.Y') ?? '—') : 'нет привязки к каталогу' }}">
        {{ $price !== null ? number_format((float) $price, 2, '.', ' ') . ' ₽' : '—' }}
    </span>

    <span class="text-[12px] {{ $stockTone }}"
          title="{{ $ci ? 'остаток на складе' : 'нет данных' }}">
        @if($stock === null)
            —
        @elseif($stock > 0)
            {{ $stock }} шт
        @else
            нет
        @endif
    </span>

    <span class="mono text-[12px] {{ $total !== null ? 'text-fg-1' : 'text-fg-3' }} text-right">
        {{ $total !== null ? number_format($total, 2, '.', ' ') . ' ₽' : '—' }}
    </span>

    {{-- Actions: только для main list (canEditItems=true и !readonly). --}}
    @if(! ($readonly ?? false) && ($canEditItems ?? false))
        @if(! $item->is_active)
            <button type="button"
                    wire:click="restoreItem({{ $item->id }})"
                    class="text-emerald-700 hover:text-emerald-900 text-center text-[14px]"
                    title="Восстановить позицию">↩</button>
        @else
            <div class="flex items-center justify-end gap-0.5">
                @if($item->parsed_name || $item->parsed_article)
                    <button type="button"
                            @click="$dispatch('open-catalog-similar', { itemId: {{ $item->id }} })"
                            class="text-fg-3 hover:text-fg-1 text-[13px] px-1 leading-none"
                            title="Найти похожие позиции в каталоге">🔍</button>
                @endif
                <div x-data="{ open: false }" class="relative"
                     @click.outside="open = false">
                    <button type="button"
                            @click="open = !open"
                            class="text-fg-2 hover:text-fg-1 text-[16px] leading-none px-1"
                            title="Действия">⋮</button>
                    <div x-show="open" x-cloak x-transition.origin.top.right
                         class="absolute right-0 top-full mt-1 z-30 w-[220px] py-1 bg-surface border border-border rounded-md shadow-lg text-left text-[12.5px]">
                        @if($item->parsed_name)
                            <button type="button"
                                    @click="open = false; $dispatch('open-item-edit', { itemId: {{ $item->id }} })"
                                    class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                                📝 Редактировать…
                            </button>
                        @endif
                        @if($item->parsed_name || $item->parsed_article)
                            <button type="button"
                                    @click="open = false; $wire.refreshItemCatalog({{ $item->id }})"
                                    class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                                🔄 Обновить из каталога
                            </button>
                        @endif
                        @if($item->catalog_item_id)
                            <button type="button"
                                    @click="open = false; $wire.unbindItemCatalog({{ $item->id }})"
                                    class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                                🔓 Отвязать от каталога
                            </button>
                        @endif
                        @if($item->parsed_name || $item->parsed_article)
                            <button type="button"
                                    @click="open = false; $dispatch('open-catalog-similar', { itemId: {{ $item->id }} })"
                                    class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                                🔍 Похожие из каталога…
                            </button>
                        @endif
                        <button type="button"
                                @click="open = false; $dispatch('open-catalog-link', { itemId: {{ $item->id }} })"
                                class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                            🔗 Привязать вручную…
                        </button>
                        <button type="button"
                                @click="open = false; $dispatch('open-photo-rebind', { itemId: {{ $item->id }} })"
                                class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                            📷 Сменить фото…
                        </button>
                        @if($qaStatus === 'internal_catalog_pending')
                            <button type="button"
                                    @click="open = false"
                                    wire:click="markItemCatalogNotFound({{ $item->id }})"
                                    wire:confirm="Подтвердить, что SKU «{{ $item->parsed_article }}» отсутствует в каталоге?"
                                    class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                                ❌ Нет в каталоге
                            </button>
                        @endif

                        @php $mergeTargets = ($items ?? collect())->where('id', '!=', $item->id); @endphp
                        @if($mergeTargets->isNotEmpty())
                            <div x-data="{ subOpen: false }" class="relative">
                                <button type="button"
                                        @click="subOpen = !subOpen"
                                        class="block w-full text-left px-3 py-1.5 hover:bg-surface-2 text-fg-1">
                                    🔗 Это уточнение позиции…
                                    <span class="float-right text-fg-3" x-text="subOpen ? '▾' : '▸'"></span>
                                </button>
                                <div x-show="subOpen" x-cloak
                                     class="pl-4 max-h-[200px] overflow-auto bg-surface-2 border-t border-border-subtle">
                                    @foreach($mergeTargets as $tgt)
                                        <button type="button"
                                                @click="open = false; subOpen = false"
                                                wire:click="mergeItemInto({{ $item->id }}, {{ $tgt->id }})"
                                                wire:confirm="Слить эту позицию в #{{ $tgt->position }} «{{ \Illuminate\Support\Str::limit($tgt->parsed_name ?: '—', 40) }}»? Артикул будет дописан, эта позиция удалена."
                                                class="block w-full text-left px-3 py-1.5 hover:bg-sky-50 text-fg-1 text-[11.5px]">
                                            <span class="mono text-fg-3">#{{ $tgt->position }}</span>
                                            <span class="text-fg-1">{{ \Illuminate\Support\Str::limit($tgt->parsed_name ?: '(без названия)', 50) }}</span>
                                            @if($tgt->parsed_article)
                                                <span class="mono text-fg-3 text-[10.5px]">· {{ \Illuminate\Support\Str::limit($tgt->parsed_article, 24) }}</span>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="my-1 border-t border-border-subtle"></div>
                        <button type="button"
                                @click="open = false"
                                wire:click="softDeleteItem({{ $item->id }})"
                                wire:confirm="Удалить позицию «{{ \Illuminate\Support\Str::limit($item->parsed_name ?: 'позиция #' . $item->position, 40) }}»?"
                                class="block w-full text-left px-3 py-1.5 hover:bg-red-50 text-red-700">
                            🗑 Удалить позицию
                        </button>
                    </div>
                </div>
            </div>
        @endif

    {{-- Readonly режим: одна иконка-ссылка «открыть в карточке заявки». --}}
    @elseif($readonly ?? false)
        <a href="{{ route('requests.show', $item->request_id) }}"
           class="text-sky-700 hover:text-sky-900 text-center text-[14px]"
           title="Открыть карточку заявки">↗</a>
    @else
        <span class="text-fg-3 text-center" title="Менеджер заявки">⋮</span>
    @endif
</div>
