<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: flex-start; justify-content: center; padding: 60px 24px 24px;"
             wire:click.self="close">
            <div class="ds-card p-5 w-full max-w-[720px] max-h-[80vh] flex flex-col" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-1">
                    Привязать позицию к каталогу
                </h3>
                <div class="text-[12px] text-fg-3 mb-3">
                    Поиск по SKU, артикулу бренда или названию.
                </div>

                <input type="text" wire:model.live.debounce.300ms="query"
                       autofocus
                       placeholder="например: M02016 или 3RT2016 или Кнопка вызывная"
                       class="w-full h-[36px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono mb-3" />

                @error('query') <div class="text-red-700 text-[12px] mb-2">{{ $message }}</div> @enderror

                @php $results = $this->results; @endphp

                <div class="flex-1 overflow-auto border border-border-subtle rounded-md">
                    @if(mb_strlen(trim($query)) < 2)
                        <div class="px-3 py-6 text-center text-fg-3 text-[12px]">
                            Введите минимум 2 символа для поиска.
                        </div>
                    @elseif($results->isEmpty())
                        <div class="px-3 py-6 text-center text-fg-3 text-[12px]">
                            Ничего не найдено. Попробуйте другой запрос.
                        </div>
                    @else
                        <table class="w-full text-[12px]">
                            <thead class="bg-surface-2 text-fg-3 uppercase tracking-wider text-[10.5px] sticky top-0">
                                <tr>
                                    <th class="px-2 py-1.5 text-left">SKU</th>
                                    <th class="px-2 py-1.5 text-left">Бренд / артикул</th>
                                    <th class="px-2 py-1.5 text-left">Название</th>
                                    <th class="px-2 py-1.5 text-right">Цена</th>
                                    <th class="px-2 py-1.5 text-right">Наличие</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($results as $cat)
                                    <tr wire:key="cat-{{ $cat->id }}"
                                        wire:click="selectCatalog({{ $cat->id }})"
                                        class="cursor-pointer border-b border-border-subtle last:border-b-0 {{ $selectedCatalogId === $cat->id ? 'bg-sky-50' : 'hover:bg-surface-2' }} {{ $cat->is_active ? '' : 'opacity-60' }}">
                                        <td class="px-2 py-1.5 mono text-fg-1">{{ $cat->sku }}</td>
                                        <td class="px-2 py-1.5">
                                            <div class="text-fg-1">{{ $cat->brand ?: '—' }}</div>
                                            @if($cat->brand_article)
                                                <div class="mono text-fg-3 text-[11px]">{{ $cat->brand_article }}</div>
                                            @endif
                                        </td>
                                        <td class="px-2 py-1.5 text-fg-1 max-w-[260px] truncate" title="{{ $cat->name }}">{{ $cat->name }}</td>
                                        <td class="px-2 py-1.5 mono text-right text-fg-1">
                                            {{ $cat->price !== null ? number_format((float) $cat->price, 2, '.', ' ') . ' ₽' : '—' }}
                                        </td>
                                        <td class="px-2 py-1.5 text-right">
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
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>

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
                            Выбран: <span class="mono text-fg-1">
                                {{ $results->firstWhere('id', $selectedCatalogId)?->sku ?? '#' . $selectedCatalogId }}
                            </span>
                        </span>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
