{{--
    Пагинация Livewire во всём приложении (перекрывает livewire::tailwind).
    Текущая страница — тёмная заливка, остальные — рамка; подписи по-русски.
    Стили инлайном на токенах design/colors_and_type.css: новые Tailwind-классы без пересборки CSS не работают.
--}}
@php
if (! isset($scrollTo)) {
    $scrollTo = 'body';
}

$scrollIntoViewJsSnippet = ($scrollTo !== false)
    ? <<<JS
       (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()
    JS
    : '';

$pageName = $paginator->getPageName();
$cell = 'display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 8px; border:1px solid var(--border); border-radius:6px; color:var(--fg-2); font-size:13px; line-height:1; font-variant-numeric:tabular-nums;';
$current = 'display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 8px; border:1px solid var(--fg-1); border-radius:6px; background:var(--fg-1); color:var(--fg-on-accent); font-size:13px; font-weight:600; line-height:1; font-variant-numeric:tabular-nums; cursor:default;';
$disabled = $cell.' background:var(--bg-surface); opacity:0.4; cursor:default;';
$chevronLeft = '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>';
$chevronRight = '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>';
@endphp

<div>
    @if ($paginator->hasPages())
        {{-- hover:bg-hover в собранном CSS нет — своё правило. --}}
        <style>.lw-pg{background:var(--bg-surface);cursor:pointer;transition:background-color 120ms cubic-bezier(0.2,0,0,1)}.lw-pg:hover{background:var(--bg-hover);color:var(--fg-1)}.lw-pg:focus-visible{outline:none;box-shadow:0 0 0 2px var(--ring)}</style>
        <nav role="navigation" aria-label="Страницы" style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px 16px">
            <div style="font-size:12.5px; color:var(--fg-3)">
                Страница <span style="font-weight:600; color:var(--fg-1)">{{ $paginator->currentPage() }}</span> из {{ $paginator->lastPage() }}
                <span style="color:var(--fg-4)">· {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} из {{ $paginator->total() }}</span>
            </div>

            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:4px">
                @if ($paginator->onFirstPage())
                    <span style="{{ $disabled }}" aria-disabled="true" aria-label="Предыдущая">{!! $chevronLeft !!}</span>
                @else
                    <button type="button" wire:click="previousPage('{{ $pageName }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                            class="lw-pg" style="{{ $cell }}" aria-label="Предыдущая" title="Предыдущая">{!! $chevronLeft !!}</button>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span style="{{ $cell }} border-color:transparent; background:transparent; color:var(--fg-4); cursor:default" aria-disabled="true">…</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            <span wire:key="paginator-{{ $pageName }}-page{{ $page }}">
                                @if ($page == $paginator->currentPage())
                                    <span style="{{ $current }}" aria-current="page">{{ $page }}</span>
                                @else
                                    <button type="button" wire:click="gotoPage({{ $page }}, '{{ $pageName }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                            class="lw-pg" style="{{ $cell }}" aria-label="Страница {{ $page }}">{{ $page }}</button>
                                @endif
                            </span>
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage('{{ $pageName }}')" x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                            class="lw-pg" style="{{ $cell }}" aria-label="Следующая" title="Следующая">{!! $chevronRight !!}</button>
                @else
                    <span style="{{ $disabled }}" aria-disabled="true" aria-label="Следующая">{!! $chevronRight !!}</span>
                @endif
            </div>
        </nav>
    @endif
</div>
