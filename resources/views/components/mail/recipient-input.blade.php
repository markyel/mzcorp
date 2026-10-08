@props([
    /** to | cc | bcc — по нему окно адресной книги находит поле. */
    'field',
    /** Livewire-свойство строки адресатов (toRaw / ccRaw / bccRaw). */
    'model',
    'debounce' => '1200ms',
    'placeholder' => '',
    'inputClass' => '',
])
{{--
    Поле адресатов с подсказками адресной книги и кнопкой окна выбора
    (resources/js/address-book.js, Alpine recipientField). Значение — та же
    строка «Имя <email>, …» в wire:model, поэтому автосохранение черновика
    и разбор адресов в композерах не меняются. Поля одной формы письма
    обёрнуты в [data-rcpt-group] — так окно знает, куда добавлять.
--}}
<div {{ $attributes->merge(['class' => 'rcpt-field']) }} x-data="recipientField">
    <input type="text" x-ref="input" data-rcpt="{{ $field }}" autocomplete="off"
           wire:model.live.debounce.{{ $debounce }}="{{ $model }}"
           placeholder="{{ $placeholder }}" class="{{ $inputClass }}"
           x-on:input="onInput" x-on:keydown="onKeydown($event)" x-on:focus="onFocus" x-on:blur="onBlur">
    <button type="button" class="rcpt-book" title="Адресная книга" tabindex="-1"
            x-on:mousedown.prevent x-on:click="openBook('{{ $field }}')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/><circle cx="12" cy="9" r="2.5"/><path d="M8.5 14.5c.6-1.6 2-2.5 3.5-2.5s2.9.9 3.5 2.5"/></svg>
    </button>
    <div class="rcpt-drop" x-show="open" x-cloak>
        <template x-for="(it, i) in items" :key="it.email">
            <button type="button" class="rcpt-opt" :class="{ 'is-active': i === active }"
                    x-on:mousedown.prevent="choose(it)" x-on:mouseenter="active = i">
                <span class="rcpt-opt-main">
                    <span class="rcpt-opt-name" x-text="it.name || it.email"></span>
                    <span class="rcpt-opt-email" x-show="it.name" x-text="it.email"></span>
                </span>
                <span class="rcpt-opt-src" x-text="label(it)"></span>
            </button>
        </template>
    </div>
</div>
