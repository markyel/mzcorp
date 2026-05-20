{{-- Layout: rail (56px) + main. Без list-nav (240px) как в Pool —
     для каталога нет saved views / queue navigation.
     Макет: design/uploads/07-catalog-search.html. --}}
<div class="grid"
     style="grid-template-columns: 56px 1fr; min-height: calc(100vh - var(--topbar-h));">

    <x-left-rail active="catalog" />

    <section class="bg-app flex flex-col min-w-0">
    <div class="mx-auto w-full" style="max-width: 1440px; padding: 18px 24px 48px;">

    {{-- ─── Page header ─── --}}
    <div class="flex items-end gap-4 mb-3">
        <div>
            <h1 class="m-0 text-[20px] font-semibold text-fg-1 leading-tight">Поиск по каталогу</h1>
            <div class="text-[12.5px] text-fg-3 mt-1 max-w-[780px]">
                Combo-поиск: точные коды
                <code class="mono text-[11.5px] bg-surface px-1.5 py-px border border-border rounded text-fg-2">ILIKE</code>
                + текстовое совпадение
                <code class="mono text-[11.5px] bg-surface px-1.5 py-px border border-border rounded text-fg-2">pg_trgm</code>
                + семантика
                <code class="mono text-[11.5px] bg-surface px-1.5 py-px border border-border rounded text-fg-2">vector</code>.
                Позиции, найденные несколькими способами, получают приоритет.
            </div>
        </div>
    </div>

    {{-- ─── Search bar (grid 1fr auto auto) ─── --}}
    <div class="bg-surface border border-border rounded-md p-2 mb-3 grid items-center gap-2.5"
         style="grid-template-columns: 1fr auto auto;">
        <div class="relative">
            <span class="absolute left-1.5 top-1/2 -translate-y-1/2 text-fg-3 text-[14px] pointer-events-none select-none">⌕</span>
            <input type="search"
                   wire:model.live.debounce.400ms="query"
                   autofocus
                   placeholder="Артикул, OEM-код, название, фрагмент описания…"
                   class="w-full h-9 pl-7 pr-2 border-none outline-none bg-transparent text-[14px] font-medium mono text-fg-1" />
        </div>
        <div class="flex items-center gap-1 pr-2 border-r border-border-subtle text-[11px] text-fg-3 h-6">
            <span class="text-[11px] text-fg-3 mr-1">источники:</span>
            <span class="inline-flex items-center gap-1 h-5 px-2 rounded-full bg-surface-2 text-fg-2 text-[11px] font-medium">🔀 multi</span>
            <span class="inline-flex items-center gap-1 h-5 px-2 rounded-full bg-surface-2 text-fg-2 text-[11px] font-medium">🎯 code</span>
            <span class="inline-flex items-center gap-1 h-5 px-2 rounded-full bg-surface-2 text-fg-2 text-[11px] font-medium">🔤 trgm</span>
            <span class="inline-flex items-center gap-1 h-5 px-2 rounded-full bg-surface-2 text-fg-2 text-[11px] font-medium">✨ vector</span>
        </div>
        <div class="flex items-center gap-1.5">
            @if(mb_strlen(trim($query)) >= 1)
                <button type="button" wire:click="$set('query', '')"
                        class="btn btn-sm">Очистить</button>
            @endif
            <span wire:loading wire:target="query,results,resultsBase"
                  class="text-amber-700 text-[12px] px-2">⏳ ищем…</span>
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

    {{-- ─── Facets ─── --}}
    @if(mb_strlen(trim($query)) >= 2)
        <div class="bg-surface border border-border rounded-md p-3 mb-3 flex flex-col gap-2">
            {{-- Узел --}}
            <div class="grid items-start gap-3.5" style="grid-template-columns: 92px 1fr;">
                <span class="text-[10.5px] font-semibold uppercase tracking-wider text-fg-3 pt-1">Узел</span>
                <div class="flex items-center gap-1.5 flex-wrap">
                    @if(empty($units))
                        <span class="text-fg-3 text-[11.5px] italic">нет данных</span>
                    @else
                        @foreach($units as $unitName => $unitCount)
                            @php $isActive = $filterUnit === $unitName; @endphp
                            <button type="button"
                                    wire:click="toggleUnit(@js($unitName))"
                                    class="inline-flex items-center gap-1.5 h-[26px] px-2.5 rounded-full border text-[12px] font-medium whitespace-nowrap {{ $isActive ? 'bg-sky-50 border-sky-500 text-sky-700' : 'bg-surface border-[var(--border-strong)] text-fg-2 hover:bg-[var(--bg-hover)] hover:border-[var(--neutral-400)]' }}"
                                    title="Показывать только каталог где unit_name = «{{ $unitName }}»">
                                <span>{{ $unitName }}</span>
                                <span class="mono text-[11px] {{ $isActive ? 'text-sky-700' : 'text-fg-3' }}">{{ $unitCount }}</span>
                                @if($isActive)<span class="text-sky-700 opacity-70">×</span>@endif
                            </button>
                        @endforeach
                    @endif
                </div>
            </div>

            {{-- Бренд --}}
            <div class="grid items-start gap-3.5 pt-2 border-t border-border-subtle" style="grid-template-columns: 92px 1fr;">
                <span class="text-[10.5px] font-semibold uppercase tracking-wider text-fg-3 pt-1">Бренд</span>
                <div class="flex items-center gap-1.5 flex-wrap">
                    @if(empty($brands))
                        <span class="text-fg-3 text-[11.5px] italic">нет данных</span>
                    @else
                        @foreach($brands as $brandName => $brandCount)
                            @php $isActive = in_array($brandName, $filterBrands, true); @endphp
                            <button type="button"
                                    wire:click="toggleBrand(@js($brandName))"
                                    class="inline-flex items-center gap-1.5 h-[26px] px-2.5 rounded-full border text-[12px] font-medium whitespace-nowrap {{ $isActive ? 'bg-sky-50 border-sky-500 text-sky-700' : 'bg-surface border-[var(--border-strong)] text-fg-2 hover:bg-[var(--bg-hover)] hover:border-[var(--neutral-400)]' }}"
                                    title="Показывать каталог с brand или brands[] = «{{ $brandName }}»">
                                <span>{{ $brandName }}</span>
                                <span class="mono text-[11px] {{ $isActive ? 'text-sky-700' : 'text-fg-3' }}">{{ $brandCount }}</span>
                                @if($isActive)<span class="text-sky-700 opacity-70">×</span>@endif
                            </button>
                        @endforeach
                    @endif
                </div>
            </div>

            {{-- Категория KB --}}
            <div class="grid items-center gap-3.5 pt-2 border-t border-border-subtle" style="grid-template-columns: 92px 1fr;">
                <span class="text-[10.5px] font-semibold uppercase tracking-wider text-fg-3">Категория</span>
                <div class="flex items-center gap-1.5">
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
            </div>

            {{-- Размер --}}
            <div class="grid items-center gap-3.5 pt-2 border-t border-border-subtle" style="grid-template-columns: 92px 1fr;">
                <span class="text-[10.5px] font-semibold uppercase tracking-wider text-fg-3">Размер</span>
                <div class="flex items-center gap-2 flex-wrap">
                    @if(empty($filterDims))
                        <span class="inline-flex items-center h-[26px] px-2.5 rounded-full border border-[var(--border-strong)] bg-surface text-fg-3 text-[12px] font-medium">— любое</span>
                        <span class="text-fg-3 text-[11.5px]">или указать:</span>
                    @else
                        @foreach($filterDims as $dim)
                            <span class="inline-flex items-center gap-1 h-[26px] px-2.5 rounded-full border bg-amber-50 border-amber-300 text-amber-800 text-[12px] mono">
                                {{ $dim }} мм
                                <button type="button" wire:click="removeDim({{ $dim }})"
                                        class="text-amber-700 hover:text-red-700 text-[12px] ml-0.5"
                                        title="Убрать">✕</button>
                            </span>
                        @endforeach
                        <span class="text-fg-3 text-[10.5px]">±5 мм</span>
                        <span class="text-fg-3 text-[11.5px]">+ ещё:</span>
                    @endif
                    <form wire:submit.prevent="addDim" class="inline-flex items-center h-7 border border-[var(--border-strong)] rounded-md overflow-hidden bg-surface">
                        <input type="number" min="1" max="100000" step="1"
                               wire:model="newDim"
                               placeholder="напр. 170"
                               class="border-none outline-none bg-transparent h-full w-[80px] px-2 text-[12.5px] mono text-fg-1" />
                        <span class="px-2.5 bg-surface-2 text-fg-3 text-[11px] font-medium h-full flex items-center border-l border-border-subtle">мм</span>
                        <button type="submit"
                                class="px-2.5 bg-app text-sky-700 text-[11.5px] font-medium h-full flex items-center border-l border-border-subtle hover:bg-surface">
                            + добавить
                        </button>
                    </form>
                </div>
            </div>

            @if($hasFilters)
                <div class="flex justify-end pt-2 border-t border-border-subtle">
                    <button type="button" wire:click="clearFilters"
                            class="text-fg-3 hover:text-red-700 text-[12px]"
                            title="Сбросить все фильтры">
                        ✕ сбросить все фильтры
                    </button>
                </div>
            @endif
        </div>
    @endif

    {{-- ─── Meta row ─── --}}
    @if(mb_strlen(trim($query)) >= 2 && ! empty($resultsBase))
        <div class="flex items-center gap-3.5 px-1 mb-2.5 text-[12.5px] text-fg-3">
            <span>Найдено: <span class="font-semibold text-fg-1">{{ count($results) }}</span> из {{ count($resultsBase) }}</span>
            <span class="text-[var(--border-strong)]">·</span>
            <span>сортировка: <b class="text-fg-1 font-medium">похожесть ↓</b></span>
        </div>
    @endif

    {{-- ─── Results ─── --}}
    @if(mb_strlen(trim($query)) < 2)
        <div class="bg-surface border border-border rounded-md p-8 text-center text-fg-3 text-[13px]">
            Введите минимум 2 символа для поиска.<br>
            <span class="text-[11.5px] text-fg-3">
                Поддерживаются артикулы (M02016, F0380CP3), OEM-коды, названия и фрагменты фраз («Башмак кабины Otis»).
            </span>
        </div>
    @elseif(empty($resultsBase))
        <div class="bg-surface border border-border rounded-md p-8 text-center text-fg-3 text-[13px]"
             wire:loading.remove wire:target="query,results,resultsBase">
            Ничего не найдено. Попробуйте другой запрос или сократите его до ключевого слова / артикула.
        </div>
    @elseif(empty($results))
        <div class="bg-surface border border-border rounded-md p-8 text-center text-fg-3 text-[13px]">
            Все {{ count($resultsBase) }} кандидатов отфильтрованы chip'ами выше.
            <button type="button" wire:click="clearFilters" class="text-sky-700 hover:text-sky-900 underline">сбросить фильтры</button>
        </div>
    @else
        @include('livewire.catalog._search-results-table', ['rows' => collect($results)])
    @endif

    </div>
    </section>
</div>
