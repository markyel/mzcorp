{{-- Partial: таблица результатов standalone-поиска каталога.
     Ожидает: $rows — Collection<array{catalog: CatalogItem, similarity: float, method, code_score, trgm_score, vector_score}>.
     В отличие от requests/items/_catalog-results-table — нет
     selectCatalog/toggleCompare actions: standalone-поиск не привязан
     к заявке, единственное действие — открыть на mylift.ru или
     развернуть детальную карточку (click по строке).

     Layout: каждая позиция = отдельный <tbody> с двумя <tr>'ями
     (summary + expandable detail). Multiple tbody в одной table
     разрешён HTML5 и даёт чистый Alpine scope для пары tr — иначе
     стейт `open` не пробрасывается между sibling-tr в одной tbody. --}}
<div x-data="{
        show: false, url: '', t: null, top: 0, left: 0,
        openPreview(el, photoUrl) {
            clearTimeout(this.t);
            this.show = false;
            const r = el.getBoundingClientRect();
            const W = 480, H = 480, gap = 12;
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
     }"
     x-on:catalog-preview-open.window="openPreview($event.detail.el, $event.detail.url)"
     x-on:catalog-preview-close.window="closePreview()">
<table class="w-full text-[12px]" style="table-layout: fixed;">
    <colgroup>
        <col style="width: 24px">
        <col style="width: 84px">
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
            <th class="px-2 py-1.5"></th>
            <th class="px-2 py-1.5 text-left">SKU</th>
            <th class="px-2 py-1.5 text-left">Бренд / артикул</th>
            <th class="px-2 py-1.5 text-left">Название</th>
            <th class="px-2 py-1.5 text-right">Цена</th>
            <th class="px-2 py-1.5 text-right">Наличие</th>
            <th class="px-2 py-1.5 text-right">Похожесть</th>
        </tr>
    </thead>
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

            $catDims = array_values(array_filter([
                'A' => $cat->size_a, 'B' => $cat->size_b, 'C' => $cat->size_c,
                'D' => $cat->size_d, 'E' => $cat->size_e, 'F' => $cat->size_f,
            ], fn ($v) => $v !== null));
            $catDimsLabeled = [];
            foreach (['A','B','C','D','E','F'] as $i => $k) {
                $v = $cat->{'size_' . strtolower($k)};
                if ($v !== null) $catDimsLabeled[$k] = rtrim(rtrim((string) $v, '0'), '.');
            }
            $allArticles = is_array($cat->articles) ? array_values(array_filter($cat->articles, fn ($a) => is_string($a) && trim($a) !== '')) : [];
            $extraArticles = array_values(array_filter(
                $allArticles,
                fn ($a) => mb_strtolower(trim((string) $a)) !== mb_strtolower(trim((string) $cat->brand_article))
            ));
            $allBrands = is_array($cat->brands) ? array_values(array_filter($cat->brands, fn ($b) => is_string($b) && trim($b) !== '')) : [];
            $extraBrands = array_values(array_unique(array_filter(
                $allBrands,
                fn ($b) => mb_strtolower(trim($b)) !== mb_strtolower(trim((string) $cat->brand))
            )));
            $allUnits = is_array($cat->units) ? array_values(array_filter($cat->units, fn ($u) => is_string($u) && trim($u) !== '')) : [];
        @endphp
        <tbody x-data="{ open: false }"
               wire:key="cat-tbody-{{ $cat->id }}"
               class="border-b border-border-subtle {{ $cat->is_active ? '' : 'opacity-60' }}">
            {{-- ─── Summary row ─── --}}
            <tr class="hover:bg-surface-2 cursor-pointer"
                @click="open = !open"
                :class="open && 'bg-sky-50'"
                title="Кликните, чтобы развернуть карточку товара">
                <td class="px-1 py-1.5 align-middle text-center text-fg-3 text-[12px] select-none">
                    <span x-text="open ? '▾' : '▸'"></span>
                </td>
                <td class="px-2 py-1.5 align-top" @click.stop>
                    @if($cat->photo_url)
                        <a href="{{ $cat->photo_url }}" target="_blank" rel="noopener noreferrer"
                           x-on:mouseenter="$dispatch('catalog-preview-open', { el: $el, url: '{{ addslashes($cat->photo_url) }}' })"
                           x-on:mouseleave="$dispatch('catalog-preview-close')"
                           class="block w-[72px] h-[72px] rounded overflow-hidden bg-surface-2 border border-border-subtle"
                           title="Открыть фото в новой вкладке">
                            <img src="{{ $cat->photo_url }}" alt=""
                                 loading="lazy" referrerpolicy="no-referrer"
                                 class="w-full h-full object-cover"
                                 onerror="this.style.display='none'; this.parentElement.classList.add('flex','items-center','justify-center'); this.parentElement.innerHTML += '<span class=\'text-fg-3 text-[10px]\'>нет</span>';">
                        </a>
                    @else
                        <div class="w-[72px] h-[72px] rounded bg-surface-2 border border-border-subtle flex items-center justify-center text-fg-3 text-[10px]">нет фото</div>
                    @endif
                </td>
                <td class="px-2 py-1.5 mono text-fg-1 align-top whitespace-nowrap">
                    <div class="flex items-center gap-1" @click.stop>
                        <span>{{ $cat->sku }}</span>
                        <a href="https://mylift.ru/?text={{ urlencode($cat->sku) }}&fn=find"
                           target="_blank" rel="noopener noreferrer"
                           class="text-sky-700 hover:text-sky-900 text-[11px]"
                           title="Открыть на mylift.ru">↗</a>
                    </div>
                </td>
                <td class="px-2 py-1.5 align-top">
                    <div class="text-fg-1 break-words">{{ $cat->brand ?: '—' }}</div>
                    @if($cat->brand_article)
                        <div class="mono text-fg-3 text-[11px] break-all">{{ $cat->brand_article }}</div>
                    @endif
                    @if(! empty($extraBrands))
                        <div class="mt-0.5 text-[10.5px] text-fg-3" title="OEM-кросс брендов: {{ implode(', ', $extraBrands) }}">
                            +{{ implode(', ', array_slice($extraBrands, 0, 3)) }}{{ count($extraBrands) > 3 ? ' …' : '' }}
                        </div>
                    @endif
                </td>
                <td class="px-2 py-1.5 text-fg-1 align-top leading-snug break-words">
                    <div>{{ $cat->name }}</div>
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
                <td class="px-2 py-1.5 mono text-right align-top whitespace-nowrap">
                    <div class="flex items-center justify-end gap-1">
                        @if($methodIcon)
                            <span class="text-[11px]" title="{{ $methodTitle }}">{{ $methodIcon }}</span>
                        @endif
                        <span class="{{ $tone }} font-semibold">{{ (int) round($sim * 100) }}%</span>
                    </div>
                </td>
            </tr>

            {{-- ─── Detail row (expandable, x-show) ─── --}}
            <tr x-show="open" x-cloak x-transition.opacity.duration.150ms
                class="bg-surface-2/40">
                <td></td>
                <td colspan="7" class="px-3 py-3">
                    <div class="grid grid-cols-12 gap-4 items-start">
                        {{-- Большое фото (250×250) --}}
                        <div class="col-span-12 sm:col-span-4 md:col-span-3">
                            @if($cat->photo_url)
                                <a href="{{ $cat->photo_url }}" target="_blank" rel="noopener noreferrer"
                                   class="block w-full max-w-[260px] rounded-md overflow-hidden bg-app border border-border-subtle"
                                   style="aspect-ratio: 1 / 1;">
                                    <img src="{{ $cat->photo_url }}" alt="{{ $cat->name }}"
                                         loading="lazy" referrerpolicy="no-referrer"
                                         class="w-full h-full object-cover">
                                </a>
                            @else
                                <div class="w-full max-w-[260px] rounded-md bg-app border border-border-subtle flex items-center justify-center text-fg-3 text-[11px]"
                                     style="aspect-ratio: 1 / 1;">нет фото</div>
                            @endif
                            <div class="mt-2 text-[11px] text-fg-3 mono">
                                ID: {{ $cat->id }}
                                @if($cat->last_imported_at)
                                    · импорт: {{ $cat->last_imported_at->format('d.m.Y H:i') }}
                                @endif
                            </div>
                        </div>

                        {{-- Информация ─── --}}
                        <div class="col-span-12 sm:col-span-8 md:col-span-9 space-y-3">
                            {{-- Header --}}
                            <div>
                                <div class="text-[14px] font-semibold text-fg-1 leading-snug">{{ $cat->name }}</div>
                                @if($cat->name_en)
                                    <div class="text-[12px] text-fg-3 italic mt-0.5">{{ $cat->name_en }}</div>
                                @endif
                                @if($cat->description)
                                    <div class="text-[12px] text-fg-2 mt-1 whitespace-pre-line">{{ $cat->description }}</div>
                                @endif
                            </div>

                            {{-- ─── 4-column data grid ─── --}}
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-2 text-[11.5px]">
                                {{-- Идентификация --}}
                                <div>
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">SKU</div>
                                    <div class="mono text-fg-1">{{ $cat->sku }}</div>
                                </div>
                                <div>
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Primary brand</div>
                                    <div class="text-fg-1">{{ $cat->brand ?: '—' }}</div>
                                </div>
                                <div>
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Primary артикул</div>
                                    <div class="mono text-fg-1 break-all">{{ $cat->brand_article ?: '—' }}</div>
                                    @if($cat->brand_article_normalized && $cat->brand_article_normalized !== $cat->brand_article)
                                        <div class="mono text-fg-3 text-[10.5px] break-all" title="Нормализованная форма для match">{{ $cat->brand_article_normalized }}</div>
                                    @endif
                                </div>
                                <div>
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Активна</div>
                                    <div class="text-fg-1">
                                        {{ $cat->is_active ? 'да' : 'нет (архив)' }}
                                    </div>
                                </div>

                                {{-- Классификация --}}
                                <div>
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Узел</div>
                                    <div class="text-fg-1">{{ $cat->unit_name ?: '—' }}</div>
                                </div>
                                <div>
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Размещение</div>
                                    <div class="text-fg-1">{{ $cat->placement ?: '—' }}</div>
                                </div>
                                <div>
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Тип</div>
                                    <div class="text-fg-1">{{ $cat->part_type ?: '—' }}</div>
                                </div>
                                <div>
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Форм-фактор</div>
                                    <div class="text-fg-1">{{ $cat->form_factor ?: '—' }}</div>
                                </div>

                                {{-- Цена + наличие --}}
                                <div>
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Цена</div>
                                    <div class="mono text-fg-1">{{ $cat->price !== null ? number_format((float) $cat->price, 2, '.', ' ') . ' ₽' : '—' }}</div>
                                    @if($cat->is_price_actual === false)
                                        <div class="text-[10.5px] text-amber-700">не актуальна</div>
                                    @endif
                                </div>
                                <div>
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Цена мин</div>
                                    <div class="mono text-fg-1">{{ $cat->price_min !== null ? number_format((float) $cat->price_min, 2, '.', ' ') . ' ₽' : '—' }}</div>
                                </div>
                                <div>
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Наличие</div>
                                    <div class="text-fg-1">
                                        @if($cat->stock_available === null)
                                            —
                                        @elseif($cat->stock_available > 0)
                                            <span class="text-emerald-700">{{ $cat->stock_available }} шт</span>
                                        @else
                                            <span class="text-amber-700">нет</span>
                                        @endif
                                    </div>
                                </div>
                                <div>
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Срок поставки</div>
                                    <div class="text-fg-1">{{ $cat->lead_time_days !== null ? $cat->lead_time_days . ' дн' : '—' }}</div>
                                </div>

                                {{-- Физика --}}
                                @if(! empty($catDimsLabeled))
                                    <div class="col-span-2 md:col-span-3">
                                        <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Размеры (мм)</div>
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach($catDimsLabeled as $k => $v)
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-sm bg-amber-50 text-amber-800 mono text-[11px]">
                                                    <span class="text-amber-600">{{ $k }}:</span>{{ $v }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                                <div>
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Вес</div>
                                    <div class="mono text-fg-1">{{ $cat->weight !== null ? rtrim(rtrim((string) $cat->weight, '0'), '.') . ' кг' : '—' }}</div>
                                </div>
                            </div>

                            {{-- Multi-OEM brands[] + articles[] --}}
                            @if(! empty($allBrands) || ! empty($allArticles))
                                <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-[11.5px]">
                                    @if(! empty($allBrands))
                                        <div>
                                            <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Все бренды ({{ count($allBrands) }})</div>
                                            <div class="flex flex-wrap gap-1">
                                                @foreach($allBrands as $i => $b)
                                                    @php $isPrimary = $i === 0 || (is_string($cat->brand) && mb_strtolower(trim($b)) === mb_strtolower(trim($cat->brand))); @endphp
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-sm text-[11px] {{ $isPrimary ? 'bg-emerald-50 text-emerald-800 font-semibold' : 'bg-surface-2 text-fg-2 border border-border-subtle' }}">
                                                        {{ $b }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                    @if(! empty($allArticles))
                                        <div>
                                            <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Все OEM-артикулы ({{ count($allArticles) }})</div>
                                            <div class="flex flex-wrap gap-1">
                                                @foreach($allArticles as $a)
                                                    @php $isPrimary = is_string($cat->brand_article) && mb_strtolower(trim($a)) === mb_strtolower(trim($cat->brand_article)); @endphp
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-sm mono text-[11px] {{ $isPrimary ? 'bg-emerald-50 text-emerald-800 font-semibold' : 'bg-surface-2 text-fg-2 border border-border-subtle' }}">
                                                        {{ $a }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            {{-- Список узлов («Узлы» из MDB) --}}
                            @if(count($allUnits) > 1)
                                <div class="text-[11.5px]">
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Все узлы ({{ count($allUnits) }})</div>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($allUnits as $u)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-sm bg-sky-50 text-sky-800 text-[11px]">{{ $u }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Комментарий --}}
                            @if($cat->comment)
                                <div class="text-[11.5px]">
                                    <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-0.5">Комментарий</div>
                                    <div class="text-fg-2 whitespace-pre-line">{{ $cat->comment }}</div>
                                </div>
                            @endif

                            {{-- Action bar --}}
                            <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                                <a href="https://mylift.ru/?text={{ urlencode($cat->sku) }}&fn=find"
                                   target="_blank" rel="noopener noreferrer"
                                   class="btn btn-sm">
                                    ↗ Открыть на mylift.ru
                                </a>
                                @if($cat->photo_url)
                                    <a href="{{ $cat->photo_url }}" target="_blank" rel="noopener noreferrer"
                                       class="btn btn-sm">
                                        🖼 Полный размер фото
                                    </a>
                                @endif
                                <span class="flex-1"></span>
                                <button type="button" @click="open = false"
                                        class="text-fg-3 hover:text-fg-1 text-[11.5px]"
                                        title="Свернуть карточку">
                                    ▴ свернуть
                                </button>
                            </div>
                        </div>
                    </div>
                </td>
            </tr>
        </tbody>
    @endforeach
</table>

{{-- Hover-preview overlay (один на всю таблицу). --}}
<div x-show="show" x-cloak x-transition.opacity
     :style="`position: fixed; left: ${left}px; top: ${top}px; width: 480px; height: 480px; z-index: 9999; pointer-events: none;`"
     class="rounded-lg shadow-xl border border-border-subtle bg-white p-1">
    <img :src="url" alt="" referrerpolicy="no-referrer"
         style="width: 100%; height: 100%; object-fit: cover; border-radius: 4px;">
</div>
</div>
