{{-- Вкладка «Актуализация цен» (дизайн «Актуализация цен», 10.2026): ответы поставщиков на запросы цен — по позициям.
     Разделы: новое предложение по обработанной позиции, готово к актуализации, ждём ответа и отказы. По позиции —
     «Закупаем здесь» / «Уточнить» / «Игнорировать» и «+ В выгрузку»; выгружает нижняя панель: «Выгрузить в 1С»
     (позиции остаются, сбор цен продолжается) или «Выгрузить и завершить сбор цен» (позиции уходят с экрана).
     Стили — здесь же, на токенах дизайн-системы: новые классы Tailwind без пересборки CSS не работают. --}}
@php
    $bar = $this->bar;
    $ready = $this->readyList;
    $new = $this->newItems;
    $wait = $this->waitItems;
    $states = $ready->mapWithKeys(fn ($it) => [$it['sku'] => $this->stateOf($it)['state']]);
    $dCounts = ['all' => $ready->count()] + collect(['open', 'buy', 'ignore'])->mapWithKeys(fn ($k) => [$k => $states->filter(fn ($s) => $s === $k)->count()])->all();
    $readyShown = $this->sorted($decision === 'all' ? $ready : $ready->filter(fn ($it) => $states[$it['sku']] === $decision)->values());
    $offersN = $this->rows->count();
    $usd = \App\Services\Iqot\IqotCurrencyConverter::rateToRub('USD');
    $term = trim($q);
    $sections = [
        ['key' => 'new', 'title' => 'Новое предложение по обработанной позиции', 'chip' => 'attn', 'items' => $new, 'count' => $new->count(),
            'hint' => 'По этим позициям работа была завершена и выгружена в 1С, но пришло новое предложение. Оно отмечено «новое».', 'empty' => null],
        ['key' => 'ready', 'title' => 'Готово к актуализации', 'chip' => 'ok', 'items' => $readyShown, 'count' => $ready->count(),
            'hint' => 'Цену дали хотя бы половина поставщиков, или ответили все (ценой или отказом), или есть хотя бы одно предложение и прошло больше '.\App\Services\QuoteParts\PriceRefreshReadiness::WAIT_BUSINESS_DAYS.' рабочих дней.',
            'empty' => $term !== '' ? 'Ничего не найдено.' : ($ready->isEmpty() ? 'Готовых к актуализации позиций нет.' : 'По этому фильтру позиций нет.')],
        ['key' => 'wait', 'title' => 'Ждём ответа и отказы', 'chip' => 'attn', 'items' => $wait, 'count' => $wait->count(),
            'hint' => 'Поставщики ещё не ответили или отказали; сюда же — цены из кабинета и прайсов без запроса. За '.$days.' дн.',
            'empty' => $term !== '' ? 'Ничего не найдено.' : 'Нет позиций в ожидании.'],
        ['key' => 'closed', 'title' => 'Сбор цен завершён', 'chip' => 'ok', 'items' => $this->closedItems, 'count' => $this->closedItems->count(),
            'hint' => 'Найдены по поиску: выгружены в 1С и сняты с экрана. «Вернуть в работу» — позиция снова в списке со всеми предложениями.', 'empty' => null],
    ];
