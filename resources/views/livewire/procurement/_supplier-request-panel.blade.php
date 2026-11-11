{{-- Панель «Запрос поставщикам» (ComposesSupplierPriceRequest): «Снабжение» и «Запрос цен» раздела «QuoteParts». --}}
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
        {{-- Окно запроса: тянется за заголовок, размер — за уголок справа внизу; положение
             и размер запоминаются в браузере, «⤓» возвращает окно вниз. --}}
        <div wire:key="proc-rfq-panel"
             x-data="{ min: false, x: null, y: null, w: null, h: null, drag: null, rs: null, moved: false,
                      init() { try { const s = JSON.parse(localStorage.getItem('procRfqPanel') || 'null'); if (s) { this.x = s.x; this.y = s.y; this.w = s.w; this.h = s.h; } } catch (e) {} },
                      save() { try { localStorage.setItem('procRfqPanel', JSON.stringify({ x: this.x, y: this.y, w: this.w, h: this.h })); } catch (e) {} },
                      pin() { const r = this.$refs.card.getBoundingClientRect(); if (this.x === null) { this.x = r.left; this.y = r.top; this.w = r.width; } },
                      startDrag(e) { if (e.target.closest('button,input,select,textarea,a')) return; this.pin(); this.moved = false; this.drag = { dx: e.clientX - this.x, dy: e.clientY - this.y }; },
                      startResize(e) { this.pin(); const r = this.$refs.card.getBoundingClientRect(); this.rs = { sx: e.clientX, sy: e.clientY, w: r.width, h: r.height }; },
                      move(e) { if (this.drag) { this.moved = true; this.x = Math.max(0, Math.min(innerWidth - 120, e.clientX - this.drag.dx)); this.y = Math.max(0, Math.min(innerHeight - 48, e.clientY - this.drag.dy)); }
                                else if (this.rs) { this.w = Math.max(420, this.rs.w + e.clientX - this.rs.sx); this.h = Math.max(180, this.rs.h + e.clientY - this.rs.sy); } },
                      stop() { if (this.drag || this.rs) { this.drag = null; this.rs = null; this.save(); } },
                      dock() { this.x = this.y = this.w = this.h = null; this.save(); } }"
             @mousemove.window="move($event)" @mouseup.window="stop()"
             style="position:fixed;left:0;right:0;bottom:0;z-index:40;pointer-events:none;padding:0 12px 12px">
            <div class="ds-card" x-ref="card" @focus.window.debounce.500ms="$wire.refreshSupplierOptions()"
                 :style="x !== null ? { position: 'fixed', left: x + 'px', top: y + 'px', width: w + 'px', height: (h && !min) ? h + 'px' : 'auto', margin: '0', maxWidth: 'none', display: 'flex', flexDirection: 'column' } : {}"
                 style="pointer-events:auto;max-width:1200px;margin:0 auto;box-shadow:0 -8px 30px rgba(0,0,0,.18);border-top-left-radius:12px;border-top-right-radius:12px;overflow:hidden;position:relative">
                <div class="ds-card-header select-none" :class="x !== null ? 'cursor-move' : 'cursor-pointer'"
                     @mousedown="startDrag($event)" @click="if (!moved) min = !min; moved = false" title="Перетащите за заголовок, чтобы передвинуть окно">
                    <h3>Запрос поставщикам</h3>
                    <span class="text-[12px] text-fg-3 ml-2">выбрано позиций: {{ $selPos->count() }}</span>
                    <span class="flex-1"></span>
                    <button type="button" @click.stop="min = !min" class="btn btn-sm"
                            x-text="min ? '▴ развернуть' : '▾ свернуть'"></button>
                    <button type="button" wire:click="clearSelection" @click.stop class="btn btn-sm ml-1"
                            title="Очистить весь выбор позиций">✕ очистить</button>
                    <button type="button" x-show="x !== null" x-cloak @click.stop="dock()" class="btn btn-sm ml-1" title="Вернуть окно вниз страницы">⤓</button>
                </div>
                {{-- Уголок изменения размера окна. --}}
                <div @mousedown.prevent.stop="startResize($event)" x-show="!min" title="Потяните, чтобы изменить размер окна"
                     style="position:absolute;right:2px;bottom:2px;width:16px;height:16px;cursor:nwse-resize;z-index:5;background:linear-gradient(135deg,transparent 50%,var(--fg-4) 50%,var(--fg-4) 58%,transparent 58%,transparent 70%,var(--fg-4) 70%,var(--fg-4) 78%,transparent 78%);opacity:.6"></div>
            <div class="ds-card-body space-y-3" x-show="!min" :style="h && x !== null ? { flex: '1', maxHeight: 'none' } : {}" style="max-height:40vh;overflow-y:auto">
                @php $viaQp = ($panelChannelChoice ?? true) && $channel === 'quoteparts' && $this->quotePartsEnabled; @endphp
                {{-- Раздел «QuoteParts»: своего поиска по каталогу там нет — позиции дописываются списком. --}}
                @if($panelAddsItems ?? false)
                    <details @if($skuListReport && $skuListReport['missing'] !== []) open @endif>
                        <summary class="text-[12.5px] text-sky-700 cursor-pointer select-none">Добавить позиции списком артикулов</summary>
                        <div class="mt-2">
                            <textarea wire:model="skuList" rows="2" placeholder="M05333, M26889 — столбец из Excel, через запятую или пробел"
                                      class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] mono outline-none focus:border-sky-500"></textarea>
                            <div class="flex flex-wrap items-center gap-2 mt-1.5 text-[12px]">
                                <button type="button" wire:click="addCatalogSkus" wire:loading.attr="disabled" wire:target="addCatalogSkus" class="btn btn-sm">Найти и добавить</button>
                                @if($skuListReport)
                                    @if($skuListReport['empty'])<span class="text-amber-700">В тексте нет M-артикулов.</span>
                                    @else<span class="text-emerald-700">Добавлено: {{ $skuListReport['added'] }}</span>@if($skuListReport['already'] > 0)<span class="text-fg-3"> · уже были: {{ $skuListReport['already'] }}</span>@endif
                                        @if($skuListReport['missing'] !== [])<span class="text-red-700">· нет в каталоге: <span class="mono">{{ implode(', ', $skuListReport['missing']) }}</span></span>@endif
                                    @endif
                                @endif
                            </div>
                        </div>
                    </details>
                @endif
                {{-- Канал отправки: по умолчанию письмо, как раньше; QuoteParts — пилот. В разделе «QuoteParts»
                     выбора нет (заказчик, 11.11.2026): системным — запрос платформы, внесистемным — письмо с orders@. --}}
                @if($this->quotePartsEnabled && ($panelChannelChoice ?? true))
                    <div class="flex flex-wrap items-center gap-3 text-[12.5px]">
                        <span class="text-fg-3">Как отправить:</span>
                        <label class="inline-flex items-center gap-1.5 cursor-pointer">
                            <input type="radio" wire:model.live="channel" value="mail"> Письмом с нашего ящика
                        </label>
                        <label class="inline-flex items-center gap-1.5 cursor-pointer">
                            <input type="radio" wire:model.live="channel" value="quoteparts"> Через QuoteParts <span class="chip chip-sky text-[10px]">пилот</span>
                        </label>
                    </div>
                    @if($viaQp)
                        <div class="text-[11.5px] text-fg-3 p-2 rounded-md border border-sky-200 bg-sky-50/40">
                            Письмо поставщику QuoteParts составит сам по карточкам позиций — на языке контакта поставщика, с ящика MyZip, с формой ответа.
                            Несколько адресов одной организации — один получатель, кому писать решают её контакты в QuoteParts.
                            Кто ещё не на платформе, получит обычное письмо (режим переписки). Вложения и мониторинг цен для этого канала пока не работают.
                        </div>
                    @endif
                @endif
                {{-- Поставщики --}}
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Поставщики <span class="text-fg-4">— подобраны по матрице под выбранные позиции; ✎ — карточка поставщика (правки подтянутся при возврате)</span></label>
                    @if($this->supplierGroups !== [])
                        <div class="flex flex-wrap items-center gap-1.5 mb-2">
                            <span class="text-[11px] text-fg-4">Группы:</span>
                            @foreach($this->supplierGroups as $g)
                                <button type="button" wire:click="toggleSupplierGroup({{ $g['id'] }})" wire:key="sg-{{ $g['id'] }}"
                                        class="btn btn-xs {{ $g['selected'] ? 'btn-primary' : '' }}"
                                        title="{{ $g['selected'] ? 'Снять всех поставщиков группы' : 'Выбрать всех поставщиков группы' }}">{{ $g['selected'] ? '✓ ' : '' }}{{ $g['name'] }} · {{ count($g['ids']) }}</button>
                            @endforeach
                        </div>
                    @endif
                    @php $groupsOf = []; foreach ($this->supplierGroups as $sg) { foreach ($sg['ids'] as $sid) { $groupsOf[$sid][] = $sg['name']; } } @endphp
                @php
                    $qpRouting = app(\App\Services\QuoteParts\QuotePartsRouting::class);
                    $qpRouted = $qpRouting->routedIds(collect($this->supplierOptions)->pluck('id'));
                    $qpOffsystem = $qpRouting->offsystemIds(collect($this->supplierOptions)->pluck('id'));
                @endphp
                    <div class="border border-border rounded-md divide-y divide-border-subtle">
                        @php $orgOpts = $this->supplierOptions; $prevOrgOpt = null; @endphp
                        @forelse($orgOpts as $o)
                            @if($o['org_id'] && $o['org_id'] !== $prevOrgOpt)
                                @php $orgIds = array_column(array_filter($orgOpts, fn ($x) => $x['org_id'] === $o['org_id']), 'id'); $orgAll = $orgIds !== [] && collect($orgIds)->every(fn ($sid) => ! empty($selectedSuppliers[$sid])); @endphp
                                <div wire:key="sup-org-{{ $o['org_id'] }}" class="flex items-center gap-2 px-3 py-1.5 bg-surface-2">
                                    <span class="text-[12.5px] font-semibold text-fg-1">🏢 {{ $o['org_name'] }}</span>
                                    <span class="text-[11px] text-fg-3">адресов в списке: {{ $o['org_size'] }}</span>
                                    <button type="button" wire:click="toggleOrganizationSuppliers({{ $o['org_id'] }})" class="btn btn-xs ml-auto"
                                            title="Отметить или снять все адреса организации — письмо уйдёт на каждый">{{ $orgAll ? 'снять всех' : 'выбрать всех' }}</button>
                                </div>
                            @endif
                            @php $prevOrgOpt = $o['org_id']; @endphp
                            <label wire:key="sup-opt-{{ $o['id'] }}" class="flex items-start gap-2 {{ $o['org_id'] ? 'pl-7' : 'pl-3' }} pr-3 py-2 cursor-pointer hover:bg-hover">
                                <input type="checkbox" wire:model.live="selectedSuppliers.{{ $o['id'] }}" class="mt-1">
                                <span class="flex-1">
                                    <span class="text-[13px] text-fg-1 font-medium">{{ $o['name'] }}</span>
                                @if(isset($qpRouted[$o['id']]))<span class="chip chip-ok text-[10px] ml-1" title="Принял приглашение QuoteParts — запрос уйдёт через платформу, ответ формой или письмом">через QuoteParts</span>@endif@if(isset($qpOffsystem[$o['id']]))<span class="chip chip-info text-[10px] ml-1" title="Внесистемный поставщик — наше письмо уйдёт с orders@ через QuoteParts, ответ разберёт платформа, копия ответа — вам">через orders@</span>@endif
                                    @foreach($groupsOf[$o['id']] ?? [] as $gn)<span class="chip chip-neutral text-[10px] ml-1" title="Группа поставщиков">{{ $gn }}</span>@endforeach
                                    @if($o['matched'])<span class="chip chip-sky text-[10px] ml-1">подходит · {{ $o['item_count'] }} поз.</span>
                                    @else<span class="chip chip-neutral text-[10px] ml-1">добавлен вручную</span>@endif
                                    @if($viaQp)
                                        <span class="chip {{ ($o['qp_status'] ?? null) === 'active' ? 'chip-sky' : 'chip-neutral' }} text-[10px] ml-1" title="Статус в QuoteParts">
                                            {{ \App\Services\QuoteParts\QuotePartsSupplierService::STATUS_LABELS[$o['qp_status'] ?? ''] ?? 'не на платформе — переписка' }}
                                        </span>
                                    @endif
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
                        @include('partials.supplier-manual-search-results', ['results' => $this->searchResults])
                    </div>
                </div>

                {{-- Превью письма НА ТОМ ЯЗЫКЕ, на котором улетит (RU/EN по выбранным
                     поставщикам). Названия/артикул/кол-во — редактируемые. --}}
                @if($viaQp)
                    <div class="border border-border rounded-md p-3 bg-surface-2 space-y-1.5">
                        <div class="text-[11px] uppercase tracking-wider text-fg-3 mb-1">Позиции для QuoteParts</div>
                        @foreach($selPos as $i => $ci)
                            <div wire:key="qp-pos-{{ $ci->id }}" class="flex items-center gap-2">
                                <button type="button" wire:click="removePosition({{ $ci->id }})" class="text-fg-4 hover:text-red-600 text-[13px] leading-none" style="width:18px" title="Убрать позицию из запроса">🗑</button>
                                <span class="chip chip-neutral mono text-[10px]" style="width:78px">{{ $ci->sku }}</span>
                                <span class="flex-1 truncate text-[12.5px]">{{ $ci->name }}</span>
                                <input type="text" wire:model.lazy="editedQty.{{ $ci->id }}" placeholder="—"
                                       class="px-2 h-[28px] border border-border rounded bg-surface text-[12px] text-right outline-none focus:border-sky-500" style="width:80px"
                                       title="Количество; пусто — без количества, нужна цена за 1 шт.">
                            </div>
                        @endforeach
                        <div class="flex items-center gap-2 pt-1.5">
                            <span class="text-[12px] text-fg-3">Ответить до</span>
                            <input type="date" wire:model="qpReplyBy" class="h-[28px] px-2 border border-border rounded bg-surface text-[12px] outline-none focus:border-sky-500">
                        </div>
                        <div class="pt-1.5">@include('livewire.quote-parts._mirror-note')</div>
                    </div>
                @else
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
                            <span style="width:104px" title="M-артикул каталога — в письмо не идёт; «+» ставит его в начало названия">M-арт.</span>
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
                                        {{-- M-артикул: показываем рядом, сам в письмо не идёт; клик — попап OEM;
                                             «+» — поставить его в начало названия позиции в письме (prependSku: уже стоит — ✓). --}}
                                        <button type="button" @click="oem = !oem" style="width:78px"
                                                class="chip chip-neutral mono text-[10px] truncate"
                                                title="M-артикул каталога (не идёт в письмо) — показать OEM-артикулы">{{ $oem['sku'] ?: '—' }}</button>
                                        @if($oem['sku'])
                                            @php $skuInLetter = $this::startsWithSku((string) (($r['name_model'] === 'editedNamesEn' ? $editedNamesEn : $editedNames)[$r['cid']] ?? ''), $oem['sku']); @endphp
                                            <button type="button" wire:click="prependSku({{ $r['cid'] }}, @js($oem['sku']), @js($r['name_model']))" @disabled($skuInLetter)
                                                    title="{{ $skuInLetter ? 'M-артикул уже в начале названия' : 'Поставить M-артикул '.$oem['sku'].' в начало названия позиции в письме' }}"
                                                    style="width:22px; height:22px; display:inline-flex; align-items:center; justify-content:center; flex:none; border-radius:6px; font-size:13px; font-weight:600; line-height:1;
                                                           border:1px solid {{ $skuInLetter ? 'var(--border)' : 'var(--border-strong)' }}; background:var(--bg-surface); color:{{ $skuInLetter ? 'var(--status-ok-fg)' : 'var(--sky-700)' }}; cursor:{{ $skuInLetter ? 'default' : 'pointer' }}">{{ $skuInLetter ? '✓' : '+' }}</button>
                                        @else
                                            <span style="width:22px; flex:none"></span>
                                        @endif
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
                                        {{-- Карточка товара как в каталоге + кого уже спрашивали и у кого покупали. --}}
                                        <button type="button" wire:click="toggleCard({{ $r['cid'] }}, 'p')" class="btn btn-xs mt-2">
                                            {{ $this->isCardOpen($r['cid'], 'p') ? '▾ Скрыть карточку товара' : '▸ Карточка товара и история поставщиков' }}
                                        </button>
                                        @if($this->isCardOpen($r['cid'], 'p') && ($card = $this->productCards[$r['cid']] ?? null))
                                            <div class="mt-2 overflow-x-auto">
                                                @include('livewire.catalog._catalog-item-detail', ['cat' => $card['cat'], 'pc' => $card['pc'], 'iqp' => null, 'canIqot' => false, 'allowIqotAnalyze' => false, 'withSupplyHistory' => false])
                                                @include('livewire.suppliers._catalog-supplier-history', ['history' => $card['history']])
                                            </div>
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
                @endif

                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">{{ $viaQp ? 'Сообщение поставщику' : 'Примечание' }} <span class="text-fg-4">(необязательно{{ $viaQp ? ' · лучше по-английски' : '' }})</span></label>
                    <textarea wire:model="note" rows="2" class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                </div>

                @if(! $viaQp)
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Фото / файлы <span class="text-fg-4">(необязательно · прикрепятся к запросу каждому поставщику)</span></label>
                    <input type="file" wire:model="rfqFiles" multiple accept="image/*,.pdf,.xlsx,.xls,.doc,.docx"
                           class="w-full text-[12px] border border-border rounded-md p-1.5 bg-surface">
                    @error('rfqFiles.*') <div class="text-[11.5px] text-red-600 mt-1">{{ $message }}</div> @enderror
                    <div wire:loading wire:target="rfqFiles" class="text-[11px] text-fg-3 mt-1">Загрузка…</div>
                    @if(count($rfqFiles ?? []))
                        <div class="text-[11px] text-emerald-700 mt-1">Прикреплено файлов: {{ count($rfqFiles) }}</div>
                    @endif
                    <label class="flex items-center gap-1.5 mt-2 text-[11.5px] text-fg-2 cursor-pointer">
                        <input type="checkbox" wire:model="attachCatalogPhotos">
                        Приложить фото выбранных позиций из каталога
                    </label>
                </div>

                {{-- Мониторинг цен: позиции встанут на регулярный перезапрос тем же
                     поставщикам. Включается только при реально отправленном письме. --}}
                <label class="flex flex-wrap items-center gap-2 p-2.5 rounded-md border border-border bg-surface-2 text-[12.5px] cursor-pointer">
                    <input type="checkbox" wire:model.live="monitorEnabled" class="w-4 h-4 accent-sky-600">
                    <span class="font-medium text-fg-1">🔁 Включить мониторинг цен</span>
                    <span class="text-fg-3">перезапрашивать эти позиции раз в</span>
                    <input type="number" wire:model.live="monitorDays"
                           min="{{ \App\Models\PriceMonitor::MIN_INTERVAL_DAYS }}"
                           max="{{ \App\Models\PriceMonitor::MAX_INTERVAL_DAYS }}"
                           class="h-[26px] w-[68px] px-1.5 border border-border rounded-md bg-surface text-[12px] text-right outline-none focus:border-sky-500"
                           @disabled(! $monitorEnabled)>
                    <span class="text-fg-3">дн.</span>
                    @if($monitorEnabled)
                        <span class="text-[11.5px] text-fg-4 w-full">
                            Запрос уйдёт тем же поставщикам автоматически. Список и выключение —
                            в «Снабжении», вкладка «Мониторинг цен».
                        </span>
                    @endif
                </label>
                @endif

                @error('send') <div class="text-[12px] text-red-600">{{ $message }}</div> @enderror

                <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                    @php $selSups = collect($this->selectedSuppliers)->filter()->count(); @endphp
                    <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send"
                            class="btn btn-primary" @disabled($selSups === 0)>
                        <span wire:loading.remove wire:target="send">{{ $viaQp ? 'Отправить через QuoteParts' : 'Отправить запросы' }} ({{ $selSups }})</span>
                        <span wire:loading wire:target="send">Отправляю…</span>
                    </button>
                    @if($viaQp)
                        <span class="text-[11.5px] text-fg-3">ответы — в блоке «Запросы через QuoteParts» вверху раздела; в каталог цена пока не пишется</span>
                    @else
                        <span class="text-[11.5px] text-fg-3">по M-артикулу; цена обновится в каталоге → заявки получат сигнал «💰»</span>
                    @endif
                </div>
            </div>
            </div>
        </div>
    @endif
