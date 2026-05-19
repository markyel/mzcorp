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
            // Сразу прячем старое превью — иначе при быстром mouseenter на
            // следующую строку оператор видит старый url пока 700мс таймер
            // не сработает с новым.
            this.show = false;
            // Позиционируем относительно МОДАЛА, а не миниатюры —
            // не перекрываем чекбокс, SKU, никакой контент таблицы.
            const modal = el.closest('.ds-card');
            const modalRect = modal ? modal.getBoundingClientRect() : el.getBoundingClientRect();
            const r = el.getBoundingClientRect();
            const W = 400, H = 400, gap = 12;
            if (modalRect.left - gap - W >= 8) {
                this.left = modalRect.left - gap - W;
            } else if (modalRect.right + gap + W <= window.innerWidth - 8) {
                this.left = modalRect.right + gap;
            } else {
                // Fallback: окно слишком узкое для модала + 400px превью.
                this.left = Math.min(window.innerWidth - W - 8, r.right + gap);
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
@php $compareIdsList = $compareIds ?? []; @endphp
<table class="w-full text-[12px]" style="table-layout: fixed;">
    <colgroup>
        <col style="width: 28px">
        <col style="width: 44px">
        <col style="width: 80px">
        <col style="width: 120px">
        <col>
        <col style="width: 84px">
        <col style="width: 72px">
        @if($rows->first()['similarity'] ?? null !== null)
            <col style="width: 72px">
        @endif
    </colgroup>
    <thead class="bg-surface-2 text-fg-3 uppercase tracking-wider text-[10.5px] sticky top-0">
        <tr>
            <th class="px-2 py-1.5" title="Выберите 1-3 позиции для сравнения с заявкой">⚖️</th>
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
                $inCompare = in_array($cat->id, $compareIdsList, true);
            @endphp
            <tr wire:key="cat-{{ $cat->id }}"
                wire:click="selectCatalog({{ $cat->id }})"
                class="cursor-pointer border-b border-border-subtle last:border-b-0 {{ $selectedId === $cat->id ? 'bg-sky-50' : ($inCompare ? 'bg-amber-50' : 'hover:bg-surface-2') }} {{ $cat->is_active ? '' : 'opacity-60' }}">
                <td class="px-2 py-1.5 align-top text-center" wire:click.stop>
                    <input type="checkbox"
                           wire:click="toggleCompare({{ $cat->id }})"
                           @if($inCompare) checked @endif
                           class="cursor-pointer"
                           title="{{ $inCompare ? 'Убрать из сравнения' : 'Добавить в сравнение с позицией заявки' }}">
                </td>
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
                <td class="px-2 py-1.5 text-fg-1 align-top leading-snug break-words">
                    <div>{{ $cat->name }}</div>
                    @php
                        // Диагностические chip-теги — почему позиция в выдаче,
                        // какие у неё атрибуты, как они соотносятся с subject.
                        $catDims = array_values(array_filter([
                            'A' => $cat->size_a, 'B' => $cat->size_b, 'C' => $cat->size_c,
                            'D' => $cat->size_d, 'E' => $cat->size_e, 'F' => $cat->size_f,
                        ], fn ($v) => $v !== null));
                        $extraArticles = is_array($cat->articles) ? array_values(array_filter($cat->articles)) : [];
                        // brand_article уже в отдельной колонке — не дублируем в Все OEM.
                        $extraArticles = array_values(array_filter(
                            $extraArticles,
                            fn ($a) => mb_strtolower(trim($a)) !== mb_strtolower(trim((string) $cat->brand_article))
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
                                      title="Дополнительные OEM-артикулы">
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
                @if($sim !== null)
                    <td class="px-2 py-1.5 mono text-right align-top whitespace-nowrap">
                        @php
                            $pct = (int) round($sim * 100);
                            $tone = $sim >= 0.85
                                ? 'text-emerald-700'
                                : ($sim >= 0.75 ? 'text-amber-700' : 'text-fg-3');
                            $method = $row['method'] ?? null;
                            $codeScore = $row['code_score'] ?? null;
                            $trgmScore = $row['trgm_score'] ?? null;
                            $vecScore = $row['vector_score'] ?? null;
                            $methodIcon = match ($method) {
                                'multi' => '🔀',
                                'code' => '🎯',
                                'trgm' => '🔤',
                                'vector' => '✨',
                                'both' => '🔀',  // back-compat если придёт старое значение
                                default => null,
                            };
                            $parts = [];
                            if ($codeScore !== null) $parts[] = 'code-token';
                            if ($trgmScore !== null) $parts[] = 'trgm ' . round($trgmScore * 100) . '%';
                            if ($vecScore !== null) $parts[] = 'vec ' . round($vecScore * 100) . '%';
                            $methodTitle = match ($method) {
                                'multi' => 'Найдено несколькими способами: ' . implode(', ', $parts),
                                'code' => 'Точное вхождение кода (ILIKE)',
                                'trgm' => 'Текстовое совпадение (pg_trgm)',
                                'vector' => 'Семантическая похожесть (vector)',
                                'both' => 'Найдено текстом и семантикой: ' . implode(', ', $parts),
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
