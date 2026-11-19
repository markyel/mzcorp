{{-- «Не покрыто КП» (Livewire Requests\QuoteCoverage): позиции, на которые ещё не дали КП. Пусто и без отметок — ничего.
     «Ответим позже» сворачивает позицию в строку; снова в списке — когда цена появилась ПОСЛЕ этого (quote_price_ready_at
     позже quote_deferred_at). Цена, что уже была, позицию не разворачивает (MZ2026-01254, 20.11.2026).
     У закрытой заявки — только отметки (в т.ч. «перенесено в заявку-остаток» со ссылкой), без кнопок. --}}
@php
    $open = $this->isClosed ? collect() : $this->uncovered;
    $done = $this->resolved;
    $labels = \App\Services\Quotes\QuoteCoverageService::LABELS;
    $remainder = $this->remainder;
    $prices = $open->isNotEmpty() ? $this->prices : [];
    $priceLabels = \App\Services\Quotes\QuoteCoverageService::PRICE_LABELS;
    [$later, $active] = $open->partition(fn ($it) => $it->quote_deferred_at !== null
        && ! ($it->quote_price_ready_at !== null && $it->quote_price_ready_at->gt($it->quote_deferred_at)));
@endphp
<div>
@if($this->hasQuote && ($open->isNotEmpty() || $done->isNotEmpty()))
    <div style="margin-bottom: 12px; border: 1px solid {{ $active->isNotEmpty() ? 'var(--amber-300, #fcd34d)' : 'var(--border)' }}; border-radius: 8px; background: {{ $active->isNotEmpty() ? 'var(--amber-50, #fffbeb)' : 'var(--bg-surface)' }}; font-size: 12.5px;">
        @if($active->isNotEmpty())
            <div style="padding: 8px 12px; border-bottom: 1px solid var(--border-subtle); display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap;">
                <b style="color: var(--amber-800, #92400e);">Не покрыто КП: {{ $active->count() }}</b>
                <span class="text-fg-3">клиент ещё не получил предложения — заявка в «Частичном КП». Досылаете позже — «ответим позже»: позиция свернётся до появления цены. Остальные кнопки — если позицию закрыли иначе.</span>
            </div>
            @foreach($active as $it)
                <div wire:key="qc-open-{{ $it->id }}" style="padding: 6px 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; border-top: 1px solid var(--border-subtle);">
                    <div style="flex: 1 1 280px; min-width: 0;">
                        <span style="color: var(--fg-1); font-weight: 500;">{{ $it->parsed_name ?: '—' }}</span>
                        @if($it->parsed_article)<span class="mono text-fg-3" style="font-size: 11.5px;" title="{{ $it->parsed_article }}"> · {{ mb_strimwidth((string) $it->parsed_article, 0, 40, '…') }}</span>@endif
                        @if($it->parsed_qty)<span class="text-fg-3"> · {{ rtrim(rtrim(number_format((float) $it->parsed_qty, 3, ',', ' '), '0'), ',') }} {{ $it->parsed_unit }}</span>@endif
                        @if(isset($prices[$it->id]))<span class="chip chip-ok" style="margin-left: 6px;" title="Можно дослать КП по этой позиции">📤 {{ $priceLabels[$prices[$it->id]] }}</span>@endif
                        @if($it->possibly_discontinued)<span class="chip chip-warn" style="margin-left: 6px;" title="Все спрошенные поставщики отказали">возможно, не поставляется</span>@endif
                    </div>
                    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                        <button type="button" wire:click="resolve({{ $it->id }}, 'later')" class="btn btn-xs" title="Клиенту сказали, что по позиции ответим позже: свернуть; если цены ещё нет — развернётся, когда она появится. Заявка остаётся в «Частичном КП»">ответим позже</button>
                        <button type="button" wire:click="resolve({{ $it->id }}, 'substitute')" class="btn btn-xs" title="В КП предложили другое вместо этой позиции">закрыто заменой</button>
                        <button type="button" wire:click="resolve({{ $it->id }}, 'declined')" class="btn btn-xs" title="Мы сообщили клиенту, что не поставляем">мы отказали</button>
                        <button type="button" wire:click="resolve({{ $it->id }}, 'not_needed')" wire:confirm="Отключить позицию «{{ mb_strimwidth((string) $it->parsed_name, 0, 60, '…') }}»? Клиенту она не нужна."
                                class="btn btn-xs" title="Позиция отключается — в КП она не нужна">клиенту не нужно</button>
                    </div>
                </div>
            @endforeach
        @endif
        @if($later->isNotEmpty())
            <div x-data="{ open: false }" style="padding: 6px 12px; {{ $active->isNotEmpty() ? 'border-top: 1px solid var(--border-subtle);' : '' }}">
                <div style="display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap;">
                    <span class="text-fg-2"><b>Ответим позже</b> · без КП {{ $later->count() }} поз. — свёрнуто до появления цены, заявка в «Частичном КП»</span>
                    <button type="button" @click="open = ! open" class="text-[11.5px]" style="color: var(--sky-700); background: none; border: 0; cursor: pointer;" x-text="open ? 'скрыть' : 'показать'">показать</button>
                </div>
                <div x-show="open" x-cloak style="margin-top: 4px; display: flex; flex-direction: column; gap: 2px;">
                    @foreach($later as $it)
                        <div wire:key="qc-later-{{ $it->id }}" style="display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap;">
                            <span>{{ $it->parsed_name ?: '—' }}</span>
                            <span class="text-fg-3 text-[11.5px]">с {{ $it->quote_deferred_at->format('d.m') }}</span>
                            @if(isset($prices[$it->id]))<span class="text-[11.5px]" style="color: var(--status-ok-fg);">📤 {{ $priceLabels[$prices[$it->id]] }}</span>@endif
                            <button type="button" wire:click="resolve({{ $it->id }}, 'unlater')" class="text-[11.5px]" style="color: var(--sky-700); background: none; border: 0; cursor: pointer;">вернуть в список</button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        @if($done->isNotEmpty())
            <div style="padding: 6px 12px; {{ $open->isNotEmpty() ? 'border-top: 1px solid var(--border-subtle);' : '' }} display: flex; flex-direction: column; gap: 2px;">
                <span class="text-fg-3" style="font-size: 11.5px;">Закрыто отметкой:</span>
                @foreach($done as $it)
                    <div wire:key="qc-done-{{ $it->id }}" style="display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap;">
                        <span>{{ $it->parsed_name ?: '—' }}</span>
                        <span class="chip chip-neutral">{{ $labels[$it->quote_resolution] ?? $it->quote_resolution }}</span>
                        @if($it->quote_resolution === \App\Services\Quotes\QuoteCoverageService::RESOLUTION_CARRIED_OVER)
                            @if($remainder)<a href="{{ route('requests.show', $remainder->id) }}" wire:navigate class="mono text-[11.5px]" style="color: var(--sky-700);">{{ $remainder->internal_code }}</a>@endif
                        @elseif(! $this->isClosed)
                            <button type="button" wire:click="resolve({{ $it->id }}, 'reset')" class="text-[11.5px]" style="color: var(--sky-700); background: none; border: 0; cursor: pointer;">вернуть</button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif
</div>