@endphp
<div class="pa">
    @include('livewire.quoteparts._po-styles')

    {{-- Поиск, вид, период, устаревшие; справа — сводка. --}}
    <div class="pa-strip">
        <label class="pa-search">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="flex: none"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="search" wire:model.live.debounce.300ms="q" placeholder="Артикул, позиция, поставщик, заявка, QP">
        </label>
        <div class="pa-seg">
            @foreach(\App\Livewire\QuoteParts\PriceOffers::VIEWS as $v => $label)
                <button type="button" wire:click="$set('view', '{{ $v }}')" class="{{ $view === $v ? 'on' : '' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--fg-3)">За
            <div class="pa-seg">
                @foreach(\App\Livewire\QuoteParts\PriceOffers::DAYS as $d)
                    <button type="button" wire:click="$set('days', {{ $d }})" class="{{ $days === $d ? 'on' : '' }}" style="padding: 0 8px">{{ $d }} дн</button>
                @endforeach
            </div>
        </div>
        <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--fg-2); white-space: nowrap; cursor: pointer" title="Предложения, заменённые новыми, и с истёкшим сроком действия">
            <input type="checkbox" wire:model.live="withStale" style="margin: 0; accent-color: var(--sky-600)">и устаревшие
        </label>
        <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--fg-2); white-space: nowrap; cursor: pointer" title="В «Ждём ответа и отказы» — и запрошенные позиции, по которым пока не ответил ни один поставщик">
            <input type="checkbox" wire:model.live="withUnanswered" style="margin: 0; accent-color: var(--sky-600)">в т.ч. без ответов
        </label>
        @include('livewire.quoteparts._author-filter')
        <div style="flex: 1"></div>
        <span style="font-size: 12px; color: var(--fg-3); white-space: nowrap">Предложений <b class="tnum" style="color: var(--fg-1)">{{ $offersN }}{{ $offersN >= 500 ? '+' : '' }}</b> · отказов <b class="tnum" style="color: var(--fg-1)">{{ $this->declines->count() }}</b>@if($usd) · ₽ по курсу ЦБ {{ number_format($usd, 2, ',', ' ') }}@endif</span>
    </div>

    @foreach($sections as $s)
        @continue(in_array($s['key'], ['new', 'closed'], true) && $s['count'] === 0)
        <div class="pa-section" wire:key="pa-sec-{{ $s['key'] }}">
            <div class="pa-sec-head">
                <div style="display: flex; align-items: center; gap: 8px">
                    <h2>{{ $s['title'] }}</h2>
                    <span class="pa-count {{ $s['chip'] }}">{{ $s['count'] }}</span>
                </div>
                <span class="pa-sec-hint">{{ $s['hint'] }}</span>
                @if($s['key'] === 'ready')
                    <div class="pa-seg" style="background: var(--bg-surface)">
                        @foreach(\App\Livewire\QuoteParts\PriceOffers::DECISIONS as $k => $label)
                            <button type="button" wire:click="$set('decision', '{{ $k }}')" class="{{ $decision === $k ? 'on' : '' }}" style="height: 26px">{{ $label }}<span class="n">{{ $dCounts[$k] ?? 0 }}</span></button>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="pa-table">
                <div class="pa-grid pa-colhead">
                    <span>Поставщик · ответ</span><span style="text-align: right">Цена</span><span>Условия</span><span>Комментарий для 1С</span><span style="text-align: right">Решение</span>
                </div>
                @forelse($s['items'] as $it)
                    @include('livewire.quoteparts._po-item', ['it' => $it, 'section' => $s['key']])
                @empty
                    <div class="pa-empty">{{ $s['empty'] }}</div>
                @endforelse
            </div>
        </div>
    @endforeach

    {{-- Решено, список выгрузки и выгрузка. --}}
    {{-- Сопроводительный файл скачивается следом за файлом для 1С (PriceOffers::exportList). --}}
    <div class="pa-bar" x-data x-on:qp-companion-download.window="setTimeout(() => { const a = document.createElement('a'); a.href = $event.detail.url; a.download = ''; document.body.appendChild(a); a.click(); a.remove(); }, 800)">
        <div style="display: flex; flex-direction: column; gap: 6px; min-width: 220px">
            <span style="font-size: 13px"><b class="tnum">Решено {{ $bar['done'] }} из {{ $bar['total'] }}</b> <span style="color: var(--fg-3)">· в моей выгрузке {{ $bar['inList'] }} поз. на ≈ {{ number_format($bar['sum'], 0, ',', ' ') }} ₽</span></span>
            <div class="pa-progress"><div style="width: {{ $bar['total'] > 0 ? round($bar['done'] / $bar['total'] * 100) : 0 }}%"></div></div>
        </div>
        <div style="flex: 1"></div>
        @if($exportNote)
            <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 500; color: var(--status-ok-fg)">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>{{ $exportNote }}
                @if($companionExportId)<a href="{{ route('quoteparts.price-exports.companion', $companionExportId) }}" style="color: var(--accent); text-decoration: underline">сопроводительный файл</a>@endif</span>
        @endif
        @if($bar['addable']->isNotEmpty())
            <button type="button" wire:click="addAllChosen" wire:loading.attr="disabled" class="pa-bar-add">+ Добавить все с выбранным поставщиком · {{ $bar['addable']->count() }}</button>
        @endif
        <button type="button" wire:click="exportList(false)" wire:loading.attr="disabled" @disabled($bar['inList'] === 0) class="pa-bar-btn is-secondary"
                title="Выгрузить мой список — сколько угодно раз; позиции останутся на экране, сбор цен продолжается. Плюс сопроводительный файл: что покупаем здесь и у кого">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>Выгрузить в 1С{{ $bar['inList'] ? ' · '.$bar['inList'] : '' }}</button>
        <button type="button" wire:click="exportList(true)" wire:loading.attr="disabled" @disabled($bar['inList'] === 0) class="pa-bar-btn is-primary"
                wire:confirm="Выгрузить {{ $bar['inList'] }} поз. в 1С и убрать с экрана? Вернутся, если придёт новое предложение."
                title="Выгрузить в 1С и убрать позиции с экрана. Вернутся, если придёт новое предложение.">Выгрузить и завершить сбор цен{{ $bar['inList'] ? ' · '.$bar['inList'] : '' }}</button>
    </div>
</div>
