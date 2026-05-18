@php
    $items = $this->items;
    $qi = $this->quoteItem;
@endphp

<div>
    @if($open)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:click.self="close">
            <div class="ds-card p-5 w-full max-w-[680px] max-h-[85vh] overflow-y-auto" wire:click.stop>
                <h3 class="text-[15px] font-semibold text-fg-1 mb-1">
                    Привязать строку КП к позиции заявки
                </h3>

                @if($qi)
                    <div class="text-[12px] text-fg-3 mb-3 leading-snug">
                        <span class="text-fg-1">{{ $qi->raw_name }}</span>
                        <div class="flex flex-wrap gap-x-2 gap-y-0.5 mt-0.5">
                            @if($qi->raw_article)
                                <span class="mono">{{ $qi->raw_article }}</span>
                            @endif
                            @if($qi->raw_brand)
                                <span>{{ $qi->raw_brand }}</span>
                            @endif
                            @if($qi->catalogItem)
                                <span class="inline-block px-1.5 py-0.5 rounded border bg-emerald-50 text-emerald-700 border-emerald-200">
                                    📦 каталог: <span class="mono">{{ $qi->catalogItem->sku }}</span>
                                </span>
                            @endif
                            <span class="mono">{{ $qi->quantity }} {{ $qi->unit_measure ?: 'шт.' }} × {{ number_format((float) $qi->unit_price, 2, '.', ' ') }} ₽</span>
                            <span class="font-semibold mono">= {{ number_format((float) $qi->line_total, 2, '.', ' ') }} ₽</span>
                        </div>
                    </div>
                @endif

                <form wire:submit="save" class="space-y-3">
                    <div>
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Поиск по позициям заявки</label>
                        <input wire:model.live.debounce.250ms="search" type="text"
                               placeholder="название / артикул / бренд"
                               class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]">
                    </div>

                    <div class="border border-border rounded-md overflow-hidden">
                        @if($items->isEmpty())
                            <div class="px-3 py-6 text-center text-fg-3 text-sm">
                                @if($search !== '')
                                    Ничего не найдено по запросу «{{ $search }}». <button type="button" wire:click="$set('search', '')" class="text-sky-700 hover:underline">сбросить</button>
                                @else
                                    В заявке нет активных позиций для привязки.
                                @endif
                            </div>
                        @else
                            <div class="divide-y divide-[var(--border-subtle)] max-h-[40vh] overflow-y-auto">
                                @foreach($items as $ri)
                                    @php $isSelected = $selectedRequestItemId === $ri['id']; @endphp
                                    <label class="flex items-start gap-3 px-3 py-2.5 cursor-pointer transition-colors {{ $isSelected ? 'bg-sky-50' : 'hover:bg-hover' }}">
                                        <input type="radio"
                                               wire:model.live="selectedRequestItemId"
                                               value="{{ $ri['id'] }}"
                                               class="mt-1 shrink-0 cursor-pointer">
                                        <div class="flex-1 min-w-0">
                                            <div class="text-fg-1 text-[13px]">
                                                <span class="text-fg-3 mono">№{{ $ri['position'] ?? '?' }}</span>
                                                · {{ $ri['name'] ?: '(без названия)' }}
                                            </div>
                                            <div class="text-[11px] text-fg-3 mt-0.5 flex flex-wrap gap-x-2 gap-y-0.5">
                                                @if($ri['article'])
                                                    <span class="mono">{{ $ri['article'] }}</span>
                                                @endif
                                                @if($ri['brand'])
                                                    <span>{{ $ri['brand'] }}</span>
                                                @endif
                                                @if($ri['qty'] !== null)
                                                    <span class="mono">{{ rtrim(rtrim(number_format((float) $ri['qty'], 3, '.', ' '), '0'), '.') }} шт</span>
                                                @endif
                                                @if($ri['has_catalog'])
                                                    <span class="text-emerald-700">📦 в каталоге</span>
                                                @endif
                                                @if($ri['quote_match_count'] > 0)
                                                    <span class="px-1.5 py-0.5 rounded border bg-violet-50 text-violet-700 border-violet-200">
                                                        уже привязано из КП ×{{ $ri['quote_match_count'] }}
                                                    </span>
                                                @endif
                                            </div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    @error('selectedRequestItemId') <div class="text-red-700 text-[12px]">{{ $message }}</div> @enderror

                    <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                        <button type="submit"
                                class="btn btn-primary"
                                @if($items->isEmpty() || $selectedRequestItemId === null) disabled @endif>
                            🔗 Привязать
                        </button>
                        <button type="button" wire:click="close" class="btn">Отмена</button>
                        <span class="flex-1"></span>
                        @if($qi && $qi->matched_request_item_id !== null)
                            <button type="button"
                                    wire:click="unlink({{ $qi->id }})"
                                    wire:confirm="Отвязать строку КП от текущей позиции заявки?"
                                    class="btn text-amber-700 border-amber-200 hover:bg-amber-50">
                                ✕ Отвязать
                            </button>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
