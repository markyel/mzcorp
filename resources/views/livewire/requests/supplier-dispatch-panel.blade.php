<div class="space-y-4">
    @php
        $items = $this->items;
        $opts = $this->supplierOptions;
        $atts = $this->requestAttachments;
        $staleCount = collect($items)->where('price_stale', true)->count();
        $selItems = collect($this->selectedItems)->filter()->count();
        $selSups = collect($this->selectedSuppliers)->filter()->count();
    @endphp

    @if(session('status'))
        <div class="ds-card"><div class="ds-card-body text-[13px] text-emerald-700">{{ session('status') }}</div></div>
    @endif

    {{-- 0. Уже отправленные запросы (кому + сколько позиций) --}}
    @if($this->sentInquiries->isNotEmpty())
        <div class="ds-card">
            <div class="ds-card-header"><h3>Запросы отправлены</h3><span class="text-[12px] text-fg-3 ml-2">{{ $this->sentInquiries->count() }} поставщ.</span></div>
            <div class="ds-card-body overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="text-left px-3 py-1.5">Поставщик</th>
                            <th class="text-right px-3 py-1.5">Позиций</th>
                            <th class="text-right px-3 py-1.5">Писем</th>
                            <th class="text-left px-3 py-1.5">Статус</th>
                            <th class="text-left px-3 py-1.5">Кто</th>
                            <th class="text-left px-3 py-1.5">Дата</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->sentInquiries as $si)
                            <tr wire:key="sent-{{ $si->id }}" class="border-b border-border-subtle hover:bg-hover">
                                <td class="px-3 py-1.5"><a href="{{ route('suppliers.show', $si->id) }}" wire:navigate class="text-sky-700 hover:underline">{{ $si->supplier_name ?: $si->supplier_email }}</a></td>
                                <td class="px-3 py-1.5 text-right mono text-fg-1 font-medium">{{ $si->items_count }}</td>
                                <td class="px-3 py-1.5 text-right mono text-fg-2">{{ $si->messages_count }}</td>
                                <td class="px-3 py-1.5"><span class="chip {{ $si->status === 'closed' ? 'chip-neutral' : 'chip-sky' }} text-[10.5px]">{{ $si->status === 'closed' ? 'закрыт' : 'открыт' }}</span></td>
                                <td class="px-3 py-1.5 text-fg-3 whitespace-nowrap">{{ $si->createdBy?->name ?? '—' }}</td>
                                <td class="px-3 py-1.5 text-fg-3 mono whitespace-nowrap">{{ $si->created_at?->format('d.m.Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- 0.5 Расценки от поставщиков (полученные предложения по позициям) --}}
    @php
        $offerRows = $this->offersByPosition;
        $offersTotal = collect($offerRows)->sum('received');
    @endphp
    @if($offersTotal > 0)
        <div class="ds-card">
            <div class="ds-card-header"><h3>Расценки от поставщиков</h3><span class="text-[12px] text-fg-3 ml-2">получено предложений: {{ $offersTotal }}</span></div>
            <div class="ds-card-body space-y-2">
                @foreach($offerRows as $row)
                    @if($row['received'] > 0)
                        <div class="border border-border-subtle rounded-md px-3 py-2" wire:key="offers-{{ $row['id'] }}">
                            <div class="flex items-start gap-2 flex-wrap">
                                <div class="flex-1 min-w-[240px]">
                                    <span class="text-[12.5px] text-fg-1 font-medium">{{ \Illuminate\Support\Str::limit($row['name'], 70) }}</span>
                                    @if($row['oem'])<span class="text-[11px] text-fg-4 mono ml-1">арт. {{ $row['oem'] }}</span>@endif
                                </div>
                                @if($row['best'] !== null)
                                    <span class="chip chip-ok text-[10.5px]" title="Лучшее предложение">от {{ number_format((float) $row['best'], 2, '.', ' ') }} {{ $row['currency'] ?: '₽' }}</span>
                                @endif
                            </div>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                @foreach($row['offers'] as $of)
                                    <div class="inline-flex items-center gap-1.5 border border-border-subtle rounded-md px-2 py-1 bg-surface text-[11.5px]">
                                        <a href="{{ route('suppliers.show', $of['inquiry_id']) }}" wire:navigate class="text-sky-700 hover:underline">{{ $of['supplier'] }}</a>
                                        @if($of['outcome'] === 'quoted')
                                            <span class="text-emerald-700 font-medium">{{ number_format((float) $of['price'], 2, '.', ' ') }} {{ $of['currency'] ?: '₽' }}</span>
                                            @if($of['lead'])<span class="text-fg-4">· {{ \Illuminate\Support\Str::limit($of['lead'], 24) }}</span>@endif
                                        @elseif($of['outcome'] === 'refused')
                                            <span class="text-red-700">отказ{{ $of['refusal'] ? ': '.\Illuminate\Support\Str::limit($of['refusal'], 28) : '' }}</span>
                                        @else
                                            <span class="text-fg-4">ждём</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
                <div class="text-[11px] text-fg-4 pt-1">Выбранную цену вносят в корп. базу (1С); после импорта каталога статус цены позиции станет «актуальная».</div>
            </div>
        </div>
    @endif

    {{-- 1. Позиции --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Позиции для запроса цен</h3>
            <span class="text-[12px] text-fg-3 ml-2">выбрано {{ $selItems }} из {{ count($items) }}</span>
            <span class="flex-1"></span>
            @if($staleCount > 0)
                <button type="button" wire:click="selectStale" class="btn btn-sm" title="Отметить только позиции с неактуальной ценой">⚠ Только неактуальные ({{ $staleCount }})</button>
            @endif
        </div>
        <div class="ds-card-body overflow-x-auto">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                    <tr>
                        <th class="px-2 py-2 w-[34px]"></th>
                        <th class="text-left px-2 py-2">Наименование</th>
                        <th class="text-left px-2 py-2">OEM / бренд</th>
                        <th class="text-right px-2 py-2">Кол-во</th>
                        <th class="text-left px-2 py-2">Цена</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $it)
                        <tr class="border-b border-border-subtle {{ $it['price_stale'] ? 'bg-amber-50' : '' }}">
                            <td class="px-2 py-2 text-center"><input type="checkbox" wire:model.live="selectedItems.{{ $it['id'] }}"></td>
                            <td class="px-2 py-2">
                                <div class="text-fg-1">{{ \Illuminate\Support\Str::limit($it['name'], 70) }}
                                    @if($it['requested'])<span class="chip chip-sky text-[10px] ml-1" title="Запрос уже отправлен — ждём предложение">📦 ждём</span>@endif
                                    @if($it['discontinued'])<span class="chip chip-warn text-[10px] ml-1" title="Все ответы поставщиков — отказ">🚫 возможно не поставляется</span>@endif
                                </div>
                                @if($it['client_name'])<div class="text-[11px] text-fg-4">клиент: {{ \Illuminate\Support\Str::limit($it['client_name'], 60) }}</div>@endif
                                @if($it['watched'])
                                    <button type="button" wire:click="toggleDiscontinued({{ $it['id'] }})"
                                            class="text-[10.5px] text-sky-700 hover:underline mt-0.5">
                                        {{ $it['discontinued'] ? 'Вернуть в подбор (поставляется)' : 'Пометить «не поставляется»' }}
                                    </button>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-fg-3">{{ trim(implode(' · ', array_filter([$it['oem'], $it['brand']]))) ?: '—' }}</td>
                            <td class="px-2 py-2 text-right mono">{{ $it['qty'] ?: '—' }}</td>
                            <td class="px-2 py-2">
                                @if(! $it['has_catalog'])
                                    <span class="text-[11px] text-fg-4">не в каталоге</span>
                                @elseif($it['price_stale'])
                                    <span class="chip chip-warn text-[10.5px]">неактуальна</span>
                                @else
                                    <span class="chip chip-ok text-[10.5px]">актуальна</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-fg-3">Нет активных позиций.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 2. Поставщики --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Поставщики</h3>
            <span class="text-[12px] text-fg-3 ml-2">подобраны по матрице под выбранные позиции · отмечено {{ $selSups }}</span>
        </div>
        <div class="ds-card-body space-y-3">
            @if($selItems === 0)
                <div class="text-[12.5px] text-fg-3">Сначала выберите позиции выше.</div>
            @else
                @php $dupCount = collect($items)->filter(fn ($i) => ($this->selectedItems[$i['id']] ?? false) && $i['requested'])->count(); @endphp
                @if($dupCount > 0)
                    <div class="text-[11.5px] text-amber-800 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                        ⚠ По {{ $dupCount }} из выбранных позиций запрос поставщику уже отправлен — не дублируйте без необходимости. Поставщики, от которых ждём ответ, помечены ниже.
                    </div>
                @endif
                <div class="border border-border rounded-md divide-y divide-border-subtle">
                    @forelse($opts as $o)
                        <label wire:key="sup-opt-{{ $o['id'] }}" class="flex items-start gap-2 px-3 py-2 cursor-pointer hover:bg-hover {{ ($o['already_awaiting'] ?? 0) > 0 ? 'bg-amber-50/60' : '' }}">
                            <input type="checkbox" wire:model.live="selectedSuppliers.{{ $o['id'] }}" class="mt-1">
                            <span class="flex-1">
                                <span class="text-[13px] text-fg-1 font-medium">{{ $o['name'] }}</span>
                                @if($o['matched'])
                                    <span class="chip chip-sky text-[10px] ml-1">подходит · {{ $o['item_count'] }} поз.</span>
                                @else
                                    <span class="chip chip-neutral text-[10px] ml-1">добавлен вручную</span>
                                @endif
                                @if(($o['already_awaiting'] ?? 0) > 0)
                                    <span class="chip chip-warn text-[10px] ml-1" title="Уже отправлен запрос — ждём ответ по этим позициям">⏳ уже ждём · {{ $o['already_awaiting'] }} поз.</span>
                                @endif
                                @if($o['email'])<span class="block text-[11px] text-fg-4 mono">{{ $o['email'] }}</span>@endif
                            </span>
                        </label>
                    @empty
                        <div class="px-3 py-3 text-[12px] text-amber-700">По выбранным позициям нет подходящих поставщиков — добавьте вручную ниже.</div>
                    @endforelse
                </div>

                {{-- Ручное добавление любого поставщика --}}
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Добавить поставщика вручную</label>
                    <input type="search" wire:model.live.debounce.300ms="supplierSearch" placeholder="Поиск: название / email / домен"
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
            @endif
        </div>
    </div>

    {{-- 3. Превью письма + вложения --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>Письмо запроса</h3></div>
        <div class="ds-card-body space-y-3">
            {{-- Превью письма НА ТОМ ЯЗЫКЕ, на котором улетит. Если выбраны
                 поставщики из разных языковых групп — несколько блоков. --}}
            @php $selItemsCount = collect($this->selectedItems)->filter()->count(); @endphp
            @if($selItemsCount === 0)
                <div class="text-[12px] text-fg-4 border border-border rounded-md p-3 bg-surface-2">Выберите позиции выше — появится превью письма.</div>
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
                                    <button type="button" wire:click="translateToEnglish" wire:loading.attr="disabled" wire:target="translateToEnglish"
                                            class="btn btn-sm" title="Перевести названия позиций на английский через ИИ">
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

                        {{-- Заголовки колонок --}}
                        <div class="flex items-center gap-2 text-[10px] uppercase tracking-wider text-fg-4 px-1 mb-1">
                            <span style="width:18px"></span>
                            <span class="flex-1">Наименование</span>
                            <span style="width:150px">Артикул / OEM</span>
                            <span style="width:96px">Кол-во</span>
                        </div>

                        {{-- Номенклатура — название (по языку) + артикул + кол-во, всё редактируемое --}}
                        <div class="space-y-1.5">
                            @foreach($rows as $i => $r)
                                <div class="flex items-center gap-2" wire:key="prev-{{ $blk['lang'] }}-{{ $r['id'] }}">
                                    <span class="text-[11px] text-fg-4 text-right" style="width:18px">{{ $i + 1 }}.</span>
                                    <input type="text" wire:model.lazy="{{ $r['name_model'] }}.{{ $r['id'] }}"
                                           class="flex-1 px-2 h-[28px] border rounded bg-surface text-[12.5px] outline-none focus:border-sky-500 {{ $r['cyrillic'] ? 'border-amber-400' : 'border-border' }}">
                                    @if($r['cyrillic'])
                                        <span class="chip chip-warn text-[10px]" title="Похоже на русское название — переведите для англоязычного поставщика">⚠ рус.</span>
                                    @endif
                                    <input type="text" wire:model.lazy="editedOem.{{ $r['id'] }}" placeholder="—"
                                           class="px-2 h-[28px] border border-border rounded bg-surface text-[12px] mono outline-none focus:border-sky-500" style="width:150px">
                                    <input type="text" wire:model.lazy="{{ $r['qty_model'] }}.{{ $r['id'] }}" placeholder="—"
                                           class="px-2 h-[28px] border border-border rounded bg-surface text-[12px] outline-none focus:border-sky-500" style="width:96px">
                                </div>
                            @endforeach
                        </div>
                        @if($blk['lang'] === 'en')
                            <div class="text-[10.5px] text-fg-4 mt-2">Каталожные позиции — английское название (name_en). Остальные — кнопка «Перевести позиции (ИИ)» или вручную (⚠ помечены кириллицей). Артикул и кол-во правятся при неверном распознавании.</div>
                        @endif
                    </div>
                @endforeach
            @endif

            <div>
                <label class="block text-[11.5px] text-fg-3 mb-1">Примечание <span class="text-fg-4">(необязательно)</span></label>
                <textarea wire:model="note" rows="2" placeholder="Напр.: срочно; нужен аналог; уточните срок доставки"
                          class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
            </div>

            {{-- Вложения заявки: компактные превью с лайтбоксом (как в «Переписке»),
                 для остальных — ссылка скачать. Лайтбокс — общий (open-image). --}}
            @if($atts->isNotEmpty())
                @php
                    $imgExt = ['jpg','jpeg','png','gif','webp','bmp','tif','tiff','svg'];
                    $isImg = fn ($a) => ($a->mime_type && \Illuminate\Support\Str::startsWith(strtolower($a->mime_type), 'image/'))
                        || in_array(strtolower(\Illuminate\Support\Str::afterLast($a->filename, '.')), $imgExt, true);
                    $imgGallery = $atts->filter($isImg)->values()->map(fn ($a) => [
                        'src' => route('attachments.preview', $a->id),
                        'name' => $a->filename,
                        'dl' => route('attachments.download', $a->id),
                    ])->all();
                    $imgIdx = 0;
                @endphp
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Файлы из заявки</label>
                    <div class="flex flex-wrap gap-2" x-data="{ items: @js($imgGallery) }">
                        @foreach($atts as $a)
                            <div class="border border-border rounded-md overflow-hidden {{ ($selectedAttachments[$a->id] ?? false) ? 'ring-1 ring-sky-400' : '' }} bg-surface" style="width:116px">
                                @if($isImg($a))
                                    <button type="button"
                                            x-on:click="$dispatch('open-image', { items: items, index: {{ $imgIdx }} })"
                                            class="block text-left" title="Просмотр: {{ $a->filename }}" style="width:116px">
                                        <img src="{{ route('attachments.preview', $a->id) }}" alt="{{ $a->filename }}" loading="lazy"
                                             style="width:116px;height:80px;object-fit:cover;display:block">
                                    </button>
                                    @php $imgIdx++; @endphp
                                @else
                                    <a href="{{ route('attachments.download', $a->id) }}" target="_blank" rel="noopener"
                                       class="flex items-center justify-center bg-surface-2 text-sky-700 hover:bg-hover text-[11px]" style="width:116px;height:80px">
                                        <span class="text-center"><span class="text-[18px] block">📄</span>{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::afterLast($a->filename, '.')) ?: 'BIN' }}</span>
                                    </a>
                                @endif
                                <label class="flex items-center gap-1 px-1.5 py-1 cursor-pointer border-t border-border-subtle">
                                    <input type="checkbox" wire:model.live="selectedAttachments.{{ $a->id }}">
                                    <span class="text-[10.5px] text-fg-2 truncate" title="{{ $a->filename }}">{{ \Illuminate\Support\Str::limit($a->filename, 12) }}</span>
                                </label>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Файлы с диска --}}
            <div>
                <label class="block text-[11.5px] text-fg-3 mb-1">Прикрепить файлы с диска</label>
                <input type="file" wire:model="newFiles" multiple class="text-[12px]">
                @error('newFiles.*') <div class="text-[11px] text-red-600 mt-0.5">{{ $message }}</div> @enderror
                @if(count($newFiles) > 0)
                    <div class="flex flex-wrap gap-2 mt-1.5">
                        @foreach($newFiles as $idx => $f)
                            <span class="inline-flex items-center gap-1 text-[11.5px] bg-surface border border-border rounded px-2 py-0.5">
                                {{ \Illuminate\Support\Str::limit($f->getClientOriginalName(), 30) }}
                                <button type="button" wire:click="removeNewFile({{ $idx }})" class="text-red-600">×</button>
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            @error('send') <div class="text-[12px] text-red-600">{{ $message }}</div> @enderror

            <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                <button type="button" wire:click="openEmailPreview" wire:loading.attr="disabled" wire:target="openEmailPreview,newFiles"
                        class="btn" @disabled($selItems === 0 || $selSups === 0)
                        title="Посмотреть письмо каждого поставщика — ровно как оно уйдёт">
                    <span wire:loading.remove wire:target="openEmailPreview">👁 Предпросмотр письма</span>
                    <span wire:loading wire:target="openEmailPreview">Собираю…</span>
                </button>
                <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send,newFiles"
                        class="btn btn-primary" @disabled($selItems === 0 || $selSups === 0)>
                    <span wire:loading.remove wire:target="send">Отправить запросы ({{ $selSups }})</span>
                    <span wire:loading wire:target="send">Отправляю…</span>
                </button>
                <span class="text-[11.5px] text-fg-3">каждому поставщику — отдельное письмо с его позициями</span>
            </div>
        </div>
    </div>

    {{-- ─────────── МОДАЛКА «Предпросмотр письма» ───────────
         Рендерит РОВНО то, что уйдёт (та же view emails.supplier-rfq, те же
         правки/язык/обращение) — по табу на каждого отмеченного поставщика.
         x-teleport=body обязателен: у предков есть transform, fixed без
         телепорта уезжает в подвал (см. плавающий композер). --}}
    @if($previewOpen && $this->emailPreview)
        @php $p = $this->emailPreview; @endphp
        <template x-teleport="body">
            <div style="position: fixed; inset: 0; z-index: 70; background: rgba(15, 23, 42, 0.45);
                        display: flex; align-items: center; justify-content: center; padding: 16px;"
                 wire:click.self="closeEmailPreview">
                <div style="width: min(780px, 96vw); max-height: 92vh; display: flex; flex-direction: column;
                            background: var(--bg-surface); border: 1px solid var(--border-strong);
                            border-radius: 10px; box-shadow: 0 18px 50px rgba(15, 23, 42, 0.35); overflow: hidden;">
                    {{-- Шапка + табы поставщиков --}}
                    <div style="flex: 0 0 auto; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
                                padding: 10px 14px; background: var(--bg-surface-2); border-bottom: 1px solid var(--border-subtle);">
                        <span class="text-[13px] font-semibold text-fg-1">👁 Предпросмотр письма</span>
                        @foreach($this->previewSupplierTabs as $tab)
                            <button type="button" wire:click="setPreviewSupplier({{ $tab['id'] }})"
                                    class="chip {{ $tab['id'] === $previewSupplierId ? 'chip-info' : 'chip-neutral' }}">
                                {{ \Illuminate\Support\Str::limit($tab['label'], 28) }}@if($tab['lang'] === 'en') · EN @endif
                            </button>
                        @endforeach
                        <span style="flex: 1;"></span>
                        <button type="button" wire:click="closeEmailPreview"
                                class="text-fg-3 hover:text-fg-1 text-[14px]" style="padding: 2px 6px; line-height: 1;">✕</button>
                    </div>

                    {{-- Кому / Тема --}}
                    <div style="flex: 0 0 auto; padding: 8px 14px; border-bottom: 1px solid var(--border-subtle);"
                         class="text-[12.5px]">
                        <div><span class="text-fg-3 uppercase text-[11px] tracking-wider font-semibold">Кому:</span>
                            <span class="text-fg-1 mono">{{ $p['supplier'] }} &lt;{{ $p['to'] }}&gt;</span></div>
                        <div class="mt-0.5"><span class="text-fg-3 uppercase text-[11px] tracking-wider font-semibold">Тема:</span>
                            <span class="text-fg-1">{{ $p['subject'] }}</span></div>
                    </div>

                    {{-- Само письмо (изолированно в iframe — стили письма не текут в CRM) --}}
                    <iframe sandbox="" srcdoc="{{ $p['html'] }}"
                            style="flex: 1 1 auto; width: 100%; min-height: 320px; height: 52vh; border: none; background: #f5f6f8;"></iframe>

                    {{-- Вложения + подпись --}}
                    <div style="flex: 0 0 auto; padding: 8px 14px; border-top: 1px solid var(--border-subtle);" class="text-[12px]">
                        @if(count($p['attachments']) > 0)
                            <div class="text-fg-2">📎 Вложения ({{ count($p['attachments']) }}):
                                {{ \Illuminate\Support\Str::limit(implode(', ', $p['attachments']), 160) }}</div>
                        @endif
                        <div class="text-fg-3 mt-0.5">К письму автоматически добавится подпись менеджера.</div>
                    </div>

                    {{-- Действия --}}
                    <div style="flex: 0 0 auto; display: flex; align-items: center; gap: 8px;
                                padding: 10px 14px; background: var(--bg-surface-2); border-top: 1px solid var(--border-subtle);">
                        <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send"
                                class="btn btn-primary">
                            <span wire:loading.remove wire:target="send">Отправить запросы ({{ $selSups }})</span>
                            <span wire:loading wire:target="send">Отправляю…</span>
                        </button>
                        <button type="button" wire:click="closeEmailPreview" class="btn">Вернуться к правкам</button>
                    </div>
                </div>
            </div>
        </template>
    @endif
</div>
