<div>
    @if($open && $this->catalogItem)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: flex-start; justify-content: center; padding: 32px 16px; overflow-y: auto;"
             wire:click.self="close">
            <div class="ds-card w-full" style="max-width: 920px;" wire:click.stop>
                <div class="ds-card-header">
                    <h3 class="text-[15px] font-semibold text-fg-1">
                        Каталог · <span class="mono">{{ $this->catalogItem->sku }}</span>
                    </h3>
                    <span class="flex-1"></span>
                    <a href="{{ route('catalog.search', ['q' => $this->catalogItem->sku]) }}" target="_blank" rel="noopener"
                       class="btn btn-sm" title="Открыть в разделе каталога">↗ В каталоге</a>
                    <button type="button" wire:click="close" class="btn btn-sm ml-1" title="Закрыть">✕ Закрыть</button>
                </div>
                <div class="ds-card-body">
                    @include('livewire.catalog._catalog-item-detail', [
                        'cat' => $this->catalogItem,
                        'pc' => $this->lastPriceChange,
                        'iqp' => $this->iqotPosition,
                        'canIqot' => $this->canIqot,
                        'allowIqotAnalyze' => false,
                    ])
                </div>
            </div>
        </div>
    @endif
</div>
