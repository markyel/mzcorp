{{-- Partial: таблица результатов каталога для text / similar вкладок
     ItemCatalogLinkDialog. Ожидает:
       $rows — Collection<array{catalog: CatalogItem, similarity: ?float}>
       $selectedId — ?int (id выбранной строки для подсветки) --}}
{{-- Hover-preview: ОДИН элемент на всю таблицу. Каждая миниатюра
     при mouseenter вызывает openPreview(el, url) — стейт переиспользуется,
     поэтому стекаться нечему. Раньше у каждой строки был свой x-data scope
     и show=false в mouseleave callback не всегда триггерил обновление. --}}
<div x-data="{
        show: false, url: '', t: null, top: 0, left: 0,
        openPreview(el, photoUrl) {
            clearTimeout(this.t);
            const r = el.getBoundingClientRect();
            const W = 400, H = 400, gap = 8;
            this.left = (r.left - gap - W >= 8)
                ? r.left - gap - W
                : Math.min(window.innerWidth - W - 8, r.right + gap);
            this.top = Math.min(window.innerHeight - H - 8, Math.max(8, r.top));
            this.url = photoUrl;
            this.t = setTimeout(() => { this.show = true; }, 1000);
        },
        closePreview() {
            clearTimeout(this.t);
            this.show = false;
        }
     }">
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
                        <a href="{{ $cat->photo_url }}" target="_blank" rel="noopener noreferrer"
                           x-on:click.stop
                           x-on:mouseenter="openPreview($el, '{{ addslashes($cat->photo_url) }}')"
                           x-on:mouseleave="closePreview()"
                           class="block w-10 h-10 rounded overflow-hidden bg-surface-2 border border-border-subtle"
                           title="Открыть фото в новой вкладке">
                            <img src="{{ $cat->photo_url }}"
                                 alt=""
                                 loading="lazy"
                                 referrerpolicy="no-referrer"
                                 class="w-full h-full object-cover"
                                 onerror="this.style.display='none'; this.parentElement.classList.add('flex','items-center','justify-center'); this.parentElement.innerHTML += '<span class=\'text-fg-3 text-[9px]\'>нет</span>';">
                        </a>
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
                            $method = $row['method'] ?? null;
                            $trgmScore = $row['trgm_score'] ?? null;
                            $vecScore = $row['vector_score'] ?? null;
                            $methodIcon = match ($method) {
                                'both' => '🔀',
                                'trgm' => '🔤',
                                'vector' => '✨',
                                default => null,
                            };
                            $methodTitle = match ($method) {
                                'both' => 'Найдено и текстом, и семантикой'
                                    . ($trgmScore !== null ? ' (trgm '.round($trgmScore*100).'%' : '')
                                    . ($vecScore !== null ? ', vec '.round($vecScore*100).'%)' : ($trgmScore !== null ? ')' : '')),
                                'trgm' => 'Текстовое совпадение (pg_trgm)',
                                'vector' => 'Семантическая похожесть (vector)',
                                default => '',
                            };
                        @endphp
                        <div class="flex items-center justify-end gap-1">
                            @if($methodIcon)
                                <span class="text-[11px]" title="{{ $methodTitle }}">{{ $methodIcon }}</span>
                            @endif
                            <span class="{{ $tone }} font-semibold">{{ $pct }}%</span>
                        </div>
                    </td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>

{{-- Единственное hover-превью на всю таблицу: координаты и src свапаются
     openPreview/closePreview из миниатюр. Никакого стекирования. --}}
<div x-show="show" x-cloak x-transition.opacity
     :style="`position: fixed; left: ${left}px; top: ${top}px; width: 400px; height: 400px; z-index: 9999; pointer-events: none;`"
     class="rounded-lg shadow-xl border border-border-subtle bg-white p-1">
    <img :src="url" alt=""
         referrerpolicy="no-referrer"
         style="width: 100%; height: 100%; object-fit: cover; border-radius: 4px;">
</div>
</div>{{-- /x-data hover-preview wrapper --}}
