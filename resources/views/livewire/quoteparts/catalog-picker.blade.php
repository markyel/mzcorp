{{-- Запрос расценки на ЛЮБЫЕ позиции каталога — как в «Снабжении»; позиции уходят в панель «Запрос поставщикам». --}}
<div class="ds-card" x-data="{ open: @js(trim($catalogSearch) !== '' || $skuListReport !== null) }">
    <div class="ds-card-header cursor-pointer select-none" @click="open = !open">
        <h3>🔎 Запросить расценку на позиции каталога</h3>
        <span class="text-[12px] text-fg-3 ml-2">любая позиция — поиском или списком артикулов</span>
        <span class="flex-1"></span>
        <span class="text-[12px] text-fg-3" x-text="open ? '▾ свернуть' : '▸ развернуть'"></span>
    </div>
    <div class="ds-card-body" x-show="open" x-cloak>
        <input type="search" wire:model.live.debounce.300ms="catalogSearch"
               placeholder="Артикул, название или бренд…"
               class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
        @if(trim($catalogSearch) !== '')
            <div class="mt-2 border border-border-subtle rounded-md divide-y divide-border-subtle max-h-72 overflow-y-auto">
                @forelse($this->catalogSearchResults as $ci)
                    <div class="flex items-center gap-2 px-3 py-2 text-[12.5px]" wire:key="qp-csr-{{ $ci->id }}">
                        <div class="flex-1 min-w-0">
                            <span class="mono text-fg-3">{{ $ci->sku }}</span>
                            <span class="text-fg-1">· {{ $ci->name }}</span>
                            @if($ci->brand)<span class="text-fg-4">· {{ $ci->brand }}</span>@endif
                        </div>
                        <button type="button" wire:click="addCatalogItem({{ $ci->id }})" class="btn btn-sm btn-primary shrink-0">+ В запрос</button>
                    </div>
                @empty
                    <div class="px-3 py-2 text-[12px] text-fg-4">Ничего не найдено.</div>
                @endforelse
            </div>
        @endif
        <details class="mt-3" @if($skuListReport && $skuListReport['missing'] !== []) open @endif>
            <summary class="text-[12.5px] text-sky-700 cursor-pointer select-none">Добавить списком артикулов</summary>
            <div class="mt-2">
                <textarea wire:model="skuList" rows="4"
                          placeholder="M05333&#10;M26889, M00171&#10;— столбец из Excel, через запятую или пробел"
                          class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] mono outline-none focus:border-sky-500"></textarea>
                <div class="flex items-center gap-2 mt-1.5">
                    <button type="button" wire:click="addCatalogSkus" wire:loading.attr="disabled" wire:target="addCatalogSkus" class="btn btn-sm btn-primary">
                        <span wire:loading.remove wire:target="addCatalogSkus">Найти и добавить</span>
                        <span wire:loading wire:target="addCatalogSkus">Ищу…</span>
                    </button>
                    <span class="text-[11.5px] text-fg-3">берём все M-артикулы из текста, остальное игнорируем</span>
                </div>
                @if($skuListReport)
                    <div class="text-[12px] mt-1.5">
                        @if($skuListReport['empty'])
                            <span class="text-amber-700">В тексте нет M-артикулов.</span>
                        @else
                            <span class="text-emerald-700">Добавлено в запрос: {{ $skuListReport['added'] }}</span>
                            @if($skuListReport['missing'] !== [])
                                <div class="text-red-700 mt-0.5">Нет в каталоге ({{ count($skuListReport['missing']) }}): <span class="mono">{{ implode(', ', $skuListReport['missing']) }}</span> — оставлены в поле, проверьте написание.</div>
                            @endif
                        @endif
                    </div>
                @endif
            </div>
        </details>
        <div class="text-[11.5px] text-fg-4 mt-2">Добавленные позиции появятся в окне «Запрос поставщикам» внизу страницы.</div>
    </div>
</div>
