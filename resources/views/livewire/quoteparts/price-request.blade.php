{{-- «Запросить цены» в разделе «QuoteParts»: позиции, поставщики, срок; количество не обязательно. --}}
<div>
    @if($open)
        @php
            $opts = $this->supplierOptions;
            $routes = $this->routes;
            $prevOrg = null;
            $pickedSup = count(array_filter($selectedSuppliers));
        @endphp
        <div class="ds-card" style="padding: 16px; border-color: var(--accent)">
            <div class="flex items-center gap-2 mb-3">
                <div class="text-[14px] font-semibold text-fg-1">💬 Запрос цен поставщикам</div>
                <span class="text-[12px] text-fg-3">позиций: {{ count($cids) }}</span>
                <button type="button" wire:click="close" class="btn btn-sm ml-auto">Закрыть</button>
            </div>

            <div class="text-[11px] uppercase tracking-wider text-fg-3 mb-1">Позиции · количество не обязательно: пусто — нужна актуальная цена за 1 шт.</div>
            <div class="flex items-center gap-2 text-[10px] uppercase tracking-wider text-fg-4 px-3 mb-1">
                <span style="width:18px"></span>
                <span style="width:78px" title="M-артикул каталога — клик показывает OEM-артикулы">M-арт.</span>
                <span class="flex-1">Наименование</span>
                <span style="width:190px" title="Артикул, который увидит поставщик">Артикул / OEM</span>
                <span style="width:80px">Кол-во</span>
            </div>
            <div class="border border-border rounded-md divide-y divide-border-subtle mb-2" style="max-height: 420px; overflow-y: auto">
                @forelse($this->items as $ci)
                    @php $oemOpts = $this->oemOptions[$ci->id] ?? []; @endphp
                    <div wire:key="pr-item-{{ $ci->id }}" x-data="{ oemOpen: false }" class="px-3 py-1.5">
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="removeItem({{ $ci->id }})" class="text-fg-4 hover:text-red-600 text-[13px] leading-none" style="width:18px" title="Убрать позицию из запроса">🗑</button>
                            <button type="button" @click="oemOpen = !oemOpen" class="chip chip-neutral mono text-[10px] truncate" style="width:78px"
                                    title="M-артикул каталога (в письмо не идёт) — показать OEM-артикулы">{{ $ci->sku }}</button>
                            <span class="flex-1 truncate text-[12.5px]" title="{{ $ci->name }}">{{ $ci->name }}@if($ci->brand)<span class="text-fg-4"> · {{ $ci->brand }}</span>@endif @if($ci->photo_url)<span class="text-fg-4" title="В каталоге есть фото">📷</span>@endif</span>
                            <input type="text" wire:model.lazy="oem.{{ $ci->id }}" placeholder="—"
                                   class="px-2 h-[28px] border border-border rounded bg-surface text-[12px] mono outline-none focus:border-sky-500" style="width:190px"
                                   title="Артикул для поставщика. Несколько — через запятую. Пусто — без артикула (внесистемным); системным останется артикул карточки QuoteParts">
                            <input type="text" wire:model.lazy="qty.{{ $ci->id }}" placeholder="—"
                                   class="px-2 h-[28px] border border-border rounded bg-surface text-[12px] text-right outline-none focus:border-sky-500" style="width:80px"
                                   title="Количество; пусто — без количества, нужна цена за 1 шт.">
                        </div>
                        <div x-show="oemOpen" x-cloak class="mt-1 mb-1 p-2 rounded-md bg-surface-2 border border-border-subtle" style="margin-left:26px">
                            @if($oemOpts !== [])
                                <div class="text-[10px] uppercase tracking-wider text-fg-4 mb-1">OEM-артикулы каталога — клик добавляет в поле «Артикул / OEM»</div>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($oemOpts as $opt)
                                        <button type="button" wire:click="addOem({{ $ci->id }}, @js($opt['article']))"
                                                class="inline-flex items-center gap-1 px-2 py-1 rounded border border-border bg-surface hover:bg-hover text-[11.5px]"
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
                @empty
                    <div class="px-3 py-3 text-[12px] text-fg-3">Позиций нет — добавьте артикулы ниже.</div>
                @endforelse
            </div>
            <div class="text-[11px] text-fg-4 mb-2">Системным поставщикам письмо пишет QuoteParts по карточке позиции: артикул, отличный от карточки, уйдёт строкой «Part No.: …» под позицией. Внесистемным — прямо в нашем письме.</div>
            <div class="flex items-center gap-2 mb-4">
                <input type="text" wire:model="skus" wire:keydown.enter="addSkus" placeholder="Добавить артикулы: M06374, M26773 …"
                       class="h-[30px] w-full max-w-[420px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
                <button type="button" wire:click="addSkus" class="btn btn-sm">Добавить</button>
                @error('skus')<span class="text-[12px] text-amber-700">{{ $message }}</span>@enderror
            </div>

            <div class="text-[11px] uppercase tracking-wider text-fg-3 mb-1">Поставщики</div>
            <div class="border border-border rounded-md divide-y divide-border-subtle" style="max-height: 360px; overflow-y: auto">
                @forelse($opts as $o)
                    @if($o['org_id'] && $o['org_id'] !== $prevOrg)
                        @php $orgIds = array_column(array_filter($opts, fn ($x) => $x['org_id'] === $o['org_id']), 'id'); $orgAll = $orgIds !== [] && collect($orgIds)->every(fn ($sid) => ! empty($selectedSuppliers[$sid])); @endphp
                        <div wire:key="pr-org-{{ $o['org_id'] }}" class="flex items-center gap-2 px-3 py-1.5 bg-surface-2">
                            <span class="text-[12.5px] font-semibold text-fg-1">🏢 {{ $o['org_name'] }}</span>
                            <span class="text-[11px] text-fg-3">адресов в списке: {{ $o['org_size'] }}</span>
                            <button type="button" wire:click="toggleOrganization({{ $o['org_id'] }})" class="btn btn-xs ml-auto">{{ $orgAll ? 'снять всех' : 'выбрать всех' }}</button>
                        </div>
                    @endif
                    @php $prevOrg = $o['org_id']; @endphp
                    <label wire:key="pr-sup-{{ $o['id'] }}" class="flex items-start gap-2 {{ $o['org_id'] ? 'pl-7' : 'pl-3' }} pr-3 py-2 cursor-pointer hover:bg-hover">
                        <input type="checkbox" wire:model.live="selectedSuppliers.{{ $o['id'] }}" class="mt-1">
                        <span class="flex-1">
                            <span class="text-[13px] text-fg-1 font-medium">{{ $o['name'] }}</span>
                            @if(isset($routes['quoteparts'][$o['id']]))<span class="chip chip-ok text-[10px] ml-1" title="Системный: запрос платформы с myzip@quoteparts.net, ответ формой или письмом">через QuoteParts</span>@endif
                            @if(isset($routes['offsystem'][$o['id']]))<span class="chip chip-info text-[10px] ml-1" title="Внесистемный: наше письмо с orders@ через QuoteParts, ответ разберёт платформа">через orders@</span>@endif
                            @if($o['matched'])<span class="chip chip-sky text-[10px] ml-1">подходит · {{ $o['item_count'] }} поз.</span>
                            @else<span class="chip chip-neutral text-[10px] ml-1">добавлен вручную</span>@endif
                            @if($o['email'])<span class="block text-[11px] text-fg-4 mono">{{ $o['email'] }}</span>@endif
                        </span>
                        <a href="{{ route('suppliers.registry-edit', $o['id']) }}" target="_blank" rel="noopener"
                           class="mt-0.5 px-1 text-fg-4 hover:text-sky-700 text-[13px]" title="Карточка поставщика в новой вкладке">✎</a>
                    </label>
                @empty
                    <div class="px-3 py-3 text-[12px] text-amber-700">По этим позициям нет подходящих поставщиков — добавьте вручную ниже.</div>
                @endforelse
            </div>
            <div class="mt-2 mb-4">
                <input type="search" wire:model.live.debounce.300ms="supplierSearch" placeholder="Добавить поставщика: название / email / домен"
                       class="h-[30px] w-full max-w-[420px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
                @include('partials.supplier-manual-search-results', ['results' => $this->searchResults])
            </div>

            <div class="mb-3">
                <label class="block text-[11.5px] text-fg-3 mb-1">Сообщение поставщикам <span class="text-fg-4">(необязательно · лучше по-английски)</span></label>
                <textarea wire:model="note" rows="4" class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                @error('note')<div class="text-[11.5px] text-red-600 mt-1">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="block text-[11.5px] text-fg-3 mb-1">Фото / файлы <span class="text-fg-4">(необязательно · прикрепятся к запросу каждому поставщику)</span></label>
                <input type="file" wire:model="rfqFiles" multiple accept="image/*,.pdf,.xlsx,.xls,.doc,.docx"
                       class="w-full text-[12px] border border-border rounded-md p-1.5 bg-surface">
                @error('rfqFiles.*')<div class="text-[11.5px] text-red-600 mt-1">{{ $message }}</div>@enderror
                <div wire:loading wire:target="rfqFiles" class="text-[11px] text-fg-3 mt-1">Загрузка…</div>
                @if(count($rfqFiles ?? []))
                    <div class="text-[11px] text-emerald-700 mt-1">Прикреплено файлов: {{ count($rfqFiles) }}</div>
                @endif
                <label class="flex items-center gap-1.5 mt-2 text-[11.5px] text-fg-2 cursor-pointer">
                    <input type="checkbox" wire:model="attachCatalogPhotos">
                    Приложить фото позиций из каталога <span class="text-fg-4">(📷 — есть фото)</span>
                </label>
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <label class="text-[12px] text-fg-3">Ответ до
                    <input type="date" wire:model="replyBy" min="{{ now()->toDateString() }}"
                           class="block h-[30px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
                </label>
                <span class="flex-1"></span>
                <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send" class="btn btn-primary"
                        @disabled($pickedSup === 0 || $cids === [])>
                    <span wire:loading.remove wire:target="send">Отправить: {{ count($cids) }} поз. · {{ $pickedSup }} пост.</span>
                    <span wire:loading wire:target="send">Отправляем…</span>
                </button>
            </div>
            @error('replyBy')<div class="mt-2 text-[12px] text-red-600">{{ $message }}</div>@enderror
            @error('send')<div class="mt-2 text-[12px] text-red-600">{{ $message }}</div>@enderror
            <div class="mt-2 text-[11.5px] text-fg-4">Системным поставщикам — запрос платформы с myzip@quoteparts.net, внесистемным — наше письмо с orders@. С ящика менеджера не уходит.</div>
        </div>
    @endif
</div>
