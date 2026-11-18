{{-- «Не покрыто КП» (Livewire Requests\QuoteCoverage): позиции, на которые ещё не дали КП. Пусто и без отметок — ничего. --}}
@php
    $open = $this->uncovered;
    $done = $this->resolved;
    $labels = \App\Services\Quotes\QuoteCoverageService::RESOLUTIONS;
@endphp
<div>
@if($this->hasQuote && ($open->isNotEmpty() || $done->isNotEmpty()))
    <div style="margin-bottom: 12px; border: 1px solid {{ $open->isNotEmpty() ? 'var(--amber-300, #fcd34d)' : 'var(--border)' }}; border-radius: 8px; background: {{ $open->isNotEmpty() ? 'var(--amber-50, #fffbeb)' : 'var(--bg-surface)' }}; font-size: 12.5px;">
        @if($open->isNotEmpty())
            <div style="padding: 8px 12px; border-bottom: 1px solid var(--border-subtle); display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap;">
                <b style="color: var(--amber-800, #92400e);">Не покрыто КП: {{ $open->count() }}</b>
                <span class="text-fg-3">по этим позициям клиент ещё не получил предложения — заявка в «Частичном КП». Дошлите КП или отметьте, как закрыли позицию.</span>
            </div>
            @foreach($open as $it)
                <div wire:key="qc-open-{{ $it->id }}" style="padding: 6px 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; border-top: 1px solid var(--border-subtle);">
                    <div style="flex: 1 1 280px; min-width: 0;">
                        <span style="color: var(--fg-1); font-weight: 500;">{{ $it->parsed_name ?: '—' }}</span>
                        @if($it->parsed_article)<span class="mono text-fg-3" style="font-size: 11.5px;"> · {{ $it->parsed_article }}</span>@endif
                        @if($it->parsed_qty)<span class="text-fg-3"> · {{ rtrim(rtrim(number_format((float) $it->parsed_qty, 3, ',', ' '), '0'), ',') }} {{ $it->parsed_unit }}</span>@endif
                        @if($it->possibly_discontinued)<span class="chip chip-warn" style="margin-left: 6px;" title="Все спрошенные поставщики отказали">возможно, не поставляется</span>@endif
                    </div>
                    <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                        <button type="button" wire:click="resolve({{ $it->id }}, 'substitute')" class="btn btn-xs" title="В КП предложили другое вместо этой позиции">закрыто заменой</button>
                        <button type="button" wire:click="resolve({{ $it->id }}, 'declined')" class="btn btn-xs" title="Мы сообщили клиенту, что не поставляем">мы отказали</button>
                        <button type="button" wire:click="resolve({{ $it->id }}, 'not_needed')" wire:confirm="Отключить позицию «{{ mb_strimwidth((string) $it->parsed_name, 0, 60, '…') }}»? Клиенту она не нужна."
                                class="btn btn-xs" title="Позиция отключается — в КП она не нужна">клиенту не нужно</button>
                    </div>
                </div>
            @endforeach
        @endif
        @if($done->isNotEmpty())
            <div style="padding: 6px 12px; {{ $open->isNotEmpty() ? 'border-top: 1px solid var(--border-subtle);' : '' }} display: flex; flex-direction: column; gap: 2px;">
                <span class="text-fg-3" style="font-size: 11.5px;">Закрыто отметкой:</span>
                @foreach($done as $it)
                    <div wire:key="qc-done-{{ $it->id }}" style="display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap;">
                        <span>{{ $it->parsed_name ?: '—' }}</span>
                        <span class="chip chip-neutral">{{ $labels[$it->quote_resolution] ?? $it->quote_resolution }}</span>
                        <button type="button" wire:click="resolve({{ $it->id }}, 'reset')" class="text-[11.5px]" style="color: var(--sky-700); background: none; border: 0; cursor: pointer;">вернуть</button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif
</div>
