{{-- Partial: таблица результатов standalone-поиска каталога.
     Ожидает: $rows — Collection<array{catalog: CatalogItem, similarity: float, method, code_score, trgm_score, vector_score}>.
     В отличие от requests/items/_catalog-results-table — нет
     selectCatalog/toggleCompare actions: standalone-поиск не привязан
     к заявке, единственное действие — открыть на mylift.ru. --}}
<div x-data="{
        show: false, url: '', t: null, top: 0, left: 0,
        openPreview(el, photoUrl) {
            clearTimeout(this.t);
            this.show = false;
            const r = el.getBoundingClientRect();
            const W = 400, H = 400, gap = 12;
            if (r.right + gap + W <= window.innerWidth - 8) {
                this.left = r.right + gap;
            } else if (r.left - gap - W >= 8) {
                this.left = r.left - gap - W;
            } else {
                this.left = Math.max(8, window.innerWidth - W - 8);
            }
            this.top = Math.min(window.innerHeight - H - 8, Math.max(8, r.top));
            this.url = photoUrl;
            this.t = setTimeout(() => { this.show = true; }, 700);
        },
        closePreview() {
            clearTimeout(this.t);
            this.show = false;
        }
     }">
<table class="w-full text-[12px]" style="table-layout: fixed;">
    <colgroup>
        <col style="width: 52px">
        <col style="width: 90px">
        <col style="width: 160px">
        <col>
        <col style="width: 96px">
        <col style="width: 84px">
        <col style="width: 88px">
    </colgroup>
    <thead class="bg-surface-2 text-fg-3 uppercase tracking-wider text-[10.5px] sticky top-0">
        <tr>
            <th class="px-2 py-1.5"></th>
            <th class="px-2 py-1.5 text-left">SKU</th>
            <th class="px-2 py-1.5 text-left">Бренд / артикул</th>
            <th class="px-2 py-1.5 text-left">Название</th>
            <th class="px-2 py-1.5 text-right">Цена</th>
            <th class="px-2 py-1.5 text-right">Наличие</th>
            <th class="px-2 py-1.5 text-right">Похожесть</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $row)
            @php
                $cat = $row['catalog'];
                $sim = $row['similarity'] ?? null;
                $method = $row['method'] ?? null;
                $codeScore = $row['code_score'] ?? null;
                $trgmScore = $row['trgm_score'] ?? null;
                $vecScore  = $row['vector_score'] ?? null;
                $methodIcon = match ($method) {
                    'multi' => '🔀',
                    'code' => '🎯',
                    'trgm' => '🔤',
                    'vector' => '✨',
                    default => null,
                };
                $sourceParts = [];
                if ($codeScore !== null) $sourceParts[] = 'code-token';
                if ($trgmScore !== null) $sourceParts[] = 'trgm ' . round($trgmScore * 100) . '%';
                if ($vecScore !== null)  $sourceParts[] = 'vec '  . round($vecScore  * 100) . '%';
                $methodTitle = match ($method) {
                    'multi' => 'Найдено несколькими способами: ' . implode(', ', $sourceParts),
                    'code'  => 'Точное вхождение кода (ILIKE)',
                    'trgm'  => 'Текстовое совпадение (pg_trgm)',
                    'vector'=> 'Семантическая похожесть (vector)',
                    default => '',
                };
                $tone = $sim >= 0.85 ? 'text-emerald-700' : ($sim >= 0.75 ? 'text-amber-700' : 'text-fg-3');
            @endphp
            <tr wire:key="cat-{{ $cat->id }}"
                class="border-b border-border-subtle last:border-b-0 hover:bg-surface-2 {{ $cat->is_active ? '' : 'opacity-60' }}">
                {{-- Photo --}}
                <td class="px-2 py-1.5 align-top">
                    @if($cat->photo_url)
                        <a href="{{ $cat->photo_url }}" target="_blank" rel="noopener noreferrer"
                           x-on:mouseenter="openPreview($el, '{{ addslashes($cat->photo_url) }}')"
                           x-on:mouseleave="closePreview()"
                           class="block w-10 h-10 rounded overflow-hidden bg-surface-2 border border-border-subtle"
                           title="Открыть фото в новой вкладке">
                            <img src="{{ $cat->photo_url }}" alt=""
                                 loading="lazy" referrerpolicy="no-referrer"
                                 class="w-full h-full object-cover"
                                 onerror="this.style.display='none'; this.parentElement.classList.add('flex','items-center','justify-center'); this.parentElement.innerHTML += '<span class=\'text-fg-3 text-[9px]\'>нет</span>';">
                        </a>
                    @else
                        <div class="w-10 h-10 rounded bg-surface-2 border border-border-subtle flex items-center justify-center text-fg-3 text-[9px]">нет</div>
                    @endif
                </td>
                {{-- SKU --}}
                <td class="px-2 py-1.5 mono text-fg-1 align-top whitespace-nowrap">
                    <div class="flex items-center gap-1">
                        <span>{{ $cat->sku }}</span>
                        <a href="https://mylift.ru/?text={{ urlencode($cat->sku) }}&fn=find"
                           target="_blank" rel="noopener noreferrer"
                           class="text-sky-700 hover:text-sky-900 text-[11px]"
                           title="Открыть на mylift.ru">↗</a>
                    </div>
                </td>
                {{-- Brand + brand_article --}}
                <td class="px-2 py-1.5 align-top">
                    <div class="text-fg-1 break-words">{{ $cat->brand ?: '—' }}</div>
                    @if($cat->brand_article)
                        <div class="mono text-fg-3 text-[11px] break-all">{{ $cat->brand_article }}</div>
                    @endif
                    @php
                        $extraBrands = is_array($cat->brands)
                            ? array_values(array_unique(array_filter($cat->brands, fn ($b) => is_string($b) && trim($b) !== '' && mb_strtolower(trim($b)) !== mb_strtolower(trim((string) $cat->brand)))))
                            : [];
                    @endphp
                    @if(! empty($extraBrands))
                        <div class="mt-0.5 text-[10.5px] text-fg-3" title="OEM-кросс брендов">
                            +{{ implode(', ', array_slice($extraBrands, 0, 3)) }}{{ count($extraBrands) > 3 ? ' …' : '' }}
                        </div>
                    @endif
                </td>
                {{-- Name + chips --}}
                <td class="px-2 py-1.5 text-fg-1 align-top leading-snug break-words">
                    <div>{{ $cat->name }}</div>
                    @php
                        $catDims = array_values(array_filter([
                            $cat->size_a, $cat->size_b, $cat->size_c,
                            $cat->size_d, $cat->size_e, $cat->size_f,
                        ], fn ($v) => $v !== null));
                        $extraArticles = is_array($cat->articles) ? array_values(array_filter($cat->articles)) : [];
                        $extraArticles = array_values(array_filter(
                            $extraArticles,
                            fn ($a) => mb_strtolower(trim((string) $a)) !== mb_strtolower(trim((string) $cat->brand_article))
                        ));
                    @endphp
                    @if($cat->unit_name || $cat->part_type || $cat->form_factor || ! empty($catDims) || ! empty($extraArticles))
                        <div class="mt-1 flex flex-wrap gap-x-1.5 gap-y-0.5 text-[10.5px]">
                            @if($cat->unit_name)
                                <span class="inline-flex items-center px-1.5 rounded-sm bg-sky-50 text-sky-800">{{ $cat->unit_name }}</span>
                            @endif
                            @if($cat->part_type)
                                <span class="inline-flex items-center px-1.5 rounded-sm bg-surface-2 text-fg-2">{{ $cat->part_type }}</span>
                            @endif
                            @if($cat->form_factor)
                                <span class="inline-flex items-center px-1.5 rounded-sm bg-surface-2 text-fg-2">{{ $cat->form_factor }}</span>
                            @endif
                            @if(! empty($catDims))
                                <span class="inline-flex items-center px-1.5 rounded-sm bg-amber-50 text-amber-800 mono"
                                      title="Размеры из каталога">
                                    {{ implode('×', array_map(fn ($v) => rtrim(rtrim((string) $v, '0'), '.'), $catDims)) }} мм
                                </span>
                            @endif
                            @if(! empty($extraArticles))
                                <span class="inline-flex items-center px-1.5 rounded-sm bg-surface-2 text-fg-3 mono"
                                      title="OEM-артикулы: {{ implode(', ', array_slice($extraArticles, 0, 8)) }}">
                                    +{{ count($extraArticles) }} OEM
                                </span>
                            @endif
                        </div>
                    @endif
                </td>
                {{-- Price --}}
                <td class="px-2 py-1.5 mono text-right text-fg-1 align-top whitespace-nowrap">
                    {{ $cat->price !== null ? number_format((float) $cat->price, 2, '.', ' ') . ' ₽' : '—' }}
                </td>
                {{-- Stock --}}
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
                {{-- Similarity + method icon --}}
                <td class="px-2 py-1.5 mono text-right align-top whitespace-nowrap">
                    <div class="flex items-center justify-end gap-1">
                        @if($methodIcon)
                            <span class="text-[11px]" title="{{ $methodTitle }}">{{ $methodIcon }}</span>
                        @endif
                        <span class="{{ $tone }} font-semibold">{{ (int) round($sim * 100) }}%</span>
                    </div>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- Hover-preview overlay (один на всю таблицу). --}}
<div x-show="show" x-cloak x-transition.opacity
     :style="`position: fixed; left: ${left}px; top: ${top}px; width: 400px; height: 400px; z-index: 9999; pointer-events: none;`"
     class="rounded-lg shadow-xl border border-border-subtle bg-white p-1">
    <img :src="url" alt="" referrerpolicy="no-referrer"
         style="width: 100%; height: 100%; object-fit: cover; border-radius: 4px;">
</div>
</div>
