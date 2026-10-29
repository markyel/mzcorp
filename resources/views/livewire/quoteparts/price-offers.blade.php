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
            'hint' => 'Ответили больше половины поставщиков, или есть хотя бы одно предложение и прошло больше '.\App\Services\QuoteParts\PriceRefreshReadiness::WAIT_BUSINESS_DAYS.' рабочих дней.',
            'empty' => $term !== '' ? 'Ничего не найдено.' : ($ready->isEmpty() ? 'Готовых к актуализации позиций нет.' : 'По этому фильтру позиций нет.')],
        ['key' => 'wait', 'title' => 'Ждём ответа и отказы', 'chip' => 'attn', 'items' => $wait, 'count' => $wait->count(),
            'hint' => 'Поставщики ещё не ответили или отказали; сюда же — цены из кабинета и прайсов без запроса. За '.$days.' дн.',
            'empty' => $term !== '' ? 'Ничего не найдено.' : 'Нет позиций в ожидании.'],
    ];
@endphp
<div class="pa">
    <style>
        .pa{--pa-cols:minmax(120px,180px) 104px 104px minmax(0,1fr) 172px;display:flex;flex-direction:column;gap:16px;font-size:13px;color:var(--fg-1)}
        .pa .tnum{font-feature-settings:'tnum'}
        .pa-strip{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg-surface-2)}
        .pa-search{display:flex;align-items:center;gap:8px;height:28px;padding:0 10px;border:1px solid var(--border-strong);border-radius:6px;background:var(--bg-surface);flex:0 1 280px;min-width:180px;color:var(--fg-3)}
        .pa-search input{flex:1;min-width:0;border:0;background:transparent;font-size:12px;outline:none;box-shadow:none;padding:0}
        .pa-seg{display:flex;padding:2px;border:1px solid var(--border);border-radius:6px;background:var(--bg-app)}
        .pa-seg button{height:24px;padding:0 10px;border:0;border-radius:4px;font-size:12px;font-weight:500;cursor:pointer;background:transparent;color:var(--fg-2);display:flex;align-items:center;gap:6px;white-space:nowrap}
        .pa-seg button.on{background:var(--bg-surface);color:var(--fg-1);box-shadow:var(--shadow-sm),0 0 0 1px var(--border)}
        .pa-seg button .n{font-family:var(--font-mono);font-size:11px;color:var(--fg-3)}
        .pa-section{display:flex;flex-direction:column;gap:10px}
        .pa-sec-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        .pa-sec-head h2{margin:0;font-size:16px;font-weight:600}
        .pa-count{padding:1px 8px;border-radius:999px;font-size:12px;font-weight:600;font-feature-settings:'tnum'}
        .pa-count.ok{background:var(--status-ok-bg);color:var(--status-ok-fg)}
        .pa-count.attn{background:var(--status-attn-bg);color:var(--status-attn-fg)}
        .pa-sec-hint{font-size:12px;color:var(--fg-3);flex:1 1 320px}
        .pa-table{background:var(--bg-surface);border:1px solid var(--border);border-radius:8px;overflow:hidden}
        .pa-grid{display:grid;grid-template-columns:var(--pa-cols);gap:12px}
        .pa-colhead{align-items:center;height:32px;padding:0 16px 0 20px;border-bottom:1px solid var(--border-strong);font-size:12px;font-weight:500;color:var(--fg-3)}
        .pa-pos{border-bottom:1px solid var(--border-strong);box-shadow:inset 4px 0 0 var(--pa-bar)}
        .pa-pos:last-child{border-bottom:0}
        .pa-pos.is-ready{--pa-bar:var(--emerald-600);--pa-head:var(--emerald-50);--pa-head-bd:oklch(88% 0.06 155);--pa-ans-bg:var(--emerald-600);--pa-ans-fg:#fff}
        .pa-pos.is-wait{--pa-bar:var(--neutral-400);--pa-head:var(--neutral-100);--pa-head-bd:var(--border);--pa-ans-bg:var(--bg-surface);--pa-ans-fg:var(--fg-2)}
        .pa-pos.is-refused{--pa-bar:var(--red-600);--pa-head:var(--red-50);--pa-head-bd:oklch(88% 0.06 25);--pa-ans-bg:var(--red-600);--pa-ans-fg:#fff}
        .pa-pos.is-new{--pa-bar:var(--amber-600);--pa-head:oklch(98% 0.02 85);--pa-head-bd:oklch(88% 0.07 85);--pa-ans-bg:var(--bg-surface);--pa-ans-fg:var(--fg-2)}
        .pa-head{display:grid;grid-template-columns:minmax(0,1fr) 172px;gap:12px;align-items:center;padding:12px 16px 12px 20px;background:var(--pa-head);border-bottom:1px solid var(--pa-head-bd);font-size:12px}
        .pa-head-main{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0}
        .pa-head-side{display:flex;flex-direction:column;align-items:stretch;gap:2px}
        .pa-sku{display:inline-flex;align-items:center;gap:4px;padding:0;border:0;background:none;cursor:pointer;font-family:var(--font-mono);font-size:13px;font-weight:700;color:var(--sky-700)}
        .pa-name{font-size:14px;font-weight:600;color:var(--fg-1)}
        .pa-answered{padding:1px 8px;border-radius:999px;font-weight:500;white-space:nowrap;background:var(--pa-ans-bg);color:var(--pa-ans-fg)}
        .pa-dim{color:var(--fg-3);white-space:nowrap}
        .pa-last{display:inline-flex;align-items:baseline;gap:6px;flex-wrap:wrap;min-width:0}
        .pa-export{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:28px;padding:0 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;border:1px solid var(--emerald-700);background:var(--emerald-600);color:#fff;box-shadow:var(--shadow-sm);transition:background 120ms}
        .pa-export:hover{background:var(--emerald-700)}
        .pa-export[disabled]{opacity:.45;cursor:not-allowed;box-shadow:none}
        .pa-export[disabled]:hover{background:var(--emerald-600)}
        .pa-export.is-in{cursor:default;box-shadow:none;border-color:var(--emerald-600)}
        .pa-export.is-in:hover{background:var(--emerald-600)}
        .pa-reopen{padding:6px 20px;border-bottom:1px solid var(--border);font-size:12px;color:var(--fg-2);background:var(--bg-surface)}
        .pa-card{padding:12px 16px;background:var(--bg-app);border-bottom:1px solid var(--border)}
        .pa-offer{border-top:1px solid var(--border);background:var(--bg-surface)}
        .pa-head + .pa-offer,.pa-reopen + .pa-offer,.pa-card + .pa-offer{border-top:0}
        .pa-offer.is-open{background:var(--bg-selected)}
        .pa-row{align-items:start;padding:10px 16px 10px 20px}
        .pa-col{display:flex;flex-direction:column;gap:2px;min-width:0}
        .pa-who{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
        .pa-sup{font-weight:600}
        .pa-meta{font-size:12px;color:var(--fg-3);font-feature-settings:'tnum'}
        .pa-link{align-self:flex-start;padding:0;border:0;background:none;font-size:12px;color:var(--sky-700);cursor:pointer}
        .pa-link:hover{text-decoration:underline}
        .pa-pill{padding:0 6px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap}
        .pa-pill-ok{background:var(--status-ok-bg);color:var(--status-ok-fg)}
        .pa-pill-over{background:var(--status-overdue-bg);color:var(--status-overdue-fg)}
        .pa-pill-attn{background:var(--status-attn-bg);color:var(--status-attn-fg)}
        .pa-pill-info{background:var(--status-info-bg);color:var(--status-info-fg)}
        .pa-pill-neutral{background:var(--status-neutral-bg);color:var(--status-neutral-fg)}
        .pa-pill-new{border:1px solid oklch(82% 0.1 80);background:var(--bg-surface);color:var(--amber-700)}
        .pa-price{text-align:right;align-items:flex-end}
        .pa-kind{padding:0 6px;border:1px solid;border-radius:4px;font-size:11px;font-weight:600;white-space:nowrap}
        .pa-kind.is-orig{background:var(--status-ok-bg);color:var(--status-ok-fg);border-color:oklch(85% 0.07 155)}
        .pa-kind.is-dev{background:var(--status-attn-bg);color:var(--status-attn-fg);border-color:oklch(85% 0.08 75)}
        .pa-amount{font-weight:600;font-size:14px;font-feature-settings:'tnum';white-space:nowrap}
        .pa-from{font-weight:400;font-size:11px;color:var(--fg-3)}
        .pa-terms{font-size:12px;color:var(--fg-2);font-feature-settings:'tnum'}
        .pa-terms span{white-space:nowrap}
        .pa-note{font-size:12px;color:var(--fg-2);text-wrap:pretty;overflow-wrap:anywhere}
        .pa-input{height:28px;box-sizing:border-box;border:1px solid var(--border);border-radius:6px;padding:0 8px;font-size:12px;background:var(--bg-surface);min-width:0;width:100%;outline:none}
        .pa-input:focus{border-color:var(--sky-500);box-shadow:0 0 0 2px var(--ring)}
        .pa-decide{align-items:stretch;gap:4px}
        .pa-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:28px;padding:0 10px;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;white-space:nowrap;border:1px solid transparent;background:transparent;transition:background 120ms,color 120ms}
        .pa-btn-accent{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:600}
        .pa-btn-accent:hover{background:var(--accent-600)}
        .pa-btn-secondary{background:var(--bg-surface);border-color:var(--border-strong);color:var(--fg-1)}
        .pa-btn-secondary:hover{background:var(--bg-hover)}
        .pa-btn-secondary.is-on{background:var(--sky-50);border-color:var(--sky-500);color:var(--sky-700)}
        .pa-btn-ghost{color:var(--fg-3)}
        .pa-btn-ghost:hover{background:var(--bg-hover);color:var(--fg-1)}
        .pa-btn-link{align-self:flex-end;color:var(--sky-700);padding:0 8px}
        .pa-btn-link:hover{background:var(--bg-hover)}
        .pa-state{align-self:flex-end;max-width:100%;box-sizing:border-box;display:inline-flex;align-items:center;gap:6px;min-height:22px;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:500;text-align:right}
        .pa-state .dot{width:6px;height:6px;border-radius:999px;background:currentColor;flex:none}
        .pa-state.is-ok{background:var(--status-ok-bg);color:var(--status-ok-fg)}
        .pa-state.is-neutral{background:var(--status-neutral-bg);color:var(--status-neutral-fg)}
        .pa-state.is-info{background:var(--status-info-bg);color:var(--status-info-fg)}
        .pa-thread{margin:0 16px 14px 20px;padding:12px;border:1px solid var(--border-strong);border-radius:8px;background:var(--bg-surface)}
        .pa-empty{padding:32px 16px;text-align:center;color:var(--fg-3)}
        .pa-bar{position:sticky;bottom:0;z-index:10;display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:10px 16px;background:var(--bg-surface);border:1px solid var(--border);border-radius:8px 8px 0 0;box-shadow:0 -4px 12px -6px rgba(15,18,23,0.08)}
        .pa-progress{height:4px;border-radius:999px;background:var(--bg-surface-2);overflow:hidden;width:280px;max-width:100%}
        .pa-progress div{height:100%;background:var(--emerald-600);transition:width 180ms}
        .pa-bar-btn{display:inline-flex;align-items:center;gap:8px;height:32px;padding:0 14px;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap}
        .pa-bar-btn[disabled]{opacity:.5;cursor:default}
        .pa-bar-btn.is-secondary{border:1px solid var(--border-strong);background:var(--bg-surface);color:var(--fg-1)}
        .pa-bar-btn.is-secondary:not([disabled]):hover{background:var(--bg-hover)}
        .pa-bar-btn.is-primary{border:0;background:var(--accent);color:#fff}
        .pa-bar-btn.is-primary:not([disabled]):hover{background:var(--accent-600)}
        .pa-bar-add{height:32px;padding:0 12px;border:0;border-radius:6px;background:transparent;font-size:12px;font-weight:600;color:var(--emerald-700);cursor:pointer}
        .pa-bar-add:hover{background:var(--emerald-50)}
    </style>

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
        @continue($s['key'] === 'new' && $s['count'] === 0)
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
