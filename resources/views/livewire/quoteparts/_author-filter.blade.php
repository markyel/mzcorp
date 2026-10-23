{{-- Фильтр «Чьи запросы» раздела QuoteParts (FiltersByRfqAuthor): все, мои или запросы сотрудника. --}}
@php
    $authorNow = $this->authorFilter();
    $authors = $this->authorOptions();
@endphp
<label style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--fg-3); white-space: nowrap"
       title="Позиции, запрошенные у поставщиков этим сотрудником (автор запроса QuoteParts). Менеджерам и снабжению по умолчанию — свои">
    Чьи запросы
    <select wire:model.live="author"
            style="height: 28px; padding: 0 8px; border: 1px solid var(--border-strong); border-radius: 6px; background: var(--bg-surface); font-size: 12px; color: var(--fg-1); {{ $authorNow !== 'all' ? 'border-color: var(--sky-500); box-shadow: 0 0 0 1px var(--sky-500)' : '' }}">
        <option value="all" @selected($authorNow === 'all')>Все</option>
        <option value="mine" @selected($authorNow === 'mine')>Мои</option>
        @foreach($authors as $id => $name)
            @continue((int) $id === (int) auth()->id())
            <option value="{{ $id }}" @selected($authorNow === (string) $id)>{{ $name }}</option>
        @endforeach
    </select>
</label>
