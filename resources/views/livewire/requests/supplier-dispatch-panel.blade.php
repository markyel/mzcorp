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
                                </div>
                                @if($it['client_name'])<div class="text-[11px] text-fg-4">клиент: {{ \Illuminate\Support\Str::limit($it['client_name'], 60) }}</div>@endif
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
                <div class="border border-border rounded-md divide-y divide-border-subtle">
                    @forelse($opts as $o)
                        <label class="flex items-start gap-2 px-3 py-2 cursor-pointer hover:bg-hover">
                            <input type="checkbox" wire:model.live="selectedSuppliers.{{ $o['id'] }}" class="mt-1">
                            <span class="flex-1">
                                <span class="text-[13px] text-fg-1 font-medium">{{ $o['name'] }}</span>
                                @if($o['matched'])
                                    <span class="chip chip-sky text-[10px] ml-1">подходит · {{ $o['item_count'] }} поз.</span>
                                @else
                                    <span class="chip chip-neutral text-[10px] ml-1">добавлен вручную</span>
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
            {{-- Обращение (русский шаблон, редактируемый; для EN — фикс. «Hello …») --}}
            <div>
                <label class="block text-[11.5px] text-fg-3 mb-1">Обращение <span class="text-fg-4">(рус.) — {поставщик} подставится для каждого поставщика</span></label>
                <input type="text" wire:model.live.debounce.400ms="greeting" placeholder="Здравствуйте, {поставщик}!"
                       class="w-full px-2 h-[30px] border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
                <div class="text-[10.5px] text-fg-4 mt-0.5">Для англоязычных поставщиков используется «Hello {поставщик},».</div>
            </div>

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

                        {{-- Обращение (как улетит) --}}
                        <div class="text-[12.5px] text-fg-1 mb-2">{{ $blk['greeting'] }}</div>

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
                                    <input type="text" wire:model.lazy="editedQty.{{ $r['id'] }}" placeholder="—"
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
                <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send,newFiles"
                        class="btn btn-primary" @disabled($selItems === 0 || $selSups === 0)>
                    <span wire:loading.remove wire:target="send">Отправить запросы ({{ $selSups }})</span>
                    <span wire:loading wire:target="send">Отправляю…</span>
                </button>
                <span class="text-[11.5px] text-fg-3">каждому поставщику — отдельное письмо с его позициями</span>
            </div>
        </div>
    </div>
</div>
