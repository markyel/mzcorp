<div class="space-y-3" x-data="{ hist: false }">
    {{-- Шапка раздела (дизайн «Актуализация цен», 10.2026): заголовок вкладки, вкладки с подчёркиванием и счётчиками. --}}
    @php
        $counts = $this->poolCounts;
        $tabs = [
            'candidates' => ['К актуализации', count($this->candidates), null, null],
            'pool' => ['В QuoteParts', array_sum($counts), ($counts['error'] ?? 0) > 0 ? 'ошибка '.$counts['error'] : (($counts['paused'] ?? 0) > 0 ? 'пауза '.$counts['paused'] : null), ($counts['error'] ?? 0) > 0 ? 'red' : 'attn'],
            'suppliers' => ['Поставщики', $this->invitedGroups->count(), null, null],
            'offers' => ['Актуализация цен', $this->freshOffersCount, null, null],
            'questions' => ['Вопросы поставщиков', null, $this->openQuestionsCount > 0 ? $this->openQuestionsCount : null, 'red'],
            'prices' => ['Закупки 1С', null, null, null],
            'groups' => ['Группы товаров', null, null, null],
            'pilot' => ['Пилот', null, $this->pilotSwitchedOn ? 'вкл' : 'выкл', $this->pilotSwitchedOn ? 'ok' : 'neutral'],
        ];
        $badge = ['red' => 'background: var(--accent); color: #fff', 'attn' => 'background: var(--status-attn-bg); color: var(--status-attn-fg)',
            'ok' => 'background: var(--status-ok-bg); color: var(--status-ok-fg)', 'neutral' => 'background: var(--status-neutral-bg); color: var(--status-neutral-fg)'];
    @endphp
    <div style="display: flex; flex-direction: column; gap: 12px; border-bottom: 1px solid var(--border)">
        <div style="display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div style="flex: 1 1 320px; min-width: 0">
                <div style="font-size: 12px; font-weight: 500; color: var(--fg-3); margin-bottom: 4px">QuoteParts · платформа работы с поставщиками</div>
                <h1 style="margin: 0; font-size: 20px; font-weight: 600; line-height: 1.2; letter-spacing: -0.005em; color: var(--fg-1)">{{ $tabs[$tab][0] ?? 'QuoteParts' }}</h1>
                <div style="font-size: 12px; color: var(--fg-3); margin-top: 4px">{{ $tab === 'offers'
                    ? 'Ответы поставщиков на запросы цен. Решите по каждой позиции — закупаем, уточняем или игнорируем — и выгрузите решения в 1С.'
                    : 'Тестирование. Передаём группы позиций из пула «К актуализации».' }}</div>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap">
                @if($tab === 'offers')
                    {{-- История выгрузок в 1С — здесь же, раскрывается под шапкой. --}}
                    <button type="button" x-on:click="hist = ! hist" class="btn btn-sm" :class="hist && 'btn-primary'">Выгрузка в 1С · история</button>
                @endif
                <a href="https://quoteparts.ru/items" target="_blank" rel="noopener" class="btn btn-sm">Открыть QuoteParts ↗</a>
            </div>
        </div>
        <div style="display: flex; gap: 2px; flex-wrap: wrap; margin: 0 -4px">
            @foreach($tabs as $key => [$label, $n, $b, $tone])
                <button type="button" wire:click="setTab('{{ $key }}')"
                        style="display: flex; align-items: center; gap: 6px; height: 36px; padding: 0 10px; border: 0; background: transparent; font-size: 13px; cursor: pointer; white-space: nowrap;
                               font-weight: {{ $tab === $key ? 600 : 500 }}; color: {{ $tab === $key ? 'var(--fg-1)' : 'var(--fg-2)' }}; box-shadow: {{ $tab === $key ? 'inset 0 -2px 0 var(--accent)' : 'none' }}">
                    {{ $label }}
                    @if($n !== null)<span style="font-family: var(--font-mono); font-size: 11px; color: var(--fg-3)">{{ number_format($n, 0, ',', ' ') }}</span>@endif
                    @if($b !== null)<span style="padding: 1px 6px; border-radius: 999px; font-size: 11px; font-weight: 600; {{ $badge[$tone] ?? $badge['neutral'] }}">{{ $b }}</span>@endif
                </button>
            @endforeach
        </div>
    </div>

    {{-- История выгрузок цен в 1С: последние файлы, скачать. --}}
    @if($tab === 'offers')
        @php $exports = \App\Models\PriceExport::query()->with('createdBy:id,name')->latest('id')->limit(20)->get(); @endphp
        <div x-show="hist" x-cloak class="ds-card">
            <div class="ds-card-header">
                <h3>Выгрузки цен в 1С</h3>
                <span class="text-[12px] text-fg-3 ml-2">последние {{ $exports->count() }} · «по списку» — из «Актуализации цен», «за период» — из «Снабжения»</span>
                <button type="button" x-on:click="hist = false" class="ml-auto text-[12px] text-fg-3 hover:text-fg-1">✕ свернуть</button>
            </div>
            @if($exports->isEmpty())
                <div class="ds-card-body text-[13px] text-fg-3">Выгрузок ещё не было.</div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px]">
                        <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                            <tr>
                                <th class="text-left px-3 py-2">Когда</th>
                                <th class="text-left px-3 py-2">Вид</th>
                                <th class="text-right px-3 py-2">Позиций</th>
                                <th class="text-right px-3 py-2">Поставщиков</th>
                                <th class="text-left px-3 py-2">Кто</th>
                                <th class="text-left px-3 py-2">Файл</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($exports as $e)
                                <tr class="border-b border-border-subtle" wire:key="pex-{{ $e->id }}">
                                    <td class="px-3 py-1.5 whitespace-nowrap tnum">{{ $e->created_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</td>
                                    <td class="px-3 py-1.5 whitespace-nowrap">{{ match ($e->kind ?? 'period') { 'positions' => 'по списку', 'request' => 'по заявке', default => 'за период '.$e->from_at?->format('d.m').'–'.$e->to_at?->format('d.m') } }}</td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ $e->rows_count }}</td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ $e->suppliers_count }}</td>
                                    <td class="px-3 py-1.5 text-fg-2">{{ $e->createdBy?->name ?? '—' }}</td>
                                    <td class="px-3 py-1.5">
                                        @if($e->file_path)<a href="{{ route('quoteparts.price-exports.download', $e->id) }}" class="text-sky-700 hover:underline">скачать</a>@else<span class="text-fg-4">—</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    @unless($this->configured)
        <div class="ds-card"><div class="ds-card-body text-[13px] text-amber-800">
            Ключ API QuoteParts не задан (<span class="mono">QUOTEPARTS_API_TOKEN</span>) — позиции будут ждать передачи.
        </div></div>
    @endunless

    {{-- Общий поиск раздела: на вкладках, где он что-то ищет (у «Актуализации цен» и «Групп товаров» — свой). --}}
    @if(in_array($tab, ['candidates', 'pool', 'questions'], true))
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="{{ $tab === 'questions' ? 'Поиск: артикул, позиция, поставщик, заявка, QP-…' : 'Поиск: артикул / наименование / бренд' }}"
               class="h-[32px] w-full max-w-[340px] px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
    @endif

    @if($tab === 'candidates')
        @php
            $rows = array_slice($this->candidates, 0, $perPage);
            $inPool = $this->poolByCatalogId;
            $picked = count(array_filter($selected));
        @endphp
        <div class="flex flex-wrap items-center gap-2 text-[12.5px]">
            <button type="button" wire:click="selectShown" class="btn btn-sm">Отметить показанные</button>
            @if($picked > 0)
                <button type="button" wire:click="clearSelection" class="btn btn-sm">Снять отметки</button>
                <button type="button" wire:click="transfer" wire:loading.attr="disabled" class="btn btn-sm btn-primary">
                    Передать в QuoteParts: {{ $picked }}
                </button>
            @endif
            <span class="text-fg-4">Передаётся часть карточки (наименование, бренд, артикулы, категория, размеры, вес, фото) и закупки 1С. Цены продажи и остатки не уходят.</span>
        </div>

        <div class="ds-card">
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="w-8 px-3 py-2"></th>
                            <th class="text-left px-3 py-2">Артикул</th>
                            <th class="text-left px-3 py-2">Позиция</th>
                            <th class="text-left px-3 py-2">Почему в списке</th>
                            <th class="text-right px-3 py-2">Заявок за {{ \App\Services\Procurement\PriceRefreshCandidates::WINDOW_DAYS }} дн.</th>
                            <th class="text-right px-3 py-2">Остаток</th>
                            <th class="text-left px-3 py-2">QuoteParts</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rows as $p)
                            @php $poolRow = $inPool[$p['cid']] ?? null; @endphp
                            <tr wire:key="cand-{{ $p['cid'] }}" class="border-b border-border-subtle hover:bg-hover">
                                <td class="px-3 py-2">
                                    @if($poolRow === null)
                                        <input type="checkbox" wire:model.live="selected.{{ $p['cid'] }}" class="w-4 h-4 accent-sky-600 cursor-pointer">
                                    @endif
                                </td>
                                <td class="px-3 py-2 mono whitespace-nowrap">{{ $p['sku'] }}</td>
                                <td class="px-3 py-2 text-fg-2">
                                    <span class="inline-block max-w-[460px] truncate align-bottom" title="{{ $p['name'] }}">{{ $p['name'] }}</span>
                                    @if($p['brand'])<div class="text-[11px] text-fg-4">{{ $p['brand'] }}</div>@endif
                                </td>
                                <td class="px-3 py-2"><span class="chip {{ $p['priority'] === 1 ? 'chip-ok' : 'chip-sky' }} text-[10.5px]">{{ $p['reason'] }}</span></td>
                                <td class="px-3 py-2 text-right mono">{{ $p['req_count'] }}</td>
                                <td class="px-3 py-2 text-right mono {{ $p['stock'] > 0 ? 'text-emerald-700' : 'text-fg-4' }}">{{ $p['stock'] > 0 ? $p['stock'] : '—' }}</td>
                                <td class="px-3 py-2 text-[11.5px]">
                                    @if($poolRow)
                                        <span class="chip {{ $poolRow->status === 'paused' ? 'chip-warn' : ($poolRow->status === 'error' ? 'chip-danger' : 'chip-neutral') }} text-[10.5px]">{{ $poolRow->statusLabel() }}</span>
                                    @elseif(empty($this->activity[$p['sku']]))
                                        <span class="text-fg-4">—</span>
                                    @endif
                                    {{-- Движение — и у позиций, которые не в пуле, но запрашивались у поставщиков. --}}
                                    @include('livewire.quoteparts._sku-activity', ['act' => $this->activity[$p['sku']] ?? null, 'sku' => $p['sku']])
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-10 text-center text-fg-3 text-[13px]">{{ trim($search) !== '' ? 'Ничего не найдено.' : 'Пул «К актуализации» пуст.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if(count($this->candidates) > $perPage)
                <div class="px-4 py-3 text-[11.5px] text-fg-3 flex items-center gap-3">
                    <span>Показано {{ $perPage }} из {{ count($this->candidates) }}</span>
                    <button type="button" wire:click="loadMore" class="btn btn-sm">Показать ещё</button>
                </div>
            @endif
        </div>
    @endif

    @if($tab === 'pilot')
        <div class="ds-card">
            <div class="ds-card-header"><h3>Принявшие приглашение — через QuoteParts</h3></div>
            <div class="ds-card-body text-[13px] space-y-2 border-b border-border-subtle">
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="toggleRouting" wire:confirm="{{ $this->routingOn ? 'Слать и этим поставщикам обычные письма?' : 'Слать запросы принявшим приглашение через QuoteParts?' }}" class="btn btn-sm {{ $this->routingOn ? '' : 'btn-primary' }}">{{ $this->routingOn ? 'Выключить' : 'Включить' }}</button>
                    <span>Сейчас: <b>{{ $this->routingOn ? 'запросы этим поставщикам идут через QuoteParts' : 'всем поставщикам — письмом' }}</b></span>
                </div>
                <div class="text-[12px] text-fg-3">Для всех сотрудников, без пилота: в заявке («Поставщики»), в «Снабжении» и при мониторинге цен отмеченные поставщики из списка ниже получают запрос через платформу (форма и письмо от MyZip), остальные — обычное письмо. Ответы видны в заявке и в «Снабжении».</div>
                <div class="flex flex-wrap gap-1.5">
                    @forelse($this->routedSuppliers as $rs)<span class="chip chip-ok text-[11px]">{{ $rs->organization?->name ?: $rs->name }} · {{ $rs->email }}</span>@empty<span class="text-fg-4 text-[12px]">Пока никто не принял приглашение.</span>@endforelse
                </div>
            </div>
            {{-- Внесистемные: наше письмо через QuoteParts с ящика orders@ (вход через Яндекс, токены — в QuoteParts). --}}
            <div class="ds-card-header"><h3>Внесистемные поставщики — письмом с {{ config('services.quoteparts.offsystem_mailbox') }}</h3></div>
            <div class="ds-card-body text-[13px] space-y-2 border-b border-border-subtle" wire:init="loadOffsystem">
                <div class="text-[12px] text-fg-3">Поставщикам с отметкой «Внесистемный» (карточка поставщика → QuoteParts) запрос уходит нашим обычным письмом с этого ящика через QuoteParts: ответ разбирает платформа, цены — в «Актуализации цен», копия ответа — автору запроса. Ящик подключается входом в Яндекс под ним; в «Почте» mzCorp его нет.</div>
                @if($offsystemLoaded)
                    <div class="flex flex-wrap items-center gap-3">
                        @if($offsystemBox['connected'] ?? false)
                            <span class="chip chip-ok text-[11px]">подключён · {{ $offsystemBox['address'] }}{{ ($offsystemBox['auth'] ?? null) === 'oauth' ? ' · вход через Яндекс' : '' }}</span>
                            @if(! empty($offsystemBox['checked_at']))<span class="text-[12px] text-fg-3">почта прочитана {{ \Illuminate\Support\Carbon::parse($offsystemBox['checked_at'])->timezone('Europe/Moscow')->format('d.m H:i') }}</span>@endif
                        @elseif(isset($offsystemBox['load_error']))
                            <span class="text-[12px] text-amber-800">QuoteParts не ответил: {{ $offsystemBox['load_error'] }}</span>
                        @else
                            <span class="chip chip-neutral text-[11px]">не подключён</span>
                        @endif
                        <a href="{{ route('oauth.yandex.quoteparts') }}" target="_blank" rel="noopener" class="btn btn-sm {{ ($offsystemBox['connected'] ?? false) ? '' : 'btn-primary' }}">{{ ($offsystemBox['connected'] ?? false) ? 'Подключить заново' : 'Подключить через Яндекс' }}</a>
                        <span class="text-[12px] text-fg-4">войдите в Яндекс под {{ config('services.quoteparts.offsystem_mailbox') }}</span>
                    </div>
                    {{-- Яндекс показывает код подтверждения (приложение mzCorp так настроено) — его вставляют сюда. --}}
                    <form wire:submit="connectOffsystemCode" class="flex flex-wrap items-center gap-2">
                        <span class="text-[12px] text-fg-2">Код от Яндекса:</span>
                        <input type="password" wire:model="offsystemCode" autocomplete="off" placeholder="вставьте код" class="h-[30px] w-40 px-2 border border-border rounded-md bg-surface text-[13px]">
                        <button type="submit" class="btn btn-sm btn-primary" wire:loading.attr="disabled" wire:target="connectOffsystemCode">Подключить</button>
                        <span class="text-[12px] text-fg-4">код одноразовый и действует несколько минут</span>
                    </form>
                    @if($offsystemMessage)<div class="text-[12.5px] {{ str_contains($offsystemMessage, 'не подключён') ? 'text-[var(--status-overdue-fg)]' : 'text-[var(--status-ok-fg)]' }}">{{ $offsystemMessage }}</div>@endif
                    @if(! empty($offsystemBox['error']))<div class="text-[12px] text-[var(--status-overdue-fg)]">Ошибка чтения почты: {{ $offsystemBox['error'] }}</div>@endif
                    <div class="flex flex-wrap gap-1.5">
                        @forelse($this->offsystemSuppliers as $os)<span class="chip chip-info text-[11px]">{{ $os->organization?->name ?: $os->name }} · {{ $os->email }}</span>@empty<span class="text-fg-4 text-[12px]">Пока никто не отмечен внесистемным.</span>@endforelse
                    </div>
                @else
                    <div class="text-fg-4 text-[12px]">Загружаю…</div>
                @endif
            </div>
            {{-- Сообщения поставщиков из переписки QuoteParts — в «Почту». --}}
            <div class="ds-card-header"><h3>Сообщения поставщиков — в «Почту»</h3></div>
            <div class="ds-card-body text-[13px] space-y-2 border-b border-border-subtle" wire:init="loadMirror">
                <div class="text-[12px] text-fg-3">Каждое сообщение поставщика в переписке (из кабинета QuoteParts или письмом) приходит копией в отмеченные ящики. Ответ на копию из «Почты» уходит поставщику в его переписку от MyZip.</div>
                @if($mirrorLoaded)
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        @foreach($this->mailboxOptions as $mb)
                            <label class="inline-flex items-center gap-1.5"><input type="checkbox" wire:model="mirrorMailboxes" value="{{ mb_strtolower($mb->email) }}" class="w-4 h-4 accent-sky-600"> {{ $mb->email }}<span class="text-fg-4 text-[11.5px]">{{ $mb->type === 'shared' ? 'общий' : ($mb->owner?->name ?? '') }}</span></label>
                        @endforeach
                    </div>
                    <button type="button" wire:click="saveMirror" class="btn btn-sm btn-primary">Сохранить</button>
                @else
                    <div class="text-fg-4 text-[12px]">Загружаю…</div>
                @endif
            </div>
            <div class="ds-card-header"><h3>Переключатель QuoteParts</h3></div>
            <div class="ds-card-body text-[13px] space-y-3">
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="togglePilotSwitch"
                            wire:confirm="{{ $this->pilotSwitchedOn ? 'Выключить QuoteParts? Кнопки исчезнут у всех, отправленные запросы останутся видны в заявках.' : 'Включить QuoteParts для отмеченных ниже участников пилота?' }}"
                            class="btn btn-sm {{ $this->pilotSwitchedOn ? '' : 'btn-primary' }}">
                        {{ $this->pilotSwitchedOn ? 'Выключить' : 'Включить для участников пилота' }}
                    </button>
                    <span>Сейчас: <b>{{ $this->pilotSwitchedOn ? 'включено для участников пилота' : 'выключено — все работают как раньше' }}</b></span>
                </div>
                <div class="text-[12px] text-fg-3">
                    Участник пилота видит во вкладке «Поставщики» заявки блок «Запрос через QuoteParts», а в «Снабжении» — выбор канала «Через QuoteParts»; обычная рассылка рядом не меняется.
                    Позиции — только с M-артикулом. Поставщики — любые из справочника: кто не принял приглашение, получает обычное письмо (режим переписки). Ответы по отправленным запросам видны всем, даже после выключения.
                </div>
            </div>
        </div>
        <div class="ds-card">
            <div class="ds-card-header"><h3>Участники пилота</h3><span class="text-[12px] text-fg-3 ml-2">{{ $this->pilotCandidates->where('quoteparts_pilot', true)->count() }} отмечено</span></div>
            <div class="ds-card-body text-[13px]">
                <div class="text-[12px] text-fg-3 mb-2">
                    «Дублировать переписку» — ответы поставщиков по всем запросам сотрудника через QuoteParts приходят ему копией на почту;
                    ответ на копию QuoteParts отправляет поставщику от MyZip, в его же переписку. Отвечать нужно с того же адреса, на который пришла копия.
                </div>
                <table class="w-full">
                    <thead>
                        <tr class="text-[11px] uppercase tracking-wider text-fg-3 text-left border-b border-border-subtle">
                            <th class="py-1 pr-3 font-semibold">Сотрудник</th>
                            <th class="py-1 pr-3 font-semibold">Пилот</th>
                            <th class="py-1 font-semibold">Дублировать переписку</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->pilotCandidates as $u)
                            <tr wire:key="pu-{{ $u->id }}" class="border-b border-border-subtle">
                                <td class="py-1 pr-3"><span>{{ $u->name }}</span> <span class="text-fg-4 text-[11.5px]">{{ $u->email }}</span></td>
                                <td class="py-1 pr-3">
                                    <input type="checkbox" wire:click="togglePilotUser({{ $u->id }})" @checked($u->quoteparts_pilot) class="w-4 h-4 accent-sky-600" title="Участник пилота">
                                </td>
                                <td class="py-1">
                                    <label class="inline-flex items-center gap-2 {{ $u->quoteparts_pilot ? '' : 'opacity-50' }}">
                                        <input type="checkbox" wire:click="togglePilotMirror({{ $u->id }})" @checked($u->quoteparts_mirror) class="w-4 h-4 accent-sky-600">
                                        <span class="text-fg-3 text-[12px]">на {{ $this->mirrorAddresses[$u->id] ?? $u->email }}</span>
                                    </label>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if($tab === 'offers')
        <livewire:quote-parts.price-offers />
    @endif

    @if($tab === 'questions')
        <livewire:quote-parts.supplier-questions :search="$search" />
    @endif

    @if($tab === 'prices')
        <livewire:quote-parts.purchase-prices />
    @endif

    @if($tab === 'groups')
        <livewire:quote-parts.supply-groups />
    @endif

    @if($tab === 'suppliers')
        @php $labels = \App\Services\QuoteParts\QuotePartsSupplierService::STATUS_LABELS; @endphp
        <div class="flex flex-wrap items-center gap-2 text-[12.5px]">
            <button type="button" wire:click="refreshSupplierStatuses" class="btn btn-sm">Обновить статусы</button>
            <span class="text-fg-4">Пригласить поставщика — в его карточке: «Поставщики» → «Реестр» → поставщик → блок «QuoteParts».</span>
        </div>
        <div class="ds-card">
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="text-left px-3 py-2">Поставщик</th>
                            <th class="text-left px-3 py-2">Статус</th>
                            <th class="text-left px-3 py-2">Приглашён</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Один поставщик (связь QuoteParts, организация) — одна строка, все его адреса вместе. --}}
                        @forelse($this->invitedGroups as $gi => $g)
                            <tr wire:key="qps-{{ $gi }}" class="border-b border-border-subtle hover:bg-hover align-top">
                                <td class="px-3 py-2">
                                    <div class="font-medium text-fg-1">{{ $g['name'] }}</div>
                                    @foreach($g['suppliers'] as $s)
                                        <div class="text-[11.5px] mt-0.5">
                                            <a href="{{ route('suppliers.registry-edit', $s) }}" class="text-sky-700 hover:underline">{{ $s->email }}</a>
                                            <span class="text-fg-4">{{ $s->contact_person ?: ($s->name !== $g['name'] ? $s->name : '') }}{{ $s->language ? ' · '.($s->language === 'ru' ? 'рус' : 'англ') : '' }}</span>
                                            {{-- Ссылка для входа в кабинет поставщика от имени этого адреса — сразу в буфер обмена. Только директорат и админ. --}}
                                            @if(auth()->user()?->hasAnyRole(['director', 'admin']))
                                            <button type="button" class="ml-1 text-[11px] text-sky-700 hover:underline"
                                                    title="Скопировать ссылку для входа в личный кабинет поставщика от имени {{ $s->email }} (действует 2 часа; всё, что сделаете в кабинете, будет от имени поставщика)"
                                                    x-data="{ busy: false }" :disabled="busy"
                                                    x-on:click="busy = true; $wire.accessLink({{ $s->id }}).then(u => { busy = false; if (! u) return;
                                                        const done = () => window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: 'Ссылка в кабинет {{ $s->email }} скопирована — действует 2 часа' } }));
                                                        (navigator.clipboard ? navigator.clipboard.writeText(u) : Promise.reject()).then(done, () => window.prompt('Скопируйте ссылку в кабинет поставщика:', u)); })">
                                                <span x-show="! busy">🔑 ссылка в кабинет</span><span x-show="busy" x-cloak>…</span>
                                            </button>
                                            @endif
                                        </div>
                                    @endforeach
                                </td>
                                <td class="px-3 py-2">
                                    <span class="chip {{ $g['status'] === 'active' ? 'chip-ok' : ($g['status'] === 'terms_discussed' ? 'chip-warn' : ($g['status'] === 'declined' ? 'chip-danger' : 'chip-info')) }} text-[10.5px]">{{ $labels[$g['status']] ?? $g['status'] }}</span>
                                    @if($g['comment'])<div class="mt-1 text-[11.5px] text-amber-800">«{{ $g['comment'] }}»</div>@endif
                                </td>
                                <td class="px-3 py-2 text-[11.5px] text-fg-3">{{ $g['invited_at'] ? \Illuminate\Support\Carbon::parse($g['invited_at'])->format('d.m.Y H:i') : '' }}
                                    @if($g['suppliers']->count() > 1)<div class="text-fg-4">адресов: {{ $g['suppliers']->count() }}</div>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-10 text-center text-fg-3 text-[13px]">Пока никого не пригласили.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if($tab === 'pool')
        <livewire:quote-parts.pool-rules />
        @php $pickedPool = count(array_filter($selectedPool)); @endphp
        <div class="flex flex-wrap items-center gap-2 text-[12.5px]">
            <button type="button" wire:click="refreshStatuses" class="btn btn-sm">Обновить статусы</button>
            @if($pickedPool > 0)
                <button type="button" wire:click="clearSelection" class="btn btn-sm">Снять отметки</button>
                <button type="button" wire:click="remove" wire:confirm="Убрать отмеченные позиции из QuoteParts? История там сохранится." class="btn btn-sm">
                    Убрать из QuoteParts: {{ $pickedPool }}
                </button>
            @endif
            <span class="text-fg-4">Изменения карточки досылаются автоматически каждый час. Существенные (другой бренд или артикул, другие размеры, другой смысл наименования) ставят позицию на паузу — решение принимается в QuoteParts.</span>
        </div>

        <div class="ds-card">
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="w-8 px-3 py-2"></th>
                            <th class="text-left px-3 py-2">Артикул</th>
                            <th class="text-left px-3 py-2">Позиция</th>
                            <th class="text-left px-3 py-2">Статус</th>
                            <th class="text-left px-3 py-2">Передано</th>
                            <th class="text-left px-3 py-2">Кто добавил</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->poolItems as $row)
                            <tr wire:key="pool-{{ $row->id }}" class="border-b border-border-subtle hover:bg-hover align-top">
                                <td class="px-3 py-2"><input type="checkbox" wire:model.live="selectedPool.{{ $row->id }}" class="w-4 h-4 accent-sky-600 cursor-pointer"></td>
                                <td class="px-3 py-2 mono whitespace-nowrap">{{ $row->sku }}</td>
                                <td class="px-3 py-2 text-fg-2">
                                    <span class="inline-block max-w-[420px] truncate align-bottom">{{ $row->catalogItem?->name }}</span>
                                    @if($row->status === 'paused' && $row->pause_reasons)
                                        <ul class="mt-1 text-[11.5px] text-amber-800 list-disc pl-4">
                                            @foreach($row->pause_reasons as $reason)<li>{{ $reason }}</li>@endforeach
                                        </ul>
                                    @endif
                                    @if($row->status === 'error' && $row->last_error)
                                        <div class="mt-1 text-[11.5px] text-red-700">{{ \Illuminate\Support\Str::limit($row->last_error, 200) }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <span class="chip {{ $row->status === 'paused' ? 'chip-warn' : ($row->status === 'error' ? 'chip-danger' : ($row->status === 'active' ? 'chip-ok' : 'chip-neutral')) }} text-[10.5px]">{{ $row->statusLabel() }}</span>
                                    @if($row->remote_version > 1)<div class="text-[11px] text-fg-4 mt-0.5">версия {{ $row->remote_version }}</div>@endif
                                    @include('livewire.quoteparts._sku-activity', ['act' => $this->activity[$row->sku] ?? null, 'sku' => $row->sku])
                                </td>
                                <td class="px-3 py-2 text-[11.5px] text-fg-3 whitespace-nowrap">{{ $row->synced_at?->format('d.m.Y H:i') ?? '—' }}</td>
                                <td class="px-3 py-2 text-[11.5px] text-fg-3">{{ $row->addedBy?->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-10 text-center text-fg-3 text-[13px]">В QuoteParts пока ничего не передано.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($this->poolItems->count() >= $poolShown && array_sum($this->poolCounts) > $poolShown)
                <div class="px-3 py-2 text-[12.5px] flex items-center gap-2">
                    <span class="text-fg-3">Показано {{ $poolShown }} из {{ number_format(array_sum($this->poolCounts), 0, ',', ' ') }} — найдите позицию поиском или</span>
                    <button type="button" wire:click="showMorePool" class="btn btn-xs">показать ещё 200</button>
                </div>
            @endif
        </div>
    @endif
</div>
