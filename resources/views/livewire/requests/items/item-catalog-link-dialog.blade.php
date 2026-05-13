<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: flex-start; justify-content: center; padding: 60px 24px 24px;"
             wire:click.self="close">
            <div class="ds-card p-5 w-full max-w-[760px] max-h-[80vh] flex flex-col" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-1">
                    Привязать позицию к каталогу
                </h3>
                <div class="text-[12px] text-fg-3 mb-3">
                    Найдите подходящий товар каталога и нажмите «Привязать».
                </div>

                {{-- Tabs --}}
                <div class="flex gap-1 mb-3 border-b border-border-subtle">
                    <button type="button" wire:click="setMode('text')"
                            class="px-3 py-1.5 text-[12px] font-medium border-b-2 {{ $mode === 'text' ? 'border-[var(--accent)] text-fg-1' : 'border-transparent text-fg-3 hover:text-fg-1' }}">
                        🔎 По тексту
                    </button>
                    <button type="button" wire:click="setMode('similar')"
                            class="px-3 py-1.5 text-[12px] font-medium border-b-2 {{ $mode === 'similar' ? 'border-[var(--accent)] text-fg-1' : 'border-transparent text-fg-3 hover:text-fg-1' }}">
                        ✨ Похожие из каталога
                    </button>
                </div>

                @if($mode === 'text')
                    <input type="text" wire:model.live.debounce.300ms="query"
                           autofocus
                           placeholder="например: M02016 или 3RT2016 или Кнопка вызывная"
                           class="w-full h-[36px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono mb-3" />

                    @error('query') <div class="text-red-700 text-[12px] mb-2">{{ $message }}</div> @enderror

                    @php $results = $this->textResults; @endphp

                    <div class="flex-1 overflow-auto border border-border-subtle rounded-md">
                        @if(mb_strlen(trim($query)) < 2)
                            <div class="px-3 py-6 text-center text-fg-3 text-[12px]">
                                Введите минимум 2 символа для поиска.
                            </div>
                        @elseif($results->isEmpty())
                            <div class="px-3 py-6 text-center text-fg-3 text-[12px]">
                                Ничего не найдено. Попробуйте «Похожие из каталога».
                            </div>
                        @else
                            @include('livewire.requests.items._catalog-results-table', [
                                'rows' => $results->map(fn ($c) => ['catalog' => $c, 'similarity' => null]),
                                'selectedId' => $selectedCatalogId,
                            ])
                        @endif
                    </div>
                @else
                    {{-- similar mode --}}
                    <div class="text-[11.5px] text-fg-3 mb-2 flex items-center gap-2">
                        <span>Vector-поиск по KB-эмбеддингам, top-10 по убыванию похожести.</span>
                        <span wire:loading wire:target="similarResults,setMode" class="text-amber-700">⏳ ищем…</span>
                    </div>

                    @php $simResults = $this->similarResults; @endphp

                    <div class="flex-1 overflow-auto border border-border-subtle rounded-md">
                        @if(empty($simResults))
                            <div class="px-3 py-6 text-center text-fg-3 text-[12px]"
                                 wire:loading.remove wire:target="similarResults,setMode">
                                Не удалось получить похожие позиции (возможно, у позиции пусто название/бренд, либо
                                эмбеддинг-сервис недоступен).
                            </div>
                        @else
                            @include('livewire.requests.items._catalog-results-table', [
                                'rows' => collect($simResults),
                                'selectedId' => $selectedCatalogId,
                            ])
                        @endif
                    </div>
                @endif

                <div class="flex items-center gap-2 pt-3 mt-3 border-t border-border-subtle">
                    <button type="button" wire:click="save" class="btn btn-primary"
                            @if(! $selectedCatalogId) disabled @endif
                            wire:loading.attr="disabled" wire:target="save">
                        Привязать
                    </button>
                    <button type="button" wire:click="close" class="btn">Отмена</button>
                    <span class="flex-1"></span>
                    @if($selectedCatalogId)
                        <span class="text-[12px] text-fg-3">
                            Выбран: <span class="mono text-fg-1">#{{ $selectedCatalogId }}</span>
                        </span>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
