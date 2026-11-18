{{-- Поле «Страна»: список ISO 3166-1 alpha-2 (config/countries.php, русские названия из Википедии), храним код.
     Параметры: model — свойство Livewire, value — текущий код, class, disabled. Частые страны — сверху; код не из
     списка (старое значение) остаётся выбранным отдельной строкой. --}}
@php
    $countries = config('countries.iso_3166_1', []);
    $frequent = array_values(array_filter(config('countries.frequent', []), fn ($c) => isset($countries[$c])));
    $current = strtoupper(trim((string) ($value ?? '')));
@endphp
<select wire:model="{{ $model }}" class="{{ $class ?? '' }}" @disabled($disabled ?? false)>
    <option value="">— не указана —</option>
    @if($current !== '' && ! isset($countries[$current]))
        <option value="{{ $current }}">{{ $current }} (нет в ISO 3166-1)</option>
    @endif
    <optgroup label="Частые">
        @foreach($frequent as $code)
            <option value="{{ $code }}">{{ $countries[$code] }} · {{ $code }}</option>
        @endforeach
    </optgroup>
    <optgroup label="Все страны">
        @foreach($countries as $code => $name)
            <option value="{{ $code }}">{{ $name }} · {{ $code }}</option>
        @endforeach
    </optgroup>
</select>
