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
                                @if($inq->reply_state === 'question_to_us')
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
                            <td class="px-2 py-2 text-fg-1">{{ \Illuminate\Support\Str::limit($p['name'], 64) }}</td>
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
                                    <div class="flex items-center gap-1.5">
                                        @if($resp['state'] === 'quoted')
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

    {{-- Панель запроса поставщикам (по выбранным позициям) — модальное окно,
         закреплённое внизу экрана. Стили позиционирования — инлайн, чтобы не
         зависеть от пересборки Tailwind на деплое (см. память tailwind-arbitrary). --}}
    @php $selPos = $this->selectedPositions; @endphp
    @if($selPos->isNotEmpty())
        {{-- Спейсер: резервируем место под закреплённую панель, чтобы sentinel и
             последние строки списка не оставались под ней. --}}
        <div aria-hidden="true" style="height:48vh"></div>

        {{-- @focus.window: карточку поставщика правят через ✎ в соседней вкладке —
             при возврате сюда молча перечитываем подбор (имя/язык/матрица). --}}
        <div wire:key="proc-rfq-panel" x-data="{ min: false }"
             style="position:fixed;left:0;right:0;bottom:0;z-index:40;pointer-events:none;padding:0 12px 12px">
            <div class="ds-card" @focus.window.debounce.500ms="$wire.refreshSupplierOptions()"
                 style="pointer-events:auto;max-width:1200px;margin:0 auto;box-shadow:0 -8px 30px rgba(0,0,0,.18);border-top-left-radius:12px;border-top-right-radius:12px;overflow:hidden">
                <div class="ds-card-header cursor-pointer select-none" @click="min = !min">
                    <h3>Запрос поставщикам</h3>
                    <span class="text-[12px] text-fg-3 ml-2">выбрано позиций: {{ $selPos->count() }}</span>
                    <span class="flex-1"></span>
                    <button type="button" @click.stop="min = !min" class="btn btn-sm"
                            x-text="min ? '▴ развернуть' : '▾ свернуть'"></button>
                    <button type="button" wire:click="clearSelection" @click.stop class="btn btn-sm ml-1"
                            title="Очистить весь выбор позиций">✕ очистить</button>
                </div>
            <div class="ds-card-body space-y-3" x-show="!min" style="max-height:40vh;overflow-y:auto">
                {{-- Поставщики --}}
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Поставщики <span class="text-fg-4">— подобраны по матрице под выбранные позиции; ✎ — карточка поставщика (правки подтянутся при возврате)</span></label>
                    <div class="border border-border rounded-md divide-y divide-border-subtle">
                        @forelse($this->supplierOptions as $o)
                            <label wire:key="sup-opt-{{ $o['id'] }}" class="flex items-start gap-2 px-3 py-2 cursor-pointer hover:bg-hover">
                                <input type="checkbox" wire:model.live="selectedSuppliers.{{ $o['id'] }}" class="mt-1">
                                <span class="flex-1">
                                    <span class="text-[13px] text-fg-1 font-medium">{{ $o['name'] }}</span>
                                    @if($o['matched'])<span class="chip chip-sky text-[10px] ml-1">подходит · {{ $o['item_count'] }} поз.</span>
                                    @else<span class="chip chip-neutral text-[10px] ml-1">добавлен вручную</span>@endif
                                    @if($o['email'])<span class="block text-[11px] text-fg-4 mono">{{ $o['email'] }}</span>@endif
                                </span>
                                <a href="{{ route('suppliers.registry-edit', $o['id']) }}" target="_blank" rel="noopener"
                                   class="mt-0.5 px-1 text-fg-4 hover:text-sky-700 text-[13px]"
                                   title="Открыть карточку поставщика в новой вкладке — реквизиты, язык, матрица подбора">✎</a>
                            </label>
                        @empty
                            <div class="px-3 py-3 text-[12px] text-amber-700">По выбранным позициям нет подходящих поставщиков — добавьте вручную ниже.</div>
                        @endforelse
                    </div>
                    <div class="mt-2">
                        <input type="search" wire:model.live.debounce.300ms="supplierSearch" placeholder="Добавить поставщика: название / email / домен"
                               class="h-[30px] w-full max-w-[420px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
                        @if($this->searchResults->isNotEmpty())
                            <div class="mt-1 border border-border rounded-md max-w-[420px] divide-y divide-border-subtle">
                                @foreach($this->searchResults as $s)
                                    <button type="button" wire:click="addSupplier({{ $s->id }})" class="w-full text-left px-3 py-1.5 hover:bg-hover text-[12.5px]">
                                        {{ $s->name ?: $s->email }} <span class="text-fg-4 mono text-[11px]">{{ $s->email }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Превью письма НА ТОМ ЯЗЫКЕ, на котором улетит (RU/EN по выбранным
                     поставщикам). Названия/артикул/кол-во — редактируемые. --}}
                @foreach($this->previewLanguages as $blk)
                    @php $rows = $this->previewRowsForLang($blk['lang']); @endphp
                    <div class="border border-border rounded-md p-3 bg-surface-2" wire:key="prev-{{ $blk['lang'] }}">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="inline-flex items-center gap-1.5 text-[11px] uppercase tracking-wider text-fg-3">
                                <span class="chip {{ $blk['lang'] === 'en' ? 'chip-info' : 'chip-neutral' }} text-[10px]">{{ $blk['lang'] === 'en' ? '🌐 ' : '' }}{{ $blk['label'] }}</span>
                                Превью письма
                            </span>
                            <span class="flex items-center gap-2">
                                @if($blk['lang'] === 'en')
                                    <button type="button" wire:click="translateToEnglish" wire:loading.attr="disabled" wire:target="translateToEnglish" class="btn btn-sm">
                                        <span wire:loading.remove wire:target="translateToEnglish">🌐 Перевести позиции (ИИ)</span>
                                        <span wire:loading wire:target="translateToEnglish">Перевожу…</span>
                                    </button>
                                @endif
                                @if(!empty($blk['suppliers']))
                                    <span class="text-[10.5px] text-fg-4 text-right truncate" style="max-width:200px" title="{{ implode(', ', $blk['suppliers']) }}">→ {{ \Illuminate\Support\Str::limit(implode(', ', $blk['suppliers']), 40) }}</span>
                                @endif
                            </span>
                        </div>

                        {{-- Обращение (редактируемое, на языке письма) --}}
                        <div class="mb-2">
                            <input type="text" wire:model.lazy="{{ $blk['greeting_model'] }}"
                                   class="w-full px-2 h-[28px] border border-border rounded bg-surface text-[12.5px] outline-none focus:border-sky-500">
                            <div class="text-[10px] text-fg-4 mt-0.5">{поставщик} → контактное лицо из карточки поставщика (если заполнено), иначе название</div>
                        </div>

                        {{-- Текст вопроса (редактируемый, на языке письма) --}}
                        <div class="mb-2">
                            <textarea wire:model.lazy="{{ $blk['intro_model'] }}" rows="2"
                                      class="w-full px-2 py-1.5 border border-border rounded bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                        </div>

                        <div class="flex items-center gap-2 text-[10px] uppercase tracking-wider text-fg-4 px-1 mb-1">
                            <span style="width:18px"></span>
                            <span style="width:18px"></span>
                            <span class="flex-1">Наименование</span>
                            <span style="width:150px">Артикул / OEM</span>
                            <span style="width:96px">Кол-во</span>
                            <span style="width:78px" title="M-артикул каталога — не вставляется в письмо">M-арт.</span>
                        </div>
                        <div class="space-y-1.5">
                            @foreach($rows as $i => $r)
                                @php $oem = $this->oemOptions[$r['cid']] ?? ['sku' => '', 'name' => '', 'options' => []]; @endphp
                                <div wire:key="prev-{{ $blk['lang'] }}-{{ $r['cid'] }}" x-data="{ oem: false }">
                                    <div class="flex items-center gap-2">
                                        <button type="button" wire:click="removePosition({{ $r['cid'] }})"
                                                class="text-fg-4 hover:text-red-600 text-[13px] leading-none text-center"
                                                style="width:18px" title="Убрать позицию из запроса">🗑</button>
                                        <span class="text-[11px] text-fg-4 text-right" style="width:18px">{{ $i + 1 }}.</span>
                                        <input type="text" wire:model.lazy="{{ $r['name_model'] }}.{{ $r['cid'] }}"
                                               class="flex-1 px-2 h-[28px] border rounded bg-surface text-[12.5px] outline-none focus:border-sky-500 {{ $r['cyrillic'] ? 'border-amber-400' : 'border-border' }}">
                                        @if($r['cyrillic'])
                                            <span class="chip chip-warn text-[10px]" title="Похоже на русское название — переведите для англоязычного поставщика">⚠ рус.</span>
                                        @endif
                                        <input type="text" wire:model.lazy="editedOem.{{ $r['cid'] }}" placeholder="—"
                                               class="px-2 h-[28px] border border-border rounded bg-surface text-[12px] mono outline-none focus:border-sky-500" style="width:150px">
                                        <input type="text" wire:model.lazy="{{ $r['qty_model'] }}.{{ $r['cid'] }}" placeholder="—"
                                               class="px-2 h-[28px] border border-border rounded bg-surface text-[12px] outline-none focus:border-sky-500" style="width:96px">
                                        {{-- M-артикул: показываем рядом, НЕ вставляем в письмо; клик — попап OEM --}}
                                        <button type="button" @click="oem = !oem" style="width:78px"
                                                class="chip chip-neutral mono text-[10px] truncate"
                                                title="M-артикул каталога (не идёт в письмо) — показать OEM-артикулы">{{ $oem['sku'] ?: '—' }}</button>
                                    </div>
                                    {{-- Превью позиции: название + все OEM-артикулы каталога с быстрым
                                         добавлением в поле «Артикул/OEM» письма. --}}
                                    <div x-show="oem" x-cloak class="mt-1 mb-1 p-2 rounded-md bg-surface border border-border-subtle"
                                         style="margin-left:44px">
                                        <div class="text-[11px] text-fg-2 font-medium mb-1.5">{{ $oem['name'] ?: '—' }}</div>
                                        @if(!empty($oem['options']))
                                            <div class="text-[10px] uppercase tracking-wider text-fg-4 mb-1">OEM-артикулы каталога — клик добавляет в поле «Артикул/OEM»</div>
                                            <div class="flex flex-wrap gap-1.5">
                                                @foreach($oem['options'] as $opt)
                                                    <button type="button" wire:click="addOem({{ $r['cid'] }}, @js($opt['article']))"
                                                            class="inline-flex items-center gap-1 px-2 py-1 rounded border border-border bg-surface-2 hover:bg-hover text-[11.5px]"
                                                            title="Добавить {{ $opt['article'] }} в поле артикула позиции">
                                                        <span class="text-sky-700 font-semibold">＋</span>
                                                        <span class="mono">{{ $opt['article'] }}</span>
                                                        @if($opt['brand'] !== '')<span class="text-fg-4 text-[10.5px]">· {{ $opt['brand'] }}</span>@endif
                                                    </button>
                                                @endforeach
                                            </div>
                                        @else
                                            <div class="text-[11.5px] text-fg-4">В каталоге нет отдельных OEM-артикулов для этой позиции.</div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        {{-- Закрывающая строка письма (редактируемая, на языке письма) --}}
                        <div class="mt-2">
                            <textarea wire:model.lazy="{{ $blk['closing_model'] }}" rows="2"
                                      class="w-full px-2 py-1.5 border border-border rounded bg-surface text-[11.5px] text-fg-3 outline-none focus:border-sky-500"></textarea>
                        </div>

                        @if($blk['lang'] === 'en')
                            <div class="text-[10.5px] text-fg-4 mt-2">Каталожные позиции — английское название (name_en). Остальные — кнопка «Перевести позиции (ИИ)» или вручную (⚠ помечены кириллицей).</div>
                        @endif
                    </div>
                @endforeach

                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Примечание <span class="text-fg-4">(необязательно)</span></label>
                    <textarea wire:model="note" rows="2" class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                </div>

                @error('send') <div class="text-[12px] text-red-600">{{ $message }}</div> @enderror

                <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                    @php $selSups = collect($this->selectedSuppliers)->filter()->count(); @endphp
                    <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send"
                            class="btn btn-primary" @disabled($selSups === 0)>
                        <span wire:loading.remove wire:target="send">Отправить запросы ({{ $selSups }})</span>
                        <span wire:loading wire:target="send">Отправляю…</span>
                    </button>
                    <span class="text-[11.5px] text-fg-3">по M-артикулу; цена обновится в каталоге → заявки получат сигнал «💰»</span>
                </div>
            </div>
            </div>
        </div>
    @endif
</div>
