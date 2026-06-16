{{-- Partial: таблица результатов standalone-поиска каталога.
     Ожидает: $rows — Collection<array{catalog, similarity, method, code_score, trgm_score, vector_score}>.
     Макет: design/uploads/07-catalog-search.html.

     Layout — CSS Grid (не <table>), потому что нужны expandable rows
     с двухколоночной детальной карточкой внутри. Каждая позиция =
     2 sibling-блока (summary + expanded) в Alpine x-data{open} scope. --}}
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
            this.t = setTimeout(() => { this.show = true; }, 350);
        },
        closePreview() {
            clearTimeout(this.t);
            this.show = false;
        }
     }"
     x-on:catalog-preview-open.window="openPreview($event.detail.el, $event.detail.url)"
     x-on:catalog-preview-close.window="closePreview()"
     x-on:click.window="closePreview()"
     x-on:mousemove.window.throttle.100ms="
        if (show && !$event.target.closest('[data-cat-preview-trigger]')) {
            closePreview();
        }
     "
     class="bg-surface border border-border rounded-md overflow-hidden">

    {{-- ─── Table head ─── --}}
    @php $gridCols = '64px 88px 160px 1fr 120px 88px 96px 36px'; @endphp
    <div class="bg-surface-2 border-b text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold"
         style="display: grid; align-items: center; padding: 0 16px; height: 34px; grid-template-columns: {{ $gridCols }}; column-gap: 14px; border-color: var(--border-strong);">
        <span></span>
        <span>SKU</span>
        <span>Бренд / артикул</span>
        <span>Название</span>
        <span class="text-right">Цена</span>
        <span class="text-right">Наличие</span>
        <span class="text-right">Похожесть ↓</span>
        <span></span>
    </div>

    @foreach($rows as $row)
        @php
            $cat = $row['catalog'];
            $sim = $row['similarity'] ?? null;
            $method = $row['method'] ?? null;
            $methodIcon = match ($method) {
                'multi' => '🔀',
                'code' => '🎯',
                'trgm' => '🔤',
                'vector' => '✨',
                default => null,
            };
            $simParts = [];
            if (($row['code_score'] ?? null) !== null)   $simParts[] = 'code-token';
            if (($row['trgm_score'] ?? null) !== null)   $simParts[] = 'trgm ' . round($row['trgm_score'] * 100) . '%';
            if (($row['vector_score'] ?? null) !== null) $simParts[] = 'vec '  . round($row['vector_score']  * 100) . '%';
            $methodTitle = match ($method) {
                'multi'  => 'Найдено несколькими способами: ' . implode(', ', $simParts),
                'code'   => 'Точное вхождение кода (ILIKE)',
                'trgm'   => 'Текстовое совпадение (pg_trgm)',
                'vector' => 'Семантическая похожесть (vector)',
                default  => '',
            };
            // Цветовая шкала похожести как в макете: full (sky) для multi
            // / >=0.85 emerald / >=0.75 amber / иначе серый.
            $simTone = $sim >= 0.95 ? 'full' : ($sim >= 0.85 ? '' : ($sim >= 0.75 ? 'mid' : 'low'));
            $simPctClass = match($simTone) {
                'full' => 'text-sky-700',
                ''     => 'text-emerald-700',
                'mid'  => 'text-amber-700',
                'low'  => 'text-fg-3',
            };
            $simBarColor = match($simTone) {
                'full' => 'var(--sky-500)',
                ''     => 'var(--emerald-600)',
                'mid'  => 'var(--amber-600)',
                'low'  => 'var(--neutral-400)',
            };

            // Multi-OEM
            $allBrands = is_array($cat->brands) ? array_values(array_filter($cat->brands, fn ($b) => is_string($b) && trim($b) !== '')) : [];
            $extraBrands = array_values(array_unique(array_filter(
                $allBrands,
                fn ($b) => mb_strtolower(trim($b)) !== mb_strtolower(trim((string) $cat->brand))
            )));
            $allArticles = is_array($cat->articles) ? array_values(array_filter($cat->articles, fn ($a) => is_string($a) && trim($a) !== '')) : [];
            $extraArticles = array_values(array_filter(
                $allArticles,
                fn ($a) => mb_strtolower(trim((string) $a)) !== mb_strtolower(trim((string) $cat->brand_article))
            ));
            $allUnits = is_array($cat->units) ? array_values(array_filter($cat->units, fn ($u) => is_string($u) && trim($u) !== '')) : [];

            // Размеры — фильтруем null И 0 (в импорте часто 0 = «не указано»).
            // rtrim сам по себе делает '0.000' → '' что давало баг «0 × 0 × 0 мм».
            $catDimsLabeled = [];
            foreach (['A','B','C','D','E','F'] as $k) {
                $v = $cat->{'size_' . strtolower($k)};
                if ($v !== null && (float) $v > 0) {
                    $catDimsLabeled[$k] = rtrim(rtrim((string) $v, '0'), '.');
                }
            }
            $dimSummary = implode(' × ', $catDimsLabeled);
        @endphp

        <div x-data="{ open: false }"
             wire:key="cat-tbody-{{ $cat->id }}"
             class="border-b border-border-subtle last:border-b-0 {{ $cat->is_active ? '' : 'opacity-60' }}">

            {{-- ─── Summary row ─── --}}
            {{-- Все стили через inline `:style` reactive — НЕ через
                 `:class="open && 'bg-[var(--..)]'"`. Tailwind tree-shake'ит
                 arbitrary классы которых нет в исходниках на build, и в Alpine
                 :class они появляются только в runtime → CSS-правил нет.
                 `:style` всегда применяется напрямую через DOM, минуя CSS. --}}
            <div :style="open
                    ? 'display: grid; align-items: center; padding: 12px 16px; min-height: 68px; grid-template-columns: {{ $gridCols }}; column-gap: 14px; background: var(--bg-selected); box-shadow: inset 3px 0 0 var(--sky-500); cursor: default;'
                    : 'display: grid; align-items: center; padding: 12px 16px; min-height: 68px; grid-template-columns: {{ $gridCols }}; column-gap: 14px; cursor: pointer; transition: background 120ms;'"
                 style="display: grid; align-items: center; padding: 12px 16px; min-height: 68px; grid-template-columns: {{ $gridCols }}; column-gap: 14px; cursor: pointer;"
                 @mouseenter="if (!open) $el.style.background = 'var(--bg-hover)'"
                 @mouseleave="if (!open) $el.style.background = ''"
                 @click="open = !open"
                 title="Кликните, чтобы развернуть карточку товара">

                {{-- Thumb 56×56 — через наш прокси с дисковым кэшем (route catalog.photo).
                     Original photo_url остаётся в href для full-size в новой вкладке. --}}
                <div @click.stop>
                    @if($cat->photo_url)
                        @php $catThumb = route('catalog.photo', $cat->id); @endphp
                        <a href="{{ $cat->photo_url }}" target="_blank" rel="noopener noreferrer"
                           data-cat-preview-trigger
                           x-on:mouseenter="$dispatch('catalog-preview-open', { el: $el, url: '{{ addslashes($catThumb) }}' })"
                           x-on:mouseleave="$dispatch('catalog-preview-close')"
                           class="block w-14 h-14 rounded-md overflow-hidden bg-surface-2 border border-border"
                           title="Открыть фото в новой вкладке">
                            <img src="{{ $catThumb }}" alt=""
                                 loading="lazy" referrerpolicy="no-referrer"
                                 class="w-full h-full object-cover"
                                 onerror="this.style.display='none'; this.parentElement.classList.add('flex','items-center','justify-center'); this.parentElement.innerHTML += '<span class=\'text-fg-3 text-[10px]\'>нет</span>';">
                        </a>
                    @else
                        <div class="w-14 h-14 rounded-md bg-surface-2 border border-border flex items-center justify-center text-fg-3 text-[10px] text-center leading-tight">нет<br>фото</div>
                    @endif
                </div>

                {{-- SKU --}}
                <div class="mono text-[12.5px] text-fg-2 leading-tight">
                    <span class="block text-fg-1 font-semibold mb-0.5">{{ $cat->sku }}</span>
                    <a href="https://mylift.ru/?text={{ urlencode($cat->sku) }}&fn=find"
                       target="_blank" rel="noopener noreferrer"
                       @click.stop
                       class="text-[10.5px] font-medium text-sky-700 hover:text-sky-900 sans"
                       style="text-decoration: underline dashed; text-underline-offset: 2px;">↗ открыть</a>
                </div>

                {{-- Brand chip + article + alt brands --}}
                <div>
                    @if($cat->brand)
                        <span class="inline-block text-[10.5px] font-semibold bg-neutral-100 text-neutral-700 px-1.5 py-0.5 rounded uppercase tracking-wider mb-1">{{ $cat->brand }}</span>
                    @endif
                    @if($cat->brand_article)
                        <div class="mono text-[11.5px] text-fg-2 break-all leading-snug">{{ $cat->brand_article }}</div>
                    @endif
                    @if(! empty($extraBrands))
                        <div class="text-[11px] text-fg-3 mt-1 leading-snug" title="OEM-кросс брендов: {{ implode(', ', $extraBrands) }}">
                            + {{ implode(', ', array_slice($extraBrands, 0, 2)) }}{{ count($extraBrands) > 2 ? ' …' : '' }}
                        </div>
                    @endif
                </div>

                {{-- Name + tags --}}
                <div>
                    <div class="text-[13.5px] font-medium text-fg-1 leading-snug mb-1.5">{{ $cat->name }}</div>
                    <div class="flex items-center gap-1.5 flex-wrap text-[11px]">
                        @if($cat->unit_name)
                            <span class="inline-flex items-center h-5 px-1.5 rounded bg-surface-2 text-fg-2 text-[10.5px] font-medium border border-border-subtle whitespace-nowrap overflow-hidden text-ellipsis" style="max-width: 280px;">{{ $cat->unit_name }}</span>
                        @endif
                        @if($cat->part_type)
                            <span class="inline-flex items-center h-5 px-1.5 rounded bg-sky-50 text-sky-700 text-[10.5px] font-medium whitespace-nowrap overflow-hidden text-ellipsis" style="max-width: 280px;">{{ $cat->part_type }}</span>
                        @endif
                        @if($cat->form_factor)
                            <span class="inline-flex items-center h-5 px-1.5 rounded bg-surface-2 text-fg-2 text-[10.5px] font-medium border border-border-subtle">{{ $cat->form_factor }}</span>
                        @endif
                        @if($dimSummary)
                            <span class="inline-flex items-center h-5 px-1.5 rounded mono"
                                  style="background: #fef3c7; color: #92400e;"
                                  title="Размеры из каталога">{{ $dimSummary }} мм</span>
                        @endif
                        @if(! empty($extraArticles))
                            <span class="inline-flex items-center h-5 px-1.5 rounded font-semibold mono text-[10.5px]"
                                  style="background: #f1eafe; color: #6d28d9;"
                                  title="OEM-артикулы: {{ implode(', ', array_slice($extraArticles, 0, 8)) }}">
                                + {{ count($extraArticles) }} OEM
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Цена --}}
                <div class="text-right mono font-semibold text-[13.5px] whitespace-nowrap {{ ($cat->price ?? 0) > 0 ? 'text-fg-1' : 'text-fg-3 font-normal' }}">
                    {{ $cat->price !== null ? number_format((float) $cat->price, 2, ',', ' ') . ' ₽' : '—' }}
                </div>

                {{-- Наличие --}}
                <div class="text-right text-[12.5px] whitespace-nowrap">
                    @if($cat->stock_available === null)
                        <span class="text-fg-3">—</span>
                    @elseif($cat->stock_available > 0)
                        <span class="text-emerald-700 font-semibold">{{ $cat->stock_available }} шт</span>
                    @else
                        <span class="text-fg-3">нет</span>
                    @endif
                    @if($cat->lead_time_days !== null && $cat->stock_available !== null && $cat->stock_available <= 0)
                        <small class="block text-fg-3 text-[10.5px] mt-0.5">{{ $cat->lead_time_days }} дн</small>
                    @endif
                    @if(! $cat->is_active)
                        <small class="block text-fg-3 text-[10px] uppercase mt-0.5">архив</small>
                    @endif
                </div>

                {{-- Similarity + bar --}}
                <div class="flex flex-col items-end gap-1">
                    <span class="mono font-bold text-[13px] {{ $simPctClass }}" style="font-feature-settings: 'tnum';">
                        @if($methodIcon)<span class="text-[11px] mr-1" title="{{ $methodTitle }}">{{ $methodIcon }}</span>@endif
                        {{ (int) round($sim * 100) }}%
                    </span>
                    <span class="block rounded-full overflow-hidden" style="width: 64px; height: 4px; background: var(--neutral-100);">
                        <span class="block h-full" style="width: {{ (int) round($sim * 100) }}%; background: {{ $simBarColor }};"></span>
                    </span>
                </div>

                {{-- Toggle indicator --}}
                <div class="text-center text-fg-3 font-bold cursor-pointer p-1.5 select-none hover:text-fg-1"
                     style="letter-spacing: 1px;"
                     :class="open && 'text-sky-700'">
                    <span x-text="open ? '▴' : '⋯'"></span>
                </div>
            </div>

            {{-- ─── Expanded detail (280px + 1fr) ─── --}}
            {{-- x-show управляет display напрямую через DOM. Initial inline
                 style="display: none" — скрывает блок ДО Alpine init.
                 При open=true Alpine выставит style.display = '' (показ).
                 БЕЗ x-transition — оно создавало race conditions с
                 inline display:none при Livewire morph.
                 БЕЗ :class="... hidden ..." — Alpine :class со строкой
                 не удаляет существующие классы из обычного `class`. --}}
            <div x-show="open"
                 class="px-4 pb-4"
                 style="display: none; background: var(--bg-selected); box-shadow: inset 3px 0 0 var(--sky-500); border-bottom: 1px solid var(--border-subtle);">

                <div style="display: grid; grid-template-columns: 280px 1fr; gap: 20px; padding: 8px 0;">

                    {{-- ─── Левая колонка: фото + meta + price panel ─── --}}
                    <div class="flex flex-col gap-2">
                        @if($cat->photo_url)
                            <a href="{{ $cat->photo_url }}" target="_blank" rel="noopener noreferrer"
                               class="block w-full rounded-md overflow-hidden bg-surface border border-border"
                               style="aspect-ratio: 1/1;">
                                <img src="{{ route('catalog.photo', $cat->id) }}" alt="{{ $cat->name }}"
                                     loading="lazy" referrerpolicy="no-referrer"
                                     class="w-full h-full object-cover">
                            </a>
                        @else
                            <div class="w-full rounded-md bg-surface border border-border flex items-center justify-center text-fg-3 text-[11px] mono"
                                 style="aspect-ratio: 1/1;">нет фото</div>
                        @endif

                        <div class="text-[11px] mono text-fg-3">
                            ID: {{ $cat->id }}
                            @if($cat->last_imported_at)
                                · импорт: {{ $cat->last_imported_at->format('d.m.Y, H:i') }}
                            @endif
                            @if($cat->last_import_id)
                                · run #{{ $cat->last_import_id }}
                            @endif
                        </div>

                        <div class="flex gap-1.5 flex-wrap">
                            <a href="https://mylift.ru/?text={{ urlencode($cat->sku) }}&fn=find"
                               target="_blank" rel="noopener noreferrer"
                               class="inline-flex items-center gap-1 h-6 px-2 rounded-md bg-surface border border-border text-sky-700 text-[11px] font-medium hover:bg-[var(--bg-hover)]">
                                ↗ Открыть на mylift.ru
                            </a>
                            @if($cat->photo_url)
                                <a href="{{ $cat->photo_url }}" target="_blank" rel="noopener noreferrer"
                                   class="inline-flex items-center gap-1 h-6 px-2 rounded-md bg-surface border border-border text-sky-700 text-[11px] font-medium hover:bg-[var(--bg-hover)]">
                                    ⛶ Полный размер фото
                                </a>
                            @endif
                        </div>

                        {{-- Price / stock panel --}}
                        <div class="bg-surface border border-border rounded-md overflow-hidden mt-1">
                            <div class="px-3.5 py-2 bg-surface-2 border-b border-border-subtle text-[10px] uppercase tracking-wider text-fg-3 font-semibold">Цена и наличие</div>
                            <div class="flex justify-between items-baseline px-3.5 py-2 border-b border-border-subtle text-[12.5px]">
                                <div class="text-fg-3 text-[12px] font-medium">Цена</div>
                                <div class="mono text-[16px] font-semibold text-fg-1" style="font-feature-settings: 'tnum';">
                                    {{ $cat->price !== null ? number_format((float) $cat->price, 2, ',', ' ') . ' ₽' : '—' }}
                                </div>
                            </div>
                            <div class="flex justify-between items-baseline px-3.5 py-2 border-b border-border-subtle text-[12.5px]">
                                <div class="text-fg-3 text-[12px] font-medium">Цена мин.</div>
                                <div class="mono text-[13px] text-fg-1" style="font-feature-settings: 'tnum';">
                                    {{ $cat->price_min !== null ? number_format((float) $cat->price_min, 2, ',', ' ') . ' ₽' : '—' }}
                                </div>
                            </div>
                            @if($cat->is_price_actual === false)
                                <div class="flex justify-between items-baseline px-3.5 py-2 border-b border-border-subtle text-[12.5px]">
                                    <div class="text-fg-3 text-[12px] font-medium">Актуальность цены</div>
                                    <div class="text-amber-700 text-[12.5px] font-semibold">не актуальна</div>
                                </div>
                            @endif
                            <div class="flex justify-between items-baseline px-3.5 py-2 border-b border-border-subtle text-[12.5px]">
                                <div class="text-fg-3 text-[12px] font-medium">Наличие</div>
                                <div class="text-[13px] font-semibold {{ ($cat->stock_available ?? 0) > 0 ? 'text-emerald-700' : 'text-fg-3' }}">
                                    @if($cat->stock_available === null) — @elseif($cat->stock_available > 0) {{ $cat->stock_available }} шт @else нет @endif
                                </div>
                            </div>
                            <div class="flex justify-between items-baseline px-3.5 py-2 border-b border-border-subtle text-[12.5px]">
                                <div class="text-fg-3 text-[12px] font-medium">Срок поставки</div>
                                <div class="mono text-[13px] text-fg-1">{{ $cat->lead_time_days !== null ? $cat->lead_time_days . ' дн' : '—' }}</div>
                            </div>

                            {{-- Динамика цены: подорожал / подешевел по последнему
                                 зафиксированному изменению (catalog_price_changes). --}}
                            @php $pc = $this->lastPriceChangeByCatalogId->get($cat->id); @endphp
                            <div class="flex justify-between items-baseline px-3.5 py-2 text-[12.5px]">
                                <div class="text-fg-3 text-[12px] font-medium">Динамика цены</div>
                                <div class="text-[12.5px] text-right">
                                    @php
                                        $pcDelta = $pc?->priceDelta();
                                        $pcPct = ($pcDelta !== null && $pc && (float) $pc->old_price != 0.0)
                                            ? round($pcDelta / (float) $pc->old_price * 100, 1) : null;
                                    @endphp
                                    @if($pc && $pcDelta !== null && $pcDelta > 0)
                                        <span class="font-semibold text-red-600">▲ подорожал на {{ number_format($pcDelta, 2, ',', ' ') }} ₽@if($pcPct !== null) (+{{ $pcPct }}%)@endif</span>
                                    @elseif($pc && $pcDelta !== null && $pcDelta < 0)
                                        <span class="font-semibold text-emerald-700">▼ подешевел на {{ number_format(abs($pcDelta), 2, ',', ' ') }} ₽@if($pcPct !== null) ({{ $pcPct }}%)@endif</span>
                                    @elseif($pc)
                                        <span class="text-fg-3">менялась (см. историю)</span>
                                    @else
                                        <span class="text-fg-3">без изменений</span>
                                    @endif
                                    @if($pc?->changed_at)
                                        <div class="text-[10.5px] text-fg-3 mt-0.5">с {{ $pc->changed_at->format('d.m.Y') }}</div>
                                    @endif
                                </div>
                            </div>

                            @if(auth()->user()?->hasAnyRole(['head_of_sales', 'director', 'secretary', 'admin']))
                                <div class="px-3.5 py-2 border-t border-border-subtle text-right">
                                    <a href="{{ route('analytics.price-changes', ['q' => $cat->sku]) }}" wire:navigate
                                       class="text-[11.5px] font-medium text-sky-700 hover:underline">История цен по позиции →</a>
                                </div>
                            @endif
                        </div>

                        {{-- IQOT · анализ цен конкурентов (РОП / директор / админ) --}}
                        @if($this->canIqot)
                            @php $iqp = $this->iqotByCatalogId->get($cat->id); @endphp
                            <div class="bg-surface border border-border rounded-md overflow-hidden mt-2">
                                <div class="px-3.5 py-2 bg-surface-2 border-b border-border-subtle text-[10px] uppercase tracking-wider text-fg-3 font-semibold flex items-center justify-between">
                                    <span>IQOT · цены конкурентов</span>
                                    <a href="{{ route('iqot.index') }}" wire:navigate class="text-sky-700 normal-case font-medium">Раздел →</a>
                                </div>
                                @if($iqp && $iqp->hasFreshReport())
                                    <div class="flex justify-between items-baseline px-3.5 py-2 border-b border-border-subtle text-[12.5px]">
                                        <div class="text-fg-3 text-[12px] font-medium">Мин. цена (IQOT)</div>
                                        <div class="mono text-[14px] font-semibold text-emerald-700">{{ $iqp->report_min_price !== null ? number_format((float) $iqp->report_min_price, 2, ',', ' ') . ' ₽' : '—' }}</div>
                                    </div>
                                    <div class="flex justify-between items-center px-3.5 py-2 text-[11.5px]">
                                        <div class="text-fg-3">Офферов: <span class="text-fg-1 mono">{{ $iqp->report_offers_count ?? '—' }}</span> · {{ $iqp->analyzed_at?->format('d.m.Y') }}</div>
                                        <a href="{{ route('iqot.index', ['q' => $cat->sku]) }}" wire:navigate class="text-sky-700 font-medium hover:underline">Все предложения →</a>
                                    </div>
                                @elseif($iqp && in_array($iqp->status, ['pending', 'queued', 'analyzing'], true))
                                    <div class="px-3.5 py-2.5 flex items-center justify-between text-[12px]">
                                        <span class="text-amber-700">{{ $iqp->statusEnum()?->label() ?? 'в очереди' }}…</span>
                                        @if($iqp->report_min_price !== null)
                                            <span class="text-fg-3 text-[11.5px]">прошлый: {{ number_format((float) $iqp->report_min_price, 2, ',', ' ') }} ₽</span>
                                        @endif
                                    </div>
                                @else
                                    <div class="px-3.5 py-2.5 flex items-center justify-between gap-2">
                                        <span class="text-[11.5px] text-fg-3">@if($iqp && $iqp->status === 'failed')Ошибка прошлого анализа@elseif($iqp && $iqp->status === 'excluded')Исключена из пула@else Цены конкурентов не анализировались@endif</span>
                                        <button type="button" wire:click="analyzeWithIqot({{ $cat->id }})" wire:loading.attr="disabled"
                                                class="inline-flex items-center gap-1 h-7 px-2.5 rounded-md bg-[var(--accent)] text-fg-on-accent text-[11.5px] font-medium whitespace-nowrap">
                                            IQOT — анализ
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- ─── Правая колонка: name h2 + name_en + kvgrid + descriptions/comments ─── --}}
                    <div>
                        <h2 class="m-0 mb-1 text-[16px] font-semibold text-fg-1 leading-snug" style="letter-spacing: -0.005em;">{{ $cat->name }}</h2>
                        @if($cat->name_en)
                            <div class="text-[12.5px] text-fg-3 italic mb-3.5">{{ $cat->name_en }}</div>
                        @else
                            <div class="mb-3"></div>
                        @endif

                        {{-- ─── kvgrid (single column, key=160px / value=1fr) ─── --}}
                        <div class="bg-surface border border-border rounded-md overflow-hidden">
                            @php
                                $kvRows = [
                                    ['SKU', '<span class="mono">' . e($cat->sku) . '</span>'],
                                    ['Primary бренд', $cat->brand ? e($cat->brand) : '<span class="text-fg-3 italic">— не указан</span>'],
                                    ['Primary артикул', $cat->brand_article
                                        ? '<span class="mono">' . e($cat->brand_article) . '</span>'
                                          . ($cat->brand_article_normalized && $cat->brand_article_normalized !== $cat->brand_article
                                            ? ' <span class="text-fg-3 text-[11px] mono">(норм: ' . e($cat->brand_article_normalized) . ')</span>'
                                            : '')
                                        : '<span class="text-fg-3 italic">— не указан</span>'],
                                    ['Узел', $cat->unit_name ? e($cat->unit_name) : '<span class="text-fg-3 italic">—</span>'],
                                    ['Размещение', $cat->placement ? e($cat->placement) : '<span class="text-fg-3 italic">—</span>'],
                                    ['Тип', $cat->part_type ? e($cat->part_type) : '<span class="text-fg-3 italic">—</span>'],
                                    ['Форм-фактор', $cat->form_factor ? '<span class="mono">' . e($cat->form_factor) . '</span>' : '<span class="text-fg-3 italic">—</span>'],
                                    ['Активна', $cat->is_active
                                        ? '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 font-medium text-[11.5px]">да</span>'
                                        : '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-neutral-100 text-fg-3 font-medium text-[11.5px]">нет (архив)</span>'],
                                ];

                                if (! empty($catDimsLabeled)) {
                                    $dimHtml = '';
                                    foreach ($catDimsLabeled as $k => $v) {
                                        $dimHtml .= '<span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded mono text-[11.5px] mr-1" style="background: #fef3c7; color: #92400e;">' . $k . ' ' . e($v) . '</span>';
                                    }
                                    $kvRows[] = ['Размеры (мм)', $dimHtml];
                                }
                                if ($cat->weight !== null) {
                                    $kvRows[] = ['Вес', '<span class="mono">' . e(rtrim(rtrim((string) $cat->weight, '0'), '.')) . ' кг</span>'];
                                }
                            @endphp

                            @foreach($kvRows as [$k, $v])
                                <div class="border-b border-border-subtle last:border-b-0"
                                     style="display: grid; align-items: baseline; grid-template-columns: 160px 1fr; gap: 10px; padding: 10px 14px;">
                                    <div class="text-[10px] font-semibold uppercase tracking-wider text-fg-3 pt-0.5">{{ $k }}</div>
                                    <div class="text-[13px] font-medium text-fg-1">{!! $v !!}</div>
                                </div>
                            @endforeach

                            {{-- Multi-brand row (если >1) --}}
                            @if(count($allBrands) > 1)
                                <div class="border-b border-border-subtle last:border-b-0"
                                     style="display: grid; align-items: baseline; grid-template-columns: 160px 1fr; gap: 10px; padding: 10px 14px;">
                                    <div class="text-[10px] font-semibold uppercase tracking-wider text-fg-3 pt-0.5">Все бренды ({{ count($allBrands) }})</div>
                                    <div class="text-[13px] font-medium text-fg-1 flex flex-wrap gap-1">
                                        @foreach($allBrands as $b)
                                            @php $isPrimary = is_string($cat->brand) && mb_strtolower(trim($b)) === mb_strtolower(trim($cat->brand)); @endphp
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[11.5px] {{ $isPrimary ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'bg-neutral-100 text-fg-2' }}">{{ $b }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- All OEM articles --}}
                            @if(! empty($allArticles))
                                <div class="border-b border-border-subtle last:border-b-0"
                                     style="display: grid; align-items: baseline; grid-template-columns: 160px 1fr; gap: 10px; padding: 10px 14px;">
                                    <div class="text-[10px] font-semibold uppercase tracking-wider text-fg-3 pt-0.5">OEM-артикулы ({{ count($allArticles) }})</div>
                                    <div class="text-[13px] font-medium text-fg-1 flex flex-wrap gap-1">
                                        @foreach($allArticles as $a)
                                            @php $isPrimary = is_string($cat->brand_article) && mb_strtolower(trim($a)) === mb_strtolower(trim($cat->brand_article)); @endphp
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded mono text-[11.5px] {{ $isPrimary ? 'bg-emerald-50 text-emerald-700 font-semibold' : '' }}"
                                                  @if(! $isPrimary) style="background: #f1eafe; color: #6d28d9;" @endif>{{ $a }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- All units (если >1) --}}
                            @if(count($allUnits) > 1)
                                <div class="border-b border-border-subtle last:border-b-0"
                                     style="display: grid; align-items: baseline; grid-template-columns: 160px 1fr; gap: 10px; padding: 10px 14px;">
                                    <div class="text-[10px] font-semibold uppercase tracking-wider text-fg-3 pt-0.5">Все узлы ({{ count($allUnits) }})</div>
                                    <div class="text-[13px] font-medium text-fg-1 flex flex-wrap gap-1">
                                        @foreach($allUnits as $u)
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-sky-50 text-sky-700 text-[11.5px]">{{ $u }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Description (отдельное поле от comment) --}}
                            @if($cat->description)
                                <div class="border-b border-border-subtle last:border-b-0"
                                     style="display: grid; align-items: baseline; grid-template-columns: 160px 1fr; gap: 10px; padding: 10px 14px;">
                                    <div class="text-[10px] font-semibold uppercase tracking-wider text-fg-3 pt-0.5">Описание</div>
                                    <div class="text-[12.5px] text-fg-2 whitespace-pre-line leading-relaxed">{{ $cat->description }}</div>
                                </div>
                            @endif

                            {{-- source_hash (для отладки импорта) --}}
                            @if($cat->source_hash)
                                <div style="display: grid; align-items: baseline; grid-template-columns: 160px 1fr; gap: 10px; padding: 10px 14px;">
                                    <div class="text-[10px] font-semibold uppercase tracking-wider text-fg-3 pt-0.5">Source hash</div>
                                    <div class="mono text-[11px] text-fg-3 break-all">{{ $cat->source_hash }}</div>
                                </div>
                            @endif
                        </div>

                        {{-- ─── Comments block (catalog_items.comment) ─── --}}
                        @if($cat->comment)
                            <div class="bg-surface border border-border rounded-md overflow-hidden mt-3">
                                <div class="px-3.5 py-2 bg-surface-2 border-b border-border-subtle text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold flex items-center gap-2">
                                    Комментарии
                                </div>
                                <div class="px-3.5 py-2.5 text-[12.5px] text-fg-1 whitespace-pre-line leading-relaxed">{{ $cat->comment }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endforeach

    {{-- ─── Footer ─── --}}
    <div class="flex items-center gap-3.5 px-4 bg-surface-2 border-t border-border text-[12px] font-medium text-fg-3"
         style="height: 42px; font-feature-settings: 'tnum';">
        <span>Показано <span class="text-fg-1 font-semibold">{{ $rows->count() }}</span> {{ $rows->count() === 1 ? 'позиция' : ($rows->count() < 5 ? 'позиции' : 'позиций') }}</span>
        <span class="text-[var(--border-strong)]">·</span>
        <span>top-50 по похожести</span>
    </div>

    {{-- ─── Hover-preview overlay ─── --}}
    <div x-show="show" x-cloak x-transition.opacity
         :style="`position: fixed; left: ${left}px; top: ${top}px; width: 480px; height: 480px; z-index: 9999; pointer-events: none;`"
         class="rounded-lg shadow-xl border border-border-subtle bg-white p-1">
        <img :src="url" alt="" referrerpolicy="no-referrer"
             x-on:error="closePreview()"
             style="width: 100%; height: 100%; object-fit: cover; border-radius: 4px;">
    </div>
</div>
