{{-- Полная карточка товара каталога (двухколоночная деталь).
     Общий партиал: используется в раскрытой строке поиска каталога
     (_search-results-table) И в модалке CatalogItemDialog (из позиций заявки).

     Ожидает:
       $cat              — App\Models\CatalogItem
       $pc               — ?App\Models\CatalogPriceChange (последнее изменение цены)
       $iqp              — ?App\Models\IqotPosition (свежий отчёт IQOT), опц.
       $canIqot          — bool (показывать IQOT-блок), опц.
       $allowIqotAnalyze — bool (кнопка «IQOT — анализ», только где есть метод
                           analyzeWithIqot; в модалке-квиквью — false), опц.
--}}
@php
    $canIqot = $canIqot ?? false;
    $allowIqotAnalyze = $allowIqotAnalyze ?? false;

    $allBrands = is_array($cat->brands) ? array_values(array_filter($cat->brands, fn ($b) => is_string($b) && trim($b) !== '')) : [];
    $allArticles = is_array($cat->articles) ? array_values(array_filter($cat->articles, fn ($a) => is_string($a) && trim($a) !== '')) : [];
    $allUnits = is_array($cat->units) ? array_values(array_filter($cat->units, fn ($u) => is_string($u) && trim($u) !== '')) : [];

    $catDimsLabeled = [];
    foreach (['A','B','C','D','E','F'] as $k) {
        $v = $cat->{'size_' . strtolower($k)};
        if ($v !== null && (float) $v > 0) {
            $catDimsLabeled[$k] = rtrim(rtrim((string) $v, '0'), '.');
        }
    }
@endphp

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
            <a href="https://www.mylift.ru/index.php?code={{ urlencode($cat->sku) }}&fn=view"
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
            <div class="flex justify-between items-baseline px-3.5 py-2 border-b border-border-subtle text-[12.5px]" title="Закупочная цена. Внутренняя информация — не для клиента.">
                <div class="text-fg-3 text-[12px] font-medium">Себестоимость</div>
                <div class="mono text-[13px] text-fg-1" style="font-feature-settings: 'tnum';">
                    {{ $cat->purchase_price !== null ? number_format((float) $cat->purchase_price, 2, ',', ' ') . ' ₽' : '—' }}
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
            @if(!empty($cat->stock_in_transit))
                <div class="flex justify-between items-start gap-3 px-3.5 py-2 border-b border-border-subtle text-[12.5px]" title="Свободный остаток в пути с датами прихода">
                    <div class="text-fg-3 text-[12px] font-medium shrink-0">В пути</div>
                    <div class="text-[12.5px] text-right space-y-0.5">
                        @foreach($cat->stock_in_transit as $lot)
                            <div class="whitespace-nowrap">
                                <span class="font-semibold text-sky-700">{{ $lot['qty'] }} шт</span>
                                <span class="text-fg-3">к {{ \Illuminate\Support\Carbon::parse($lot['date'])->format('d.m.Y') }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

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

        {{-- IQOT · анализ цен конкурентов --}}
        @if($canIqot)
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
                        @if($allowIqotAnalyze)
                            <button type="button" wire:click="analyzeWithIqot({{ $cat->id }})" wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1 h-7 px-2.5 rounded-md bg-[var(--accent)] text-fg-on-accent text-[11.5px] font-medium whitespace-nowrap">
                                IQOT — анализ
                            </button>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- ─── Правая колонка: name + name_en + kvgrid + descriptions/comments ─── --}}
    <div>
        <h2 class="m-0 mb-1 text-[16px] font-semibold text-fg-1 leading-snug" style="letter-spacing: -0.005em;">{{ $cat->name }}</h2>
        @if($cat->name_en)
            <div class="text-[12.5px] text-fg-3 italic mb-3.5">{{ $cat->name_en }}</div>
        @else
            <div class="mb-3"></div>
        @endif

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

            @if($cat->description)
                <div class="border-b border-border-subtle last:border-b-0"
                     style="display: grid; align-items: baseline; grid-template-columns: 160px 1fr; gap: 10px; padding: 10px 14px;">
                    <div class="text-[10px] font-semibold uppercase tracking-wider text-fg-3 pt-0.5">Описание</div>
                    <div class="text-[12.5px] text-fg-2 whitespace-pre-line leading-relaxed">{{ $cat->description }}</div>
                </div>
            @endif
        </div>

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
