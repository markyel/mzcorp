{{-- Partial: таблица результатов каталога для text / similar вкладок
     ItemCatalogLinkDialog. Ожидает:
       $rows — Collection<array{catalog: CatalogItem, similarity: ?float}>
       $selectedId — ?int (id выбранной строки для подсветки) --}}
<table class="w-full text-[12px]">
    <thead class="bg-surface-2 text-fg-3 uppercase tracking-wider text-[10.5px] sticky top-0">
        <tr>
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
                <td class="px-2 py-1.5 mono text-fg-1 align-top whitespace-nowrap">{{ $cat->sku }}</td>
                <td class="px-2 py-1.5 align-top whitespace-nowrap">
                    <div class="text-fg-1">{{ $cat->brand ?: '—' }}</div>
                    @if($cat->brand_article)
                        <div class="mono text-fg-3 text-[11px]">{{ $cat->brand_article }}</div>
                    @endif
                </td>
                <td class="px-2 py-1.5 text-fg-1 align-top leading-snug">{{ $cat->name }}</td>
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
