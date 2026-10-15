<div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-[20px] font-semibold text-fg-1">QuoteParts</h1>
            <div class="text-[12px] text-fg-3">Платформа работы с поставщиками · тестирование. Передаём группы позиций из пула «К актуализации».</div>
        </div>
        <a href="https://quoteparts.ru/items" target="_blank" rel="noopener" class="btn btn-sm">Открыть QuoteParts ↗</a>
    </div>

    @unless($this->configured)
        <div class="ds-card"><div class="ds-card-body text-[13px] text-amber-800">
            Ключ API QuoteParts не задан (<span class="mono">QUOTEPARTS_API_TOKEN</span>) — позиции будут ждать передачи.
        </div></div>
    @endunless

    @php $counts = $this->poolCounts; @endphp
    <div class="flex flex-wrap items-center gap-2">
        <button type="button" wire:click="setTab('candidates')" class="btn btn-sm {{ $tab === 'candidates' ? 'btn-primary' : '' }}">
            К актуализации <span class="opacity-70">{{ count($this->candidates) }}</span>
        </button>
        <button type="button" wire:click="setTab('pool')" class="btn btn-sm {{ $tab === 'pool' ? 'btn-primary' : '' }}">
            В QuoteParts <span class="opacity-70">{{ array_sum($counts) }}</span>
            @if(($counts['paused'] ?? 0) > 0)<span class="chip chip-warn text-[10px] ml-1">на паузе {{ $counts['paused'] }}</span>@endif
            @if(($counts['error'] ?? 0) > 0)<span class="chip chip-danger text-[10px] ml-1">ошибка {{ $counts['error'] }}</span>@endif
        </button>
        <button type="button" wire:click="setTab('suppliers')" class="btn btn-sm {{ $tab === 'suppliers' ? 'btn-primary' : '' }}">
            Поставщики <span class="opacity-70">{{ $this->invitedSuppliers->count() }}</span>
        </button>
        <button type="button" wire:click="setTab('prices')" class="btn btn-sm {{ $tab === 'prices' ? 'btn-primary' : '' }}">Закупки 1С</button>
        <button type="button" wire:click="setTab('groups')" class="btn btn-sm {{ $tab === 'groups' ? 'btn-primary' : '' }}">Группы товаров</button>
        <button type="button" wire:click="setTab('pilot')" class="btn btn-sm {{ $tab === 'pilot' ? 'btn-primary' : '' }}">
            Пилот <span class="chip {{ $this->pilotSwitchedOn ? 'chip-ok' : 'chip-neutral' }} text-[10px] ml-1">{{ $this->pilotSwitchedOn ? 'вкл' : 'выкл' }}</span>
        </button>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Поиск: артикул / наименование / бренд"
               class="h-[32px] w-full max-w-[340px] px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
    </div>

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
                                    @else
                                        <span class="text-fg-4">—</span>
                                    @endif
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
                        @forelse($this->invitedSuppliers as $s)
                            <tr wire:key="qps-{{ $s->id }}" class="border-b border-border-subtle hover:bg-hover align-top">
                                <td class="px-3 py-2">
                                    <a href="{{ route('suppliers.registry-edit', $s) }}" class="text-sky-700 hover:underline">{{ $s->name ?: $s->email }}</a>
                                    <div class="text-[11px] text-fg-4">{{ $s->email }}</div>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="chip {{ $s->quoteparts_status === 'active' ? 'chip-ok' : ($s->quoteparts_status === 'terms_discussed' ? 'chip-warn' : ($s->quoteparts_status === 'declined' ? 'chip-danger' : 'chip-sky')) }} text-[10.5px]">{{ $labels[$s->quoteparts_status] ?? $s->quoteparts_status }}</span>
                                    @if($s->quoteparts_comment)<div class="mt-1 text-[11.5px] text-amber-800">«{{ $s->quoteparts_comment }}»</div>@endif
                                </td>
                                <td class="px-3 py-2 text-[11.5px] text-fg-3">{{ $s->quoteparts_invited_at?->format('d.m.Y H:i') }}</td>
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
