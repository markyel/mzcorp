{{-- Partial: таблица результатов каталога для text / similar вкладок
     ItemCatalogLinkDialog. Ожидает:
       $rows — Collection<array{catalog: CatalogItem, similarity: ?float}>
       $selectedId — ?int (id выбранной строки для подсветки) --}}
<table class="w-full text-[12px]" style="table-layout: auto;">
    <colgroup>
        <col style="width: 56px">
        <col style="width: 90px">
        <col style="width: 160px">
        <col>
        <col style="width: 90px">
        <col style="width: 80px">
        @if($rows->first()['similarity'] ?? null !== null)
            <col style="width: 80px">
        @endif
    </colgroup>
    <thead class="bg-surface-2 text-fg-3 uppercase tracking-wider text-[10.5px] sticky top-0">
        <tr>
            <th class="px-2 py-1.5"></th>
            <th class="px-2 py-1.5 text-left">SKU</th>
            <th class="px-2 py-1.5 text-left">Бренд / артикул</th>
            <th class="px-2 py-1.5 text-left">Название</th>
            <th class="px-2 py-1.5 text-right">Цена</th>
            <th class="px-2 py-1.5 text-right">Наличие</th>
            @if($rows->first()['similarity'] ?? null !== null)
                <th class="px-2 py-1.5 text-right">Похожесть</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
            @php
                $cat = $row['catalog'];
                $sim = $row['similarity'] ?? null;
            @endphp
            <tr wire:key="cat-{{ $cat->id }}"
                wire:click="selectCatalog({{ $cat->id }})"
                class="cursor-pointer border-b border-border-subtle last:border-b-0 {{ $selectedId === $cat->id ? 'bg-sky-50' : 'hover:bg-surface-2' }} {{ $cat->is_active ? '' : 'opacity-60' }}">
                {{-- Photo preview (MDB-поле «Фото» → photo_url).
                     Миниатюра 40×40, lazy-load + onerror fallback.
                     Hover ≥1 сек → большой предпросмотр справа (~288×288),
                     pointer-events-none на превью чтобы мышь не зацикливалась.
                     Click открывает оригинал в новой вкладке. --}}
                <td class="px-2 py-1.5 align-top">
                    @if($cat->photo_url)
                        {{-- Position: fixed (а не absolute) — превью якорится
                             к viewport, минует overflow:hidden модального окна.
                             Координаты считаем по getBoundingClientRect миниатюры
                             на mouseenter; если справа места нет — флип влево;
                             top клампится в [8 .. viewport-408]. --}}
                        <div x-data="{
                                show: false, t: null, top: 0, left: 0,
                                place(el) {
                                    const r = el.getBoundingClientRect();
                                    const W = 400, H = 400, gap = 8;
                                    // Default: флип ВЛЕВО от миниатюры — превью
                                    // приземляется в whitespace модала, не
                                    // закрывая таблицу справа. Если слева мало
                                    // места (миниатюра у левого края viewport) —
                                    // выпадаем вправо.
                                    this.left = (r.left - gap - W >= 8)
                                        ? r.left - gap - W
                                        : Math.min(window.innerWidth - W - 8, r.right + gap);
                                    this.top = Math.min(
                                        window.innerHeight - H - 8,
                                        Math.max(8, r.top)
                                    );
                                }
                             }"
                             x-on:mouseenter="place($refs.thumb); t = setTimeout(() => show = true, 1000)"
                             x-on:mouseleave="clearTimeout(t); show = false">
                            <a x-ref="thumb"
                               href="{{ $cat->photo_url }}" target="_blank" rel="noopener noreferrer"
                               x-on:click.stop
                               class="block w-10 h-10 rounded overflow-hidden bg-surface-2 border border-border-subtle"
                               title="Открыть фото в новой вкладке">
                                <img src="{{ $cat->photo_url }}"
                                     alt=""
                                     loading="lazy"
                                     referrerpolicy="no-referrer"
                                     class="w-full h-full object-cover"
                                     onerror="this.style.display='none'; this.parentElement.classList.add('flex','items-center','justify-center'); this.parentElement.innerHTML += '<span class=\'text-fg-3 text-[9px]\'>нет</span>';">
                            </a>
                            <div x-show="show" x-cloak x-transition.opacity
                                 :style="`position: fixed; left: ${left}px; top: ${top}px; width: 400px; height: 400px; z-index: 9999; pointer-events: none;`"
                                 class="rounded-lg shadow-xl border border-border-subtle bg-white p-1">
                                <img src="{{ $cat->photo_url }}"
                                     alt=""
                                     referrerpolicy="no-referrer"
                                     style="width: 100%; height: 100%; object-fit: cover; border-radius: 4px;">
                            </div>
                        </div>
                    @else
                        <div class="w-10 h-10 rounded bg-surface-2 border border-border-subtle flex items-center justify-center text-fg-3 text-[9px]">нет</div>
                    @endif
                </td>
                <td class="px-2 py-1.5 mono text-fg-1 align-top whitespace-nowrap">
                    <div class="flex items-center gap-1">
                        <span>{{ $cat->sku }}</span>
                        <a href="https://mylift.ru/?text={{ urlencode($cat->sku) }}&fn=find"
                           target="_blank" rel="noopener noreferrer"
                           x-on:click.stop
                           class="text-sky-700 hover:text-sky-900 text-[11px]"
                           title="Открыть на mylift.ru">↗</a>
                    </div>
                </td>
                <td class="px-2 py-1.5 align-top">
                    <div class="text-fg-1 break-words">{{ $cat->brand ?: '—' }}</div>
                    @if($cat->brand_article)
                        <div class="mono text-fg-3 text-[11px] break-all">{{ $cat->brand_article }}</div>
                    @endif
                </td>
                <td class="px-2 py-1.5 text-fg-1 align-top leading-snug break-words">{{ $cat->name }}</td>
                <td class="px-2 py-1.5 mono text-right text-fg-1 align-top whitespace-nowrap">
                    {{ $cat->price !== null ? number_format((float) $cat->price, 2, '.', ' ') . ' ₽' : '—' }}
                </td>
                <td class="px-2 py-1.5 text-right align-top whitespace-nowrap">
                    @if($cat->stock_available === null)
                        <span class="text-fg-3">—</span>
                    @elseif($cat->stock_available > 0)
                        <span class="text-emerald-700">{{ $cat->stock_available }} шт</span>
                    @else
                        <span class="text-amber-700">нет</span>
                    @endif
                    @if(! $cat->is_active)
                        <span class="ml-1 text-[10px] text-fg-3 uppercase">архив</span>
                    @endif
                </td>
                @if($sim !== null)
                    <td class="px-2 py-1.5 mono text-right align-top whitespace-nowrap">
                        @php
                            $pct = (int) round($sim * 100);
                            $tone = $sim >= 0.85
                                ? 'text-emerald-700'
                                : ($sim >= 0.75 ? 'text-amber-700' : 'text-fg-3');
                        @endphp
                        <span class="{{ $tone }} font-semibold">{{ $pct }}%</span>
                    </td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>
