<div class="px-4 sm:px-6 py-4">
    {{-- ─── Header ─── --}}
    <div class="flex items-center gap-3 mb-3">
        <h1 class="text-[18px] font-semibold text-fg-1">Поиск по каталогу</h1>
        <span class="text-[12px] text-fg-3">
            Combo-поиск: точные коды (ILIKE) + текстовое совпадение (pg_trgm) + семантика (vector).
            Позиции, найденные несколькими способами, получают приоритет.
        </span>
    </div>

    {{-- ─── Search input ─── --}}
    <div class="ds-card p-3 mb-3">
        <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-fg-3 text-[14px] pointer-events-none select-none">⌕</span>
            <input type="search"
                   wire:model.live.debounce.400ms="query"
                   autofocus
                   placeholder="например: M02016, F0380CP3, OTIS, Ролик уравновешивания…"
                   class="w-full h-[40px] pl-9 pr-3 border border-border rounded-md bg-app text-[14px] outline-none focus:border-[var(--sky-500)] mono" />
            <span wire:loading wire:target="query,results,resultsBase"
                  class="absolute right-3 top-1/2 -translate-y-1/2 text-amber-700 text-[12px]">⏳ ищем…</span>
        </div>
    </div>

    @php
        $resultsBase = $this->resultsBase;
        $results     = $this->results;
        $kbCats      = $this->kbCategories;
        $units       = $this->availableUnits;
        $brands      = $this->availableBrands;
        $hasFilters  = $filterUnit !== null
                       || ! empty($filterBrands)
                       || $filterCategoryId !== null
                       || ! empty($filterDims);
    @endphp

    {{-- ─── Chip-filters (показываем только когда есть запрос) ─── --}}
    @if(mb_strlen(trim($query)) >= 2)
        <div class="ds-card p-3 mb-3">
            <div class="flex flex-wrap items-start gap-3">
                {{-- Узел --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="text-fg-3 uppercase tracking-wider text-[10px] font-semibold mr-1">Узел:</span>
                    @if(empty($units))
                        <span class="text-fg-3 text-[11.5px] italic">нет данных</span>
                    @else
                        @foreach($units as $unitName => $unitCount)
                            <button type="button"
                                    wire:click="toggleUnit(@js($unitName))"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-sm border text-[11.5px] {{ $filterUnit === $unitName ? 'bg-violet-100 border-violet-300 text-violet-900' : 'bg-surface-2 border-border text-fg-2 hover:bg-surface' }}"
                                    title="Показывать только каталог где unit_name = «{{ $unitName }}»">
                                <span class="text-[10px]">{{ $filterUnit === $unitName ? '✓' : '+' }}</span>
                                <span>{{ $unitName }}</span>
                                <span class="text-fg-3">({{ $unitCount }})</span>
                            </button>
                        @endforeach
                    @endif
                </div>

                <div class="w-px self-stretch bg-border-subtle"></div>

                {{-- Бренд (multi-select) --}}
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="text-fg-3 uppercase tracking-wider text-[10px] font-semibold mr-1">Бренд:</span>
                    @if(empty($brands))
                        <span class="text-fg-3 text-[11.5px] italic">нет данных</span>
                    @else
                        @foreach($brands as $brandName => $brandCount)
                            @php $isActive = in_array($brandName, $filterBrands, true); @endphp
                            <button type="button"
                                    wire:click="toggleBrand(@js($brandName))"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-sm border text-[11.5px] {{ $isActive ? 'bg-sky-100 border-sky-300 text-sky-900' : 'bg-surface-2 border-border text-fg-2 hover:bg-surface' }}"
                                    title="Показывать каталог с primary brand или brands[] = «{{ $brandName }}»">
                                <span class="text-[10px]">{{ $isActive ? '✓' : '+' }}</span>
                                <span class="font-semibold">{{ $brandName }}</span>
                                <span class="text-fg-3">({{ $brandCount }})</span>
                            </button>
                        @endforeach
                    @endif
                </div>

                <div class="w-px self-stretch bg-border-subtle"></div>

                {{-- Категория KB --}}
                <div class="flex items-center gap-1.5">
                    <span class="text-fg-3 uppercase tracking-wider text-[10px] font-semibold mr-1">Категория:</span>
                    <select wire:model.live="filterCategoryId"
                            class="h-[26px] px-2 border border-border rounded-md bg-app text-[12px] outline-none focus:border-[var(--sky-500)]">
                        <option value="">— любая —</option>
                        @foreach($kbCats as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @if($filterCategoryId !== null)
                        <button type="button" wire:click="setCategory(null)"
                                class="text-fg-3 hover:text-red-700 text-[12px]"
                                title="Снять фильтр категории">✕</button>
                    @endif
                </div>

                <div class="w-px self-stretch bg-border-subtle"></div>

                {{-- Размеры --}}
                <div class="flex items-center flex-wrap gap-1.5">
                    <span class="text-fg-3 uppercase tracking-wider text-[10px] font-semibold mr-1">Размер, мм:</span>
                    @foreach($filterDims as $dim)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-sm border bg-amber-100 border-amber-300 text-amber-900 text-[11.5px] mono">
                            {{ $dim }}
                            <button type="button" wire:click="removeDim({{ $dim }})"
                                    class="text-amber-700 hover:text-red-700 text-[12px]"
                                    title="Убрать">✕</button>
                        </span>
                    @endforeach
                    <form wire:submit.prevent="addDim" class="flex items-center gap-1">
                        <input type="number" min="1" max="100000" step="1"
                               wire:model="newDim"
                               placeholder="напр. 1700"
                               class="w-[88px] h-[26px] px-2 border border-border rounded-md bg-app text-[12px] outline-none focus:border-[var(--sky-500)] mono" />
                        <button type="submit"
                                class="px-2 h-[26px] border border-border rounded-md bg-surface-2 text-fg-2 text-[11.5px] hover:bg-surface">
                            + добавить
                        </button>
                    </form>
                    @if(! empty($filterDims))
                        <span class="text-fg-3 text-[10.5px]">±5 мм</span>
                    @endif
                </div>

                @if($hasFilters)
                    <span class="flex-1"></span>
                    <button type="button" wire:click="clearFilters"
                            class="text-fg-3 hover:text-red-700 text-[12px]"
                            title="Сбросить все фильтры">
                        ✕ сбросить фильтры
                    </button>
                @endif
            </div>
        </div>
    @endif

    {{-- ─── Results table ─── --}}
    @if(mb_strlen(trim($query)) < 2)
        <div class="ds-card p-8 text-center text-fg-3 text-[13px]">
            Введите минимум 2 символа для поиска.<br>
            <span class="text-[11.5px] text-fg-3">
                Поддерживаются артикулы (M02016, F0380CP3), OEM-коды, названия и фрагменты фраз («Башмак кабины Otis»).
            </span>
        </div>
    @elseif(empty($resultsBase))
        <div class="ds-card p-8 text-center text-fg-3 text-[13px]"
             wire:loading.remove wire:target="query,results,resultsBase">
            Ничего не найдено. Попробуйте другой запрос или сократите его до ключевого слова / артикула.
        </div>
    @elseif(empty($results))
        <div class="ds-card p-8 text-center text-fg-3 text-[13px]">
            Все {{ count($resultsBase) }} кандидатов отфильтрованы chip'ами выше.
            <button type="button" wire:click="clearFilters" class="text-sky-700 hover:text-sky-900 underline">
                сбросить фильтры
            </button>
        </div>
    @else
        <div class="ds-card p-0 overflow-hidden">
            <div class="px-3 py-2 border-b border-border-subtle bg-surface-2 text-[11.5px] text-fg-3 flex items-center gap-2">
                <span>Найдено: <span class="font-semibold text-fg-1">{{ count($results) }}</span> из {{ count($resultsBase) }}</span>
                <span>·</span>
                <span class="flex items-center gap-1">
                    <span>🔀 multi</span><span class="text-fg-3">/</span>
                    <span>🎯 code</span><span class="text-fg-3">/</span>
                    <span>🔤 trgm</span><span class="text-fg-3">/</span>
                    <span>✨ vector</span>
                </span>
            </div>
            @include('livewire.catalog._search-results-table', ['rows' => collect($results)])
        </div>
    @endif
</div>
