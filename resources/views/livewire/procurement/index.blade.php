<div class="space-y-4">
    @if(session('procurement_status'))
        <div class="ds-card"><div class="ds-card-body text-[13px] text-emerald-700">{{ session('procurement_status') }}</div></div>
    @endif

    {{-- Мои запросы поставщикам: свои RFQ (по умолчанию — застрявшие, по которым
         авто-напоминания уже не идут) с действиями закрыть/вернуть/запросить. --}}
    @php $myRfq = $this->myInquiries; @endphp
    <div class="ds-card">
        @php $mgrOptions = $this->managerOptions; $privileged = ! empty($mgrOptions); @endphp
        <div class="ds-card-header">
            <h3 class="text-[15px] font-semibold text-fg-1">📮 {{ $privileged ? 'Запросы поставщикам' : 'Мои запросы поставщикам' }}</h3>
            <span class="text-[12px] text-fg-3 ml-2">{{ $rfqView === 'stuck' ? 'застрявшие — без ответа и без напоминаний' : 'все открытые' }}</span>
            <span class="flex-1"></span>
            @if($privileged)
                <select wire:model.live="viewAsUserId"
                        class="h-[30px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"
                        title="Смотреть запросы глазами менеджера">
                    <option value="0">👥 Все менеджеры</option>
                    @foreach($mgrOptions as $m)
                        <option value="{{ $m['id'] }}">{{ $m['name'] }}</option>
                    @endforeach
                </select>
            @endif
            <select wire:model.live="rfqView"
                    class="h-[30px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
                <option value="stuck">⚠ Застрявшие</option>
                <option value="all">Все открытые</option>
            </select>
        </div>
        <div class="ds-card-body">
            @forelse($myRfq as $inq)
                <div wire:key="rfq-{{ $inq->id }}"
                     class="flex flex-wrap items-start gap-3 py-2.5 {{ ! $loop->last ? 'border-b border-border-subtle' : '' }}">
                    <div class="flex-1 min-w-[240px]">
                        <div class="flex items-center gap-2 flex-wrap text-[13px]">
                            <span class="font-medium text-fg-1">{{ $inq->supplier_name ?: $inq->supplier_email }}</span>
                            @if($inq->offers_count > 0)
                                {{-- Есть оффер(ы) — приоритетно над reply_state (он ставится
                                     только при ОТСУТСТВИИ оффера; null ≠ «без оффера»). --}}
                                <span class="chip text-[10.5px]" style="background:var(--emerald-50);color:var(--emerald-700)"><span class="dot"></span>оффер получен · {{ $inq->offers_count }}</span>
                            @elseif($inq->inbound_count > 0)
                                @if(($inq->last_direction instanceof \BackedEnum ? $inq->last_direction->value : $inq->last_direction) === 'outbound')
                                    {{-- Последнее письмо наше (ответили на вопрос, уточнили) — ход за поставщиком. --}}
                                    <span class="chip text-[10.5px]" style="background:var(--sky-50);color:var(--sky-700)"><span class="dot"></span>вы ответили · ждём поставщика</span>
                                @elseif($inq->reply_state === 'question_to_us')
                                    <span class="chip text-[10.5px]" style="background:var(--red-50);color:var(--red-700)"><span class="dot"></span>задал вопрос — ответьте</span>
                                @elseif($inq->reply_state === 'awaiting_supplier')
                                    <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-3)"><span class="dot"></span>поставщик уточняет / ещё считает</span>
                                @else
                                    <span class="chip text-[10.5px]" style="background:var(--amber-50);color:var(--amber-800)"><span class="dot"></span>ответил без оффера</span>
                                @endif
                            @else
                                <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-3)"><span class="dot"></span>тишина · напоминаний: {{ $inq->reminders_sent }}</span>
                            @endif
                            @if($inq->relatedRequest)
                                <a href="{{ route('requests.show', $inq->relatedRequest->id) }}" wire:navigate
                                   class="mono text-[11.5px] text-sky-700 hover:underline">{{ $inq->relatedRequest->internal_code }}</a>
                            @endif
                            @if($privileged && $inq->createdBy)
                                <span class="text-[10.5px] text-fg-4">👤 {{ $inq->createdBy->name }}</span>
                            @endif
                        </div>
                        <div class="text-[11.5px] text-fg-2 mt-1">
                            <span class="text-fg-4">{{ $inq->items_count }} поз.:</span>
                            @foreach($inq->items->take(4) as $it)
                                @if($it->catalogItem)<span class="mono text-fg-4">{{ $it->catalogItem->sku }}</span> @endif<span>{{ \Illuminate\Support\Str::limit($it->catalogItem?->name ?: $it->item_name, 34) ?: '—' }}</span>@if(! $loop->last)<span class="text-fg-4">;</span> @endif
                            @endforeach
                            @if($inq->items_count > 4)<span class="text-fg-4">+{{ $inq->items_count - 4 }}</span>@endif
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 flex-wrap justify-end">
                        <a href="{{ route('suppliers.show', $inq->id) }}" wire:navigate
                           class="btn btn-sm" title="Открыть переписку с поставщиком">💬 Переписка</a>
                        @if($inq->is_stuck)
                            <button type="button" wire:click="resumeRemind({{ $inq->id }})"
                                    wire:confirm="Вернуть в работу и отправить напоминание поставщику?"
                                    class="btn btn-sm" title="Сбросить счётчик и напомнить сейчас">↻ Вернуть в работу</button>
                            <button type="button" wire:click="reRequestInquiry({{ $inq->id }})"
                                    wire:confirm="Отправить свежий запрос поставщику по тем же позициям? Текущий будет закрыт."
                                    class="btn btn-sm" title="Закрыть текущий и отправить новый RFQ">✉ Заново запросить</button>
                            <button type="button" wire:click="closeDeclined({{ $inq->id }})"
                                    wire:confirm="Закрыть запрос как отказ поставщика?"
                                    class="btn btn-sm text-red-700" title="Пометить как отказ и закрыть">✕ Отказ</button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="text-[13px] text-fg-3 py-4 text-center">
                    {{ $rfqView === 'stuck' ? 'Нет застрявших запросов — по всем идут напоминания или получены ответы.' : 'Нет открытых запросов поставщикам.' }}
                </div>
            @endforelse
        </div>
    </div>

    {{-- Запросы через QuoteParts (пилот): ответы поставщиков формой или письмом. --}}
    @php $qpRfqs = $this->quotePartsRfqs; @endphp
    @if($qpRfqs->isNotEmpty())
        @php
            $qpShown = $this->quotePartsShown;
            $qpNames = app(\App\Services\QuoteParts\QuotePartsRfqService::class)->supplierNames($qpRfqs);
        @endphp
        <div class="ds-card" x-data="{ open: true }">
            <div class="ds-card-header cursor-pointer select-none" @click="open = !open">
                <h3 class="text-[15px] font-semibold text-fg-1">Запросы через QuoteParts</h3>
                <span class="text-[12px] text-fg-3 ml-2">{{ $qpView === 'all' ? 'открытые и за 30 дней: '.$qpRfqs->count() : 'застрявшие: '.$qpShown->count().' из '.$qpRfqs->count() }}</span>
                <span class="flex-1"></span>
                {{-- Как у прямых запросов: по умолчанию только застрявшие. --}}
                <select wire:model.live="qpView" @click.stop
                        class="h-[30px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
                    <option value="stuck">⚠ Застрявшие</option>
                    <option value="all">Все</option>
                </select>
                <span class="text-[12px] text-fg-3 ml-2" x-text="open ? '▾' : '▸'"></span>
            </div>
            <div class="ds-card-body space-y-3 text-[13px]" x-show="open">
                @forelse($qpShown as $rfq)
                    @if($qpView !== 'all' && ($stuck = $rfq->stuckSuppliers()) !== [])
                        <div class="flex flex-wrap gap-1.5 -mb-1">
                            @foreach($stuck as $key => $why)
                                <span class="chip text-[10.5px]" style="background:var(--amber-50);color:var(--amber-800)"><span class="dot"></span>{{ $qpNames[$key] ?? $key }}: {{ \App\Models\QuotePartsRfq::STUCK_LABELS[$why] }}</span>
                            @endforeach
                        </div>
                    @endif
                    @include('livewire.quote-parts._rfq-card', ['rfq' => $rfq, 'supplierNames' => $qpNames, 'refreshAction' => 'refreshQuotePartsRfq'])
                @empty
                    <div class="text-[12.5px] text-fg-3">Застрявших нет — по всем запросам есть ответ или ход за поставщиком. Все запросы — «Все».</div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- Вкладки раздела --}}
    @php
        $refreshSum = $this->refreshSummary;
        $monSum = $this->monitorSummary;
        $tabs = [
            'requests' => ['⛏ Запросы', null],
            'refresh' => ['💰 К актуализации', $refreshSum['total']],
            'offers' => ['📥 Предложения поставщиков', null],
            'monitor' => ['🔁 Мониторинг цен', $monSum['active']],
            'exports' => ['📤 Выгрузки цен', null],
            ...(\App\Services\QuoteParts\QuotePartsPilot::canConverse(auth()->user()) ? ['pricelists' => ['📋 Прайсы поставщиков', null]] : []),
        ];
    @endphp
    <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden text-[12.5px]">
        @foreach($tabs as $k => [$label, $count])
            <button type="button" wire:click="setTab('{{ $k }}')"
                    class="h-[30px] px-3 whitespace-nowrap font-medium border-r border-border last:border-r-0
                           {{ $tab === $k ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                {{ $label }}@if($count !== null) <span class="mono opacity-70">{{ $count }}</span>@endif
            </button>
        @endforeach
    </div>

    @if($tab === 'requests')
    {{-- Заголовок + сводка --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h2 class="text-[16px] font-semibold text-fg-1">⛏ Снабжение</h2>
            <span class="text-[12px] text-fg-3 ml-2">позиции, сдерживающие выдачу КП</span>
            <span class="flex-1"></span>
            <a href="{{ route('procurement.missing-codes') }}" wire:navigate class="btn btn-sm"
               title="Повторяющиеся OEM-коды из заявок, не найденные в каталоге — пробелы базы синонимов и кандидаты в ассортимент">🔍 Не найдено в каталоге</a>
        </div>
        <div class="ds-card-body">
            @php $sum = $this->summary; @endphp
            <div class="flex flex-wrap gap-6 text-[13px]">
                <div><span class="text-[22px] font-semibold text-fg-1 mono">{{ $sum['positions'] }}</span> <span class="text-fg-3">M-позиций с неактуальной ценой</span></div>
                <div><span class="text-[22px] font-semibold text-fg-1 mono">{{ $sum['requests'] }}</span> <span class="text-fg-3">{{ $mode === 'period' ? 'заявок затронуто за период' : 'заявок ждут актуализацию (до-КП)' }}</span></div>
                <div><span class="text-[22px] font-semibold text-emerald-700 mono">{{ $sum['in_stock'] }}</span> <span class="text-fg-3">из них на складе <span class="text-fg-4">— достаточно переоценки, поставщик не нужен</span></span></div>
            </div>
            <div class="text-[11.5px] text-fg-4 mt-2">
                @if($mode === 'period')
                    Только позиции, у которых цена в каталоге <b>до сих пор неактуальна</b>: показываем те из них, что клиенты запрашивали за последние <b>{{ $periodDays }} дн.</b> (независимо от статуса заявки). Как только цена актуализируется в 1С — позиция из списка исчезает. Это полный поток спроса на «протухшие» позиции, а не только текущая очередь до-КП.
                @else
                    Считаем заявки в статусах <b>Новая / Назначена / В работе / Уточнение</b> со сматченными позициями (M-артикул), у которых цена в каталоге неактуальна. Чем в большем числе заявок зависла позиция — тем выше приоритет обновить её цену.
                @endif
            </div>
        </div>
    </div>

    {{-- Поиск + режим списка + фильтр наличия --}}
    <div class="flex flex-wrap items-center gap-2">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Поиск: артикул / наименование / бренд"
               class="h-[32px] w-full max-w-[340px] px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
        <select wire:model.live="mode"
                class="h-[32px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
            <option value="current">Текущие блокеры (заявки до КП)</option>
            <option value="period">За период — цена до сих пор неактуальна</option>
        </select>
        @if($mode === 'period')
            <select wire:model.live="periodDays"
                    class="h-[32px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
                @foreach(\App\Livewire\Procurement\Index::PERIOD_DAYS as $d)
                    <option value="{{ $d }}">за {{ $d }} дн.</option>
                @endforeach
            </select>
        @endif
        <select wire:model.live="stockFilter"
                class="h-[32px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
            <option value="">Наличие: все</option>
            <option value="in">📦 только на складе</option>
            <option value="out">только без остатка</option>
        </select>
        <select wire:model.live="rfqFilter"
                class="h-[32px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
            <option value="">Запрос поставщику: все</option>
            <option value="sent">⏳ уже запрошены — контроль ответов</option>
            <option value="none">ещё не запрошены</option>
        </select>

            @if($this->groupOptions !== [])
                <span class="inline-flex items-center gap-1">
                    <select wire:model.live="askedGroup" class="h-[32px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500" title="Отбор по группе поставщиков, которых по позиции уже спрашивали">
                        <option value="0">Группа поставщиков: все</option>
                        @foreach($this->groupOptions as $gid => $gname)<option value="{{ $gid }}">{{ $gname }}</option>@endforeach
                    </select>
                    @if($askedGroup > 0)
                        <select wire:model.live="askedGroupMode" class="h-[32px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
                            <option value="asked">уже спрашивали</option>
                            <option value="not">ещё не спрашивали</option>
                        </select>
                    @endif
                </span>
            @endif
    </div>

    {{-- Запрос расценки на ЛЮБЫЕ позиции каталога (не только блокеры) --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>🔎 Запросить расценку на позиции каталога</h3></div>
        <div class="ds-card-body">
            <p class="text-[12px] text-fg-3 mb-2">Найдите любую позицию каталога и добавьте её в запрос поставщикам — независимо от того, была ли она в КП.</p>
            <input type="search" wire:model.live.debounce.300ms="catalogSearch"
                   placeholder="Артикул, название или бренд…"
                   class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
            @if(trim($catalogSearch) !== '')
                <div class="mt-2 border border-border-subtle rounded-md divide-y divide-border-subtle max-h-72 overflow-y-auto">
                    @forelse($this->catalogSearchResults as $ci)
                        <div class="flex items-center gap-2 px-3 py-2 text-[12.5px]" wire:key="csr-{{ $ci->id }}">
                            <div class="flex-1 min-w-0">
                                <span class="mono text-fg-3">{{ $ci->sku }}</span>
                                <span class="text-fg-1">· {{ $ci->name }}</span>
                                @if($ci->brand)<span class="text-fg-4">· {{ $ci->brand }}</span>@endif
                            </div>
                            <button type="button" wire:click="addCatalogItem({{ $ci->id }})"
                                    class="btn btn-sm btn-primary shrink-0">+ В запрос</button>
                        </div>
                    @empty
                        <div class="px-3 py-2 text-[12px] text-fg-4">Ничего не найдено.</div>
                    @endforelse
                </div>
            @endif
            <details class="mt-3" @if($skuListReport && $skuListReport['missing'] !== []) open @endif>
                <summary class="text-[12.5px] text-sky-700 cursor-pointer select-none">Добавить списком артикулов</summary>
                <div class="mt-2">
                    <textarea wire:model="skuList" rows="4"
                              placeholder="M05333&#10;M26889, M00171&#10;— столбец из Excel, через запятую или пробел"
                              class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] mono outline-none focus:border-sky-500"></textarea>
                    <div class="flex items-center gap-2 mt-1.5">
                        <button type="button" wire:click="addCatalogSkus" wire:loading.attr="disabled" wire:target="addCatalogSkus" class="btn btn-sm btn-primary">
                            <span wire:loading.remove wire:target="addCatalogSkus">Найти и добавить</span>
                            <span wire:loading wire:target="addCatalogSkus">Ищу…</span>
                        </button>
                        <span class="text-[11.5px] text-fg-3">берём все M-артикулы из текста, остальное игнорируем</span>
                    </div>
                    @if($skuListReport)
                        <div class="text-[12px] mt-1.5">
                            @if($skuListReport['empty'])
                                <span class="text-amber-700">В тексте нет M-артикулов.</span>
                            @else
                                <span class="text-emerald-700">Добавлено: {{ $skuListReport['added'] }}</span>
                                @if($skuListReport['already'] > 0)<span class="text-fg-3"> · уже были в запросе: {{ $skuListReport['already'] }}</span>@endif
                                @if($skuListReport['missing'] !== [])
                                    <div class="text-red-700 mt-0.5">Нет в каталоге ({{ count($skuListReport['missing']) }}): <span class="mono">{{ implode(', ', $skuListReport['missing']) }}</span> — оставлены в поле, проверьте написание.</div>
                                @endif
                            @endif
                        </div>
                    @endif
                </div>
            </details>
            @if($this->selectedPositions->count() > 0)
                <div class="text-[12px] text-emerald-700 mt-2">В запросе позиций: {{ $this->selectedPositions->count() }} — оформите ниже в блоке «Запрос поставщикам».</div>
            @endif
        </div>
    </div>

    {{-- Топ позиций-блокеров --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>{{ $mode === 'period' ? 'Топ позиций с неактуальной ценой по спросу за период' : 'Топ позиций-блокеров' }}</h3></div>
        <div class="ds-card-body overflow-x-auto">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                    <tr>
                        <th class="px-2 py-2" style="width:30px"></th>
                        <th class="text-right px-2 py-2" style="width:40px">#</th>
                        <th class="text-left px-2 py-2" style="width:130px">Артикул</th>
                        <th class="text-left px-2 py-2">Наименование</th>
                        <th class="text-left px-2 py-2" style="width:120px">Бренд</th>
                        <th class="text-right px-2 py-2" style="width:90px">Заявок</th>
                        <th class="text-left px-2 py-2">Заявки</th>
                        <th class="text-right px-2 py-2" style="width:90px">Наличие</th>
                        <th class="text-right px-2 py-2" style="width:110px">Цена (ст.)</th>
                        <th class="text-left px-2 py-2" style="width:150px">IQOT (конкуренты)</th>
                        <th class="text-left px-2 py-2" style="width:180px">Ответ поставщика</th>
                    </tr>
                </thead>
                @forelse($this->positions as $i => $p)
                    @php
                        $iqp = $this->iqotByCatalogId->get($p['cid']);
                        $hasIqot = $iqp !== null;
                        $resp = $this->responseByCatalogId[$p['cid']] ?? null;
                        $hasOffers = $resp !== null && ! empty($resp['offers']);
                    @endphp
                    <tbody x-data="{ open: false, sup: false }" wire:key="blk-{{ $p['cid'] }}">
                        <tr class="border-b border-border-subtle hover:bg-hover align-top">
                            <td class="px-2 py-2 text-center"><input type="checkbox" wire:model.live="selected.{{ $p['cid'] }}"></td>
                            <td class="px-2 py-2 text-right mono text-fg-4">{{ $this->positions->firstItem() + $i }}</td>
                            <td class="px-2 py-2 mono text-fg-2">{{ $p['sku'] }}</td>
                            <td class="px-2 py-2 text-fg-1">
                                <button type="button" wire:click="toggleCard({{ $p['cid'] }}, 't')" class="text-left hover:text-sky-700"
                                        title="Карточка товара и история поставщиков">
                                    <span class="text-fg-4 text-[10px] mr-0.5">{{ $this->isCardOpen($p['cid'], 't') ? '▾' : '▸' }}</span>{{ \Illuminate\Support\Str::limit($p['name'], 64) }}
                                </button>
                            </td>
                            <td class="px-2 py-2 text-fg-3">{{ $p['brand'] ?: '—' }}</td>
                            <td class="px-2 py-2 text-right"><span class="chip chip-warn text-[11px] mono">{{ $p['req_count'] }}</span></td>
                            <td class="px-2 py-2">
                                <span class="flex flex-wrap gap-1">
                                    @foreach($p['codes'] as $c)
                                        <a href="{{ route('requests.show', $c['id']) }}" wire:navigate
                                           class="text-sky-700 hover:underline mono text-[11px]">{{ $c['code'] }}</a>
                                    @endforeach
                                    @if($p['req_count'] > count($p['codes']))<span class="text-fg-4 text-[11px]">+{{ $p['req_count'] - count($p['codes']) }}</span>@endif
                                </span>
                            </td>
                            <td class="px-2 py-2 text-right">
                                @if($p['stock'] > 0)
                                    <span class="chip chip-ok text-[10.5px] mono" title="На складе {{ $p['stock'] }} шт — для продажи достаточно актуализировать цену">📦 {{ $p['stock'] }}</span>
                                @else
                                    <span class="text-fg-4 text-[11px]">нет</span>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-right mono text-fg-3">{{ $p['price'] !== null ? number_format((float)$p['price'], 2, '.', ' ') : '—' }}</td>
                            <td class="px-2 py-2">
                                @if($hasIqot)
                                    <button type="button" @click="open = !open"
                                            class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-[10.5px] font-medium hover:bg-emerald-100">
                                        <span>IQOT</span>
                                        @if($iqp->report_min_price !== null)<span>: мин. {{ number_format((float) $iqp->report_min_price, 0, ',', ' ') }} ₽</span>@endif
                                        <span>· {{ $iqp->report_offers_count ?? count($iqp->offers()) }} офф.</span>
                                        <span x-text="open ? '▾' : '▸'"></span>
                                    </button>
                                @else
                                    <span class="text-fg-4 text-[11px]">нет данных</span>
                                @endif
                            </td>
                            <td class="px-2 py-2">
                                @if($resp === null)
                                    <span class="text-fg-4 text-[11px]">—</span>
                                @else
                                    @if(! empty($resp['chosen']))
                                        @php $ch = $resp['chosen']; @endphp
                                        <div class="mb-0.5 text-[10.5px]" title="Закупаем у этого поставщика (выбрано {{ $ch['at'] }})">
                                            <span class="chip chip-ok text-[10.5px]">✓ {{ \Illuminate\Support\Str::limit($ch['supplier'], 22) }}</span>
                                            @if($ch['current'] && $ch['price'] !== null)<span class="mono text-fg-2">{{ number_format($ch['price'], 2, '.', ' ') }} {{ $ch['currency'] }}</span>@else<span class="text-fg-4">нет текущей цены</span>@endif
                                        </div>
                                    @endif
                                    <div class="flex items-center gap-1.5">
                                        @if($resp['state'] === 'none')
                                        @elseif($resp['state'] === 'review')
                                            <span class="chip chip-sky text-[10.5px]" title="Поставщик предложил аналог, копию, восстановленное или замену — проверьте и примите к закупке">🔎 {{ \App\Models\QuotePartsOffer::reviewLabel(null) }}</span>
                                        @elseif($resp['state'] === 'quoted')
                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-[10.5px] font-medium">
                                                <span>💰 {{ $resp['best_price'] !== null ? number_format($resp['best_price'], 2, '.', ' ') : 'цена' }}{{ $resp['best_currency'] ? ' '.$resp['best_currency'] : ' ₽' }}</span>
                                            </span>
                                            @if($resp['best_supplier'])<span class="text-fg-3 text-[10.5px] truncate" style="max-width:80px" title="{{ $resp['best_supplier'] }}">{{ $resp['best_supplier'] }}</span>@endif
                                            @if($resp['pending_count'] > 0)<span class="text-fg-4 text-[10px]" title="ещё ждём ответ от {{ $resp['pending_count'] }} поставщ.">+{{ $resp['pending_count'] }}</span>@endif
                                        @elseif($resp['state'] === 'refused')
                                            <span class="chip chip-warn text-[10.5px]">✖ отказ</span>
                                        @else
                                            <span class="chip chip-sky text-[10.5px]">⏳ ждём ответ{{ $resp['pending_count'] > 1 ? ' ('.$resp['pending_count'].')' : '' }}</span>
                                        @endif
                                        @if($hasOffers)
                                            <button type="button" @click="sup = !sup" class="text-fg-4 text-[11px] hover:text-fg-2" x-text="sup ? '▾' : '▸'"></button>
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                        @if($this->isCardOpen($p['cid'], 't') && ($card = $this->productCards[$p['cid']] ?? null))
                            <tr class="border-b border-border-subtle bg-surface-2">
                                <td colspan="11" class="px-4 py-2.5">
                                    @include('livewire.catalog._catalog-item-detail', ['cat' => $card['cat'], 'pc' => $card['pc'], 'iqp' => null, 'canIqot' => false, 'allowIqotAnalyze' => false, 'withSupplyHistory' => false])
                                    @include('livewire.suppliers._catalog-supplier-history', ['history' => $card['history']])
                                </td>
                            </tr>
                        @endif
                        @if($hasIqot)
                            <tr x-show="open" x-cloak class="border-b border-border-subtle bg-surface-2">
                                <td colspan="11" class="px-4 py-2.5">
                                    @include('livewire.iqot._comparison', ['pos' => $iqp])
                                </td>
                            </tr>
                        @endif
                        @if($hasOffers)
                            <tr x-show="sup" x-cloak class="border-b border-border-subtle bg-surface-2">
                                <td colspan="11" class="px-4 py-2.5">
                                    <div class="text-[10.5px] uppercase tracking-wider text-fg-4 mb-1.5">Ответы поставщиков</div>
                                    <div class="space-y-1">
                                        @foreach($resp['offers'] as $of)
                                            <div class="flex items-center gap-2 text-[12px]">
                                                <span class="text-fg-2 font-medium truncate" style="min-width:170px;max-width:170px" title="{{ $of['supplier'] }}">{{ $of['supplier'] }}</span>
                                                @if($of['outcome'] === 'quoted')
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-[10.5px] font-medium">💰 {{ $of['price'] !== null ? number_format($of['price'], 2, '.', ' ') : 'цена' }}{{ $of['currency'] ? ' '.$of['currency'] : ' ₽' }}</span>
                                                    @if($of['valid_until'])<span class="text-fg-4 text-[11px]">до {{ $of['valid_until'] }}</span>@endif
                                                    @if(! empty($of['variant']))<span class="chip chip-sky text-[10.5px]">{{ $of['variant'] }}</span>@endif
                                                    @if(! empty($of['note']))<span class="text-fg-3 text-[11px]" title="{{ $of['note'] }}">{{ \Illuminate\Support\Str::limit($of['note'], 60) }}</span>@endif
                                                    @php $__g = collect($of['photos'] ?? [])->values()->map(fn ($u, $i) => ['src' => $u, 'name' => 'Фото '.($i + 1), 'dl' => $u])->all(); @endphp @foreach($__g as $__i => $ph)<a href="{{ $ph['src'] }}" x-on:click.prevent="$dispatch('open-image', {{ \Illuminate\Support\Js::from(['items' => $__g, 'index' => $__i]) }})" class="text-sky-700 hover:underline text-[11px]">📷{{ count($__g) > 1 ? $__i + 1 : '' }}</a>@endforeach
                                                    {{-- Отклонение от запрошенного — разбор руками. --}}
                                                    @if(($of['review'] ?? null) === 'pending')
                                                        <span class="chip chip-warn text-[10.5px]" title="{{ \Illuminate\Support\Str::ucfirst(\App\Models\QuotePartsOffer::kindLabel($of['kind'] ?? null)) }}: {{ \App\Models\QuotePartsOffer::kindHint($of['kind'] ?? null) }}">{{ \App\Models\QuotePartsOffer::reviewLabel('pending') }}</span>
                                                        <button type="button" wire:click="acceptDeviation({{ $of['ref'] }})" class="btn btn-xs" title="Закупать этот вариант по запрошенной позиции: цена пойдёт как цена позиции">Принять к закупке</button>
                                                        <button type="button" wire:click="rejectDeviation({{ $of['ref'] }})" class="btn btn-xs btn-ghost">Отклонить</button>
                                                    @elseif(($of['review'] ?? null) === 'accepted')
                                                        <span class="chip chip-ok text-[10.5px]">принято к закупке</span>
                                                        <button type="button" wire:click="rejectDeviation({{ $of['ref'] }})" class="text-fg-4 hover:text-fg-2 text-[10.5px]">отменить</button>
                                                    @elseif(($of['review'] ?? null) === 'rejected')
                                                        <span class="chip chip-neutral text-[10.5px]">отклонено</span>
                                                        <button type="button" wire:click="acceptDeviation({{ $of['ref'] }})" class="text-fg-4 hover:text-fg-2 text-[10.5px]">принять</button>
                                                    @endif
                                                    {{-- У кого закупаем: выбор остаётся при следующих ценах. --}}
                                                    @if(! empty($of['key']) && ! in_array($of['review'] ?? null, ['pending', 'rejected'], true))
                                                        @if(($resp['chosen']['key'] ?? null) === $of['key'])
                                                            <span class="chip chip-ok text-[10.5px] ml-auto">✓ закупаем здесь</span>
                                                            <button type="button" wire:click="clearSupplierChoice({{ $p['cid'] }})" class="text-fg-4 hover:text-fg-2 text-[10.5px]">снять</button>
                                                        @else
                                                            <button type="button" wire:click="chooseSupplier({{ $p['cid'] }}, '{{ $of['key'] }}')" class="btn btn-xs ml-auto">Закупаем здесь</button>
                                                        @endif
                                                    @endif
                                                @else
                                                    <span class="chip chip-warn text-[10.5px]">✖ отказ</span>
                                                    @if($of['refusal'])<span class="text-fg-3 text-[11px]">{{ \Illuminate\Support\Str::limit($of['refusal'], 70) }}</span>@endif
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="text-[10.5px] text-fg-4 mt-2">Цена обновится в каталоге после импорта из 1С → заблокированные заявки получат сигнал «💰».</div>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                @empty
                    <tbody>
                        <tr><td colspan="11" class="px-3 py-10 text-center text-fg-3 text-[13px]">{{ trim($search) !== '' || $stockFilter !== '' || $rfqFilter !== '' ? 'Ничего не найдено по заданным фильтрам.' : ($mode === 'period' ? 'За выбранный период нет запрошенных позиций без актуальной цены.' : 'Нет позиций с неактуальной ценой в заявках до выдачи КП.') }}</td></tr>
                    </tbody>
                @endforelse
            </table>
        </div>
        {{-- Авторолистывание: sentinel догружает следующую порцию при попадании
             в зону видимости (с упреждением 300px), сохраняя scrollTop. Паттерн
             из App\Livewire\Requests\Pool. --}}
        <div class="px-4 py-3 flex items-center gap-3 text-[11.5px] text-fg-3">
            <span>Показано {{ count($this->positions->items()) }} из {{ $this->positions->total() }}</span>
            @if($this->positions->hasMorePages())
                <span class="text-border-strong">·</span>
                <div wire:key="proc-load-more"
                     x-data="{
                         keepScrollLoadMore() {
                             let s = this.$el.parentElement;
                             while (s && !((['auto','scroll'].includes(getComputedStyle(s).overflowY)) && s.scrollHeight > s.clientHeight)) s = s.parentElement;
                             s = s || document.scrollingElement || document.documentElement;
                             const y = s.scrollTop;
                             this.$wire.loadMore().then(() => requestAnimationFrame(() => { s.scrollTop = y; }));
                         }
                     }"
                     x-intersect.margin.300px="keepScrollLoadMore()"
                     class="flex items-center gap-2">
                    <span wire:loading.remove wire:target="loadMore" class="text-fg-4">прокрутите вниз — загрузится ещё</span>
                    <span wire:loading wire:target="loadMore" class="flex items-center gap-2">
                        <span class="inline-block w-3.5 h-3.5 border-2 border-fg-4 border-t-transparent rounded-full animate-spin"></span>
                        Загрузка…
                    </span>
                </div>
            @endif
        </div>
    </div>
    @endif

    @if($tab === 'refresh')
        {{-- К актуализации: приоритезированный список позиций, по которым стоит
             обновить цену. Порог «регулярного спроса» — решение заказчика. --}}
        <div class="ds-card">
            <div class="ds-card-header">
                <h2 class="text-[16px] font-semibold text-fg-1">💰 К актуализации</h2>
                <span class="text-[12px] text-fg-3 ml-2">что запрашивать у поставщиков в первую очередь</span>
            </div>
            <div class="ds-card-body">
                <div class="flex flex-wrap gap-6 text-[13px]">
                    <div><span class="text-[22px] font-semibold text-emerald-700 mono">{{ $refreshSum['p1'] }}</span>
                        <span class="text-fg-3">на складе, но без цены <span class="text-fg-4">— продать можно сразу, мешает только цена</span></span></div>
                    <div><span class="text-[22px] font-semibold text-fg-1 mono">{{ $refreshSum['p2'] }}</span>
                        <span class="text-fg-3">регулярный спрос без наличия</span></div>
                    <div><span class="text-[22px] font-semibold text-sky-700 mono">{{ $refreshSum['monitored'] }}</span>
                        <span class="text-fg-3">уже на мониторинге</span></div>
                    @php $iqs = $this->iqotMonitoringSummary; @endphp
                    @if($iqs['enabled'] || $iqs['on'] > 0)
                        <div title="Мониторинг цен IQOT «Гибрид»: цены с сайтов поставщиков и КП по почте. Синхронизация раз в час">
                            <span class="text-[22px] font-semibold text-fg-1 mono">{{ $iqs['on'] }}</span>
                            <span class="text-fg-3">в IQOT, с ценами <b class="text-fg-1">{{ $iqs['priced'] }}</b></span>
                            @if($iqs['no_oem'] > 0)<span class="text-fg-4"> · без OEM (не отправляем): {{ $iqs['no_oem'] }}</span>@endif
                        </div>
                    @endif
                </div>
                <div class="text-[11.5px] text-fg-4 mt-2">
                    Позиции с неактуальной ценой, которые спрашивали за последние
                    <b>{{ \App\Livewire\Procurement\Index::REFRESH_WINDOW_DAYS }} дн.</b>
                    Сначала те, что лежат на складе: по ним сделка упирается только в цену.
                    Затем те, что спрашивали от
                    <b>{{ \App\Livewire\Procurement\Index::REFRESH_REGULAR_MIN_REQUESTS }} раз</b> за окно.
                    Отметьте нужные и отправьте запрос поставщикам — там же можно включить мониторинг.
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Поиск: артикул / наименование / бренд"
                   class="h-[32px] w-full max-w-[340px] px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
            @if($this->groupOptions !== [])
                <span class="inline-flex items-center gap-1">
                    <select wire:model.live="askedGroup" class="h-[32px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500" title="Отбор по группе поставщиков, которых по позиции уже спрашивали">
                        <option value="0">Группа поставщиков: все</option>
                        @foreach($this->groupOptions as $gid => $gname)<option value="{{ $gid }}">{{ $gname }}</option>@endforeach
                    </select>
                    @if($askedGroup > 0)
                        <select wire:model.live="askedGroupMode" class="h-[32px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
                            <option value="asked">уже спрашивали</option>
                            <option value="not">ещё не спрашивали</option>
                        </select>
                    @endif
                </span>
            @endif
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
                            <th class="text-left px-3 py-2" title="Группы поставщиков, которым уже отправляли запрос по позиции (число — сколько поставщиков группы)">Спрашивали</th>
                            <th class="text-right px-3 py-2">Заявок за 30 дн.</th>
                            <th class="text-right px-3 py-2">Остаток</th>
                            <th class="text-right px-3 py-2">Цена</th>
                            <th class="text-left px-3 py-2" title="Мониторинг цен IQOT («Гибрид»): лучшая цена поставщиков с сайтов и из КП, без НДС. На мониторинг идут позиции с OEM-артикулом">IQOT, без НДС</th>
                            <th class="text-left px-3 py-2">Мониторинг</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $refRows = array_slice($this->refreshList, 0, $perPage); $askedGroups = $this->askedGroupsFor(array_column($refRows, 'cid')); $iqotMon = $this->iqotMonitoringFor(array_column($refRows, 'cid')); @endphp
                        @forelse($refRows as $p)
                            <tr wire:key="ref-{{ $p['cid'] }}" class="border-b border-border-subtle hover:bg-hover">
                                <td class="px-3 py-2">
                                    <input type="checkbox" wire:model.live="selected.{{ $p['cid'] }}"
                                           class="w-4 h-4 accent-sky-600 cursor-pointer">
                                </td>
                                <td class="px-3 py-2 mono whitespace-nowrap">{{ $p['sku'] }}</td>
                                <td class="px-3 py-2 text-fg-2">
                                    <button type="button" wire:click="toggleCard({{ $p['cid'] }}, 't')" class="text-left hover:text-sky-700" title="Карточка товара и история поставщиков">
                                        <span class="text-fg-4 text-[10px] mr-0.5">{{ $this->isCardOpen($p['cid'], 't') ? '▾' : '▸' }}</span><span class="inline-block max-w-[420px] truncate align-bottom" title="{{ $p['name'] }}">{{ $p['name'] }}</span>
                                    </button>
                                    @if($p['brand'])<div class="text-[11px] text-fg-4">{{ $p['brand'] }}</div>@endif
                                </td>
                                <td class="px-3 py-2">
                                    <span class="chip {{ $p['priority'] === 1 ? 'chip-ok' : 'chip-sky' }} text-[10.5px]">{{ $p['reason'] }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    @php $ag = $askedGroups[$p['cid']] ?? ['groups' => [], 'other' => 0]; @endphp
                                    @if($ag['groups'] === [] && $ag['other'] === 0)
                                        <span class="text-fg-4 text-[11px]">никого</span>
                                    @else
                                        <span class="inline-flex flex-wrap gap-1">
                                            @foreach($ag['groups'] as $gname => $gcount)
                                                <span class="chip chip-neutral text-[10.5px]" title="Спрашивали поставщиков группы: {{ $gcount }}">{{ $gname }} · {{ $gcount }}</span>
                                            @endforeach
                                            @if($ag['other'] > 0)<span class="text-fg-4 text-[11px]" title="Спрашивали поставщиков, не входящих ни в одну группу">+{{ $ag['other'] }} вне групп</span>@endif
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right mono">{{ $p['req_count'] }}</td>
                                <td class="px-3 py-2 text-right mono {{ $p['stock'] > 0 ? 'text-emerald-700' : 'text-fg-4' }}">
                                    {{ $p['stock'] > 0 ? $p['stock'] : '—' }}
                                </td>
                                <td class="px-3 py-2 text-right mono text-fg-3">{{ $p['price'] ? number_format((float) $p['price'], 0, ',', ' ') : '—' }}</td>
                                <td class="px-3 py-2 text-[11.5px] whitespace-nowrap">
                                    @php $im = $iqotMon[$p['cid']] ?? ['row' => null, 'pos' => null, 'oem' => false, 'market' => null]; $mon = $im['row']; $mk = $im['market']; @endphp
                                    @if($mk && $mk['offers'] > 0)
                                        <button type="button" wire:click="toggleCard({{ $p['cid'] }}, 't')" class="text-left" title="Цены поставщиков — в карточке позиции">
                                            <span class="mono font-semibold text-fg-1">{{ number_format((float) $mk['best_net'], 0, ',', ' ') }}</span>
                                            <div class="text-fg-4">без НДС · мед. {{ number_format((float) $mk['median_net'], 0, ',', ' ') }} · {{ $mk['offers'] }} пр.</div>
                                            @if($mk['our_rank'])<div class="{{ $mk['delta'] > 0 ? 'text-red-700' : 'text-emerald-700' }}" title="Наша цена без НДС против цен поставщиков без НДС">мы {{ $mk['our_rank'] }}-е из {{ $mk['total'] }}</div>@endif
                                        </button>
                                    @elseif($mon && $mon->active)
                                        <span class="{{ in_array($mon->match_status, ['ambiguous', 'rejected'], true) || $mon->import_error ? 'text-amber-700' : 'text-sky-700' }}">{{ $mon->import_error ? 'ошибка загрузки' : (\App\Models\IqotMonitoringItem::MATCH_LABELS[$mon->match_status] ?? 'на мониторинге') }}</span>
                                    @elseif(! $im['oem'])
                                        <span class="text-fg-4" title="Нет артикула производителя (OEM): IQOT нечего искать, на мониторинг не ставим">нет OEM</span>
                                    @else
                                        <span class="text-fg-4" title="Уйдёт на мониторинг с ближайшей синхронизацией (раз в час)">в очереди</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-[11.5px]">
                                    @if($p['monitored_until'])
                                        <span class="text-sky-700">до {{ \Illuminate\Support\Carbon::parse($p['monitored_until'])->format('d.m.Y') }}</span>
                                    @else
                                        <span class="text-fg-4">нет</span>
                                    @endif
                                </td>
                            </tr>
                            @if($this->isCardOpen($p['cid'], 't') && ($card = $this->productCards[$p['cid']] ?? null))
                                <tr wire:key="ref-card-{{ $p['cid'] }}" class="border-b border-border-subtle bg-surface-2">
                                    <td colspan="10" class="px-4 py-2.5">
                                        @include('livewire.catalog._catalog-item-detail', ['cat' => $card['cat'], 'pc' => $card['pc'], 'iqp' => null, 'canIqot' => false, 'allowIqotAnalyze' => false, 'withSupplyHistory' => false])
                                        @include('livewire.suppliers._catalog-supplier-history', ['history' => $card['history']])
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="10" class="px-3 py-10 text-center text-fg-3 text-[13px]">
                                {{ trim($search) !== '' ? 'Ничего не найдено.' : 'Нет позиций с неактуальной ценой и спросом за последние 30 дней.' }}
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if(count($this->refreshList) > $perPage)
                <div class="px-4 py-3 text-[11.5px] text-fg-3 flex items-center gap-3">
                    <span>Показано {{ $perPage }} из {{ count($this->refreshList) }}</span>
                    <button type="button" wire:click="loadMore" class="btn btn-sm">Показать ещё</button>
                </div>
            @endif
        </div>
    @endif

    @if($tab === 'pricelists')
        {{-- Прайсы поставщиков через QuoteParts: поставщик загружает в своём кабинете, чёткие
             строки сразу становятся ценами, сомнительные ждут решения снабжения. --}}
        @php $plists = $this->quotePartsPriceLists; $pl = $this->quotePartsPriceList; @endphp
        <div class="ds-card">
            <div class="ds-card-header">
                <h3>Прайсы поставщиков</h3>
                <span class="text-[12px] text-fg-3 ml-2">QuoteParts · поставщик загружает прайс в своём кабинете</span>
            </div>
            <div class="ds-card-body space-y-2 text-[13px]">
                @if(isset($plists[0]['error']))
                    <div class="text-red-700">QuoteParts не ответил: {{ $plists[0]['error'] }}</div>
                @elseif($plists === [])
                    <div class="text-fg-4">Прайсов пока нет. Их загружают поставщики в кабинете QuoteParts — на странице цен для Моего Зипа.</div>
                @else
                    <table class="w-full text-[12.5px]">
                        <thead class="text-[10.5px] uppercase tracking-wider text-fg-3 text-left">
                            <tr><th class="py-1 pr-3">Поставщик</th><th class="py-1 pr-3">Файл</th><th class="py-1 pr-3">Загружен</th><th class="py-1 pr-3">Цен записано</th><th class="py-1 pr-3">На проверку</th><th class="py-1">Не найдено</th></tr>
                        </thead>
                        <tbody>
                            @foreach($plists as $l)
                                @php $s = $l['stats'] ?? []; @endphp
                                <tr wire:key="qppl-{{ $l['id'] }}" class="border-t border-border-subtle cursor-pointer hover:bg-hover {{ $openPriceList === $l['id'] ? 'bg-surface-2' : '' }}" wire:click="$set('openPriceList', {{ $l['id'] }})">
                                    <td class="py-1.5 pr-3">{{ $l['supplier_name'] ?? $l['supplier'] }}</td>
                                    <td class="py-1.5 pr-3">{{ $l['file'] }}</td>
                                    <td class="py-1.5 pr-3 text-fg-3">{{ \Illuminate\Support\Carbon::parse($l['uploaded_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') }}</td>
                                    <td class="py-1.5 pr-3">{{ $l['status'] === 'matching' ? 'сопоставляется…' : (($s['exact'] ?? 0) + ($s['accepted'] ?? 0)) }}</td>
                                    <td class="py-1.5 pr-3 {{ ($s['check'] ?? 0) > 0 ? 'text-amber-700 font-medium' : '' }}">{{ $s['check'] ?? 0 }}</td>
                                    <td class="py-1.5 text-fg-3">{{ $s['none'] ?? 0 }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        @if($pl)
            <div class="ds-card">
                <div class="ds-card-header">
                    <h3>{{ $pl['file'] }}</h3>
                    <span class="text-[12px] text-fg-3 ml-2">{{ $pl['defaults']['currency'] ?? '' }}{{ ! empty($pl['defaults']['valid_until']) ? ' · цены до '.\Illuminate\Support\Carbon::parse($pl['defaults']['valid_until'])->format('d.m.Y') : '' }}{{ isset($pl['defaults']['lead_time_days']) && $pl['defaults']['lead_time_days'] !== null ? ' · срок '.$pl['defaults']['lead_time_days'].' дн.' : '' }}</span>
                </div>
                <div class="ds-card-body overflow-x-auto">
                    <table class="w-full text-[12.5px]">
                        <thead class="text-[10.5px] uppercase tracking-wider text-fg-3 text-left">
                            <tr><th class="py-1 pr-2">#</th><th class="py-1 pr-2">В прайсе</th><th class="py-1 pr-2">Цена</th><th class="py-1 pr-2">Наша позиция</th><th class="py-1 pr-2">Статус</th><th class="py-1"></th></tr>
                        </thead>
                        <tbody>
                            @foreach(collect($pl['rows'] ?? [])->sortBy(fn ($r) => [$r['status'] === 'check' ? 0 : ($r['status'] === 'none' ? 1 : 2), $r['row']]) as $r)
                                <tr wire:key="qpplr-{{ $r['id'] }}" class="border-t border-border-subtle align-top {{ $r['status'] === 'check' ? 'bg-amber-50/60' : '' }}">
                                    <td class="py-1.5 pr-2 text-fg-4">{{ $r['row'] }}</td>
                                    <td class="py-1.5 pr-2">{{ $r['name'] }}<div class="text-[11px] text-fg-3 mono">{{ collect([$r['our_code'], $r['part_number'], $r['supplier_sku']])->filter()->implode(' · ') }}</div></td>
                                    <td class="py-1.5 pr-2 mono whitespace-nowrap">{{ $r['price'] !== null ? number_format($r['price'], 2, ',', ' ').' '.$r['currency'] : '—' }}</td>
                                    <td class="py-1.5 pr-2">@if($r['item_code'])<span class="mono">{{ $r['item_code'] }}</span> <span class="text-fg-3">{{ \Illuminate\Support\Str::limit($r['item_name'] ?? '', 50) }}</span>@else<span class="text-fg-4">—</span>@endif</td>
                                    <td class="py-1.5 pr-2">
                                        @php $labels = ['exact' => 'цена записана', 'accepted' => 'принято', 'check' => 'на проверку', 'none' => 'не найдено', 'skipped' => 'пропущено', 'rejected' => 'отклонено', 'pending' => 'ждёт']; @endphp
                                        <span class="{{ in_array($r['status'], ['exact', 'accepted'], true) ? 'text-emerald-700' : ($r['status'] === 'check' ? 'text-amber-700' : 'text-fg-3') }}">{{ $labels[$r['status']] ?? $r['status'] }}</span>
                                        @if($r['reason'])<div class="text-[11px] text-fg-3">{{ $r['reason'] }}</div>@endif
                                    </td>
                                    <td class="py-1.5 whitespace-nowrap">
                                        @if(in_array($r['status'], ['check', 'none'], true))
                                            <input type="text" wire:model="priceRowCode.{{ $r['id'] }}" placeholder="{{ $r['candidates'][0] ?? 'M-артикул' }}" class="h-[26px] w-[90px] px-1.5 border border-border rounded bg-surface text-[12px] mono">
                                            <button type="button" wire:click="acceptPriceRow({{ $r['id'] }})" class="btn btn-xs btn-primary" title="Принять цену{{ $r['item_code'] ? ' для '.$r['item_code'] : '' }} (или для указанного M-артикула)">Принять</button>
                                            <button type="button" wire:click="rejectPriceRow({{ $r['id'] }})" class="btn btn-xs">Отклонить</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif

    @if($tab === 'offers')
        <livewire:procurement.supplier-offers-feed />
    @endif

    @if($tab === 'exports')
        <livewire:procurement.price-exports />
    @endif

    @if($tab === 'monitor')
        {{-- Мониторинг цен: что стоит на регулярном перезапросе. --}}
        <div class="ds-card">
            <div class="ds-card-header">
                <h2 class="text-[16px] font-semibold text-fg-1">🔁 Автоматический мониторинг цен</h2>
                <span class="text-[12px] text-fg-3 ml-2">регулярный перезапрос цены у поставщиков</span>
            </div>
            <div class="ds-card-body">
                <div class="flex flex-wrap gap-6 text-[13px]">
                    <div><span class="text-[22px] font-semibold text-fg-1 mono">{{ $monSum['active'] }}</span> <span class="text-fg-3">позиций на мониторинге</span></div>
                    <div><span class="text-[22px] font-semibold text-amber-700 mono">{{ $monSum['stale'] }}</span> <span class="text-fg-3">из них цена сейчас неактуальна</span></div>
                    <div><span class="text-[22px] font-semibold text-sky-700 mono">{{ $monSum['due'] }}</span> <span class="text-fg-3">ждут ближайшего прогона</span></div>
                </div>
                <div class="text-[11.5px] text-fg-4 mt-2">
                    Запрос уходит тем же поставщикам, которым его отправили при включении мониторинга.
                    Планировщик проверяет сроки раз в час. Мониторинг не снимается сам, когда цена
                    стала актуальной — через период она снова устареет; выключается кнопкой.
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Поиск: артикул / наименование"
                   class="h-[32px] w-full max-w-[340px] px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
        </div>

        <div class="ds-card">
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="text-left px-3 py-2">Артикул</th>
                            <th class="text-left px-3 py-2">Позиция</th>
                            <th class="text-left px-3 py-2">Цена сейчас</th>
                            <th class="text-left px-3 py-2">Последний запрос</th>
                            <th class="text-left px-3 py-2">Следующий</th>
                            <th class="text-right px-3 py-2">Период</th>
                            <th class="text-left px-3 py-2">Включил</th>
                            <th class="text-right px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->monitors as $m)
                            @php $ci = $m->catalogItem; $left = $m->daysLeft(); @endphp
                            <tr wire:key="mon-{{ $m->id }}" class="border-b border-border-subtle hover:bg-hover {{ $m->is_active ? '' : 'opacity-60' }}">
                                <td class="px-3 py-2 mono whitespace-nowrap">{{ $ci?->sku ?? '—' }}</td>
                                <td class="px-3 py-2 text-fg-2">
                                    <span class="inline-block max-w-[360px] truncate align-bottom" title="{{ $ci?->name }}">{{ $ci?->name ?? '—' }}</span>
                                    @if($ci?->stock_available > 0)<span class="chip chip-ok text-[10px] ml-1">на складе {{ $ci->stock_available }}</span>@endif
                                </td>
                                <td class="px-3 py-2">
                                    @if($ci?->is_price_actual)
                                        <span class="chip chip-ok text-[10.5px]">актуальна</span>
                                    @else
                                        <span class="chip chip-warn text-[10.5px]">неактуальна</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 mono text-fg-3 whitespace-nowrap">
                                    {{ $m->last_dispatched_at?->format('d.m.Y') ?? '—' }}
                                </td>
                                <td class="px-3 py-2 mono whitespace-nowrap">
                                    @if(! $m->is_active)
                                        <span class="text-fg-4">выключен</span>
                                    @elseif($left === null)
                                        <span class="text-fg-4">—</span>
                                    @elseif($left <= 0)
                                        <span class="text-sky-700">в очереди</span>
                                    @else
                                        {{ $m->next_due_at->format('d.m.Y') }} <span class="text-fg-4">({{ $left }} дн.)</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <input type="number" min="{{ \App\Models\PriceMonitor::MIN_INTERVAL_DAYS }}"
                                           max="{{ \App\Models\PriceMonitor::MAX_INTERVAL_DAYS }}"
                                           value="{{ $m->interval_days }}"
                                           wire:change="updateMonitorInterval({{ $m->id }}, $event.target.value)"
                                           class="h-[26px] w-[70px] px-1.5 border border-border rounded-md bg-surface text-[12px] text-right outline-none focus:border-sky-500"
                                           title="Период перезапроса, дней">
                                </td>
                                <td class="px-3 py-2 text-fg-3 whitespace-nowrap">{{ $m->createdBy?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    @if($m->is_active)
                                        <button type="button" wire:click="monitorNow({{ $m->id }})"
                                                class="text-[11.5px] text-sky-700 hover:underline mr-2">запросить сейчас</button>
                                        <button type="button" wire:click="disableMonitor({{ $m->id }})"
                                                wire:confirm="Выключить мониторинг по этой позиции?"
                                                class="text-[11.5px] text-red-600 hover:underline">выключить</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-3 py-10 text-center text-fg-3 text-[13px]">
                                {{ trim($search) !== '' ? 'Ничего не найдено.' : 'Пока ничего не стоит на мониторинге. Включите его при отправке запроса поставщикам.' }}
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @include('livewire.procurement._supplier-request-panel')
</div>
