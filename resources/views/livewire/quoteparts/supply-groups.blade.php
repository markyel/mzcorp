<div class="space-y-3">
    @php $groups = \App\Models\SupplyGroup::ordered(); @endphp
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Группы товаров</h3>
            <span class="text-[12px] text-fg-3 ml-2">по поставщикам из «Закупок 1С»: китайским поставщикам — то, что везём из Китая, российским — что берём в России, европейским — что импортируем только из Европы</span>
        </div>
        <div class="ds-card-body text-[13px] space-y-3">
            <div class="flex flex-wrap items-center gap-6">
                @foreach($groups as $g)
                    <button type="button" wire:click="$set('group', '{{ $group === $g->code ? '' : $g->code }}')" @class(['text-left rounded-md px-2 py-1 hover:bg-hover', 'bg-[var(--bg-selected)] ring-1 ring-sky-300' => $group === $g->code]) title="Показать позиции группы">
                        <span class="text-[20px] font-semibold mono">{{ number_format($groupCounts[$g->code] ?? 0, 0, ',', ' ') }}</span> <span class="text-fg-3">позиций · {{ $g->name }}</span> <span class="text-sky-700 text-[12px]">{{ $group === $g->code ? 'скрыть' : 'показать' }}</span>
                    </button>
                @endforeach
                <button type="button" wire:click="$set('group', '{{ $group === 'none' ? '' : 'none' }}')" @class(['text-left rounded-md px-2 py-1 hover:bg-hover text-fg-3', 'bg-[var(--bg-selected)] ring-1 ring-sky-300' => $group === 'none'])>без группы <span class="text-sky-700 text-[12px]">{{ $group === 'none' ? 'скрыть' : 'показать' }}</span></button>
                <button type="button" wire:click="rebuild" wire:loading.attr="disabled" wire:target="rebuild" class="btn btn-sm btn-primary ml-auto">
                    <span wire:loading.remove wire:target="rebuild">Пересчитать</span><span wire:loading wire:target="rebuild">Считаю…</span>
                </button>
            </div>
            <p class="text-[12px] text-fg-3">
                Регион поставщика определяется сам — по стране в названии, по кириллическому имени (российская фирма) или по валюте закупок; поправьте, где неверно.
                Позиция попадает в «Китай», если её закупали у китайских поставщиков, в «Россию» — у российских, в «Европу» — если импортируем её из Европы и не из Китая; может быть в двух группах.
                Пересчёт идёт и сам — после каждой загрузки «Закупок 1С». Видимость групп поставщику — в его карточке, блок «QuoteParts».
            </p>
        </div>
    </div>

    {{-- Справочник групп: добавить, переименовать, правило «не если в группе …», удалить пустую. --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>Справочник групп</h3><span class="text-[12px] text-fg-3 ml-2">группа — она же регион поставщиков 1С; позиция может быть в нескольких группах</span></div>
        <div class="overflow-x-auto">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                    <tr><th class="text-left px-3 py-2">Название</th><th class="text-left px-3 py-2">Код</th><th class="text-right px-3 py-2">Позиций</th><th class="text-left px-3 py-2">Не включать автоматически, если позиция в группе</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach($groups as $g)
                        <tr wire:key="sg-{{ $g->id }}" class="border-b border-border-subtle">
                            <td class="px-3 py-1.5"><input type="text" value="{{ $g->name }}" maxlength="64" wire:change="renameGroup({{ $g->id }}, $event.target.value)" class="h-[28px] w-48 px-2 border border-border rounded-md bg-surface text-[12.5px]"></td>
                            <td class="px-3 py-1.5 mono text-fg-3">{{ $g->code }}</td>
                            <td class="px-3 py-1.5 text-right mono">{{ number_format($groupCounts[$g->code] ?? 0, 0, ',', ' ') }}</td>
                            <td class="px-3 py-1.5">
                                <select wire:change="setExclude({{ $g->id }}, $event.target.value)" class="h-[28px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px]">
                                    <option value="" @selected($g->exclude_if_code === null)>— включать всегда</option>
                                    @foreach($groups->where('id', '!=', $g->id) as $o)<option value="{{ $o->code }}" @selected($g->exclude_if_code === $o->code)>{{ $o->name }}</option>@endforeach
                                </select>
                            </td>
                            <td class="px-3 py-1.5 text-right">
                                @if(empty($groupCounts[$g->code]) && empty($regionCounts[$g->code]))
                                    <button type="button" wire:click="deleteGroup({{ $g->id }})" wire:confirm="Удалить группу «{{ $g->name }}»?" class="text-fg-4 hover:text-red-700 text-[11.5px]">удалить</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    <tr>
                        <td class="px-3 py-2" colspan="5">
                            <form wire:submit="addGroup" class="flex items-center gap-2">
                                <input type="text" wire:model="newGroup" maxlength="64" placeholder="Новая группа, например «Турция»" class="h-[28px] w-64 px-2 border border-border rounded-md bg-surface text-[12.5px]">
                                <button type="submit" class="btn btn-xs">Добавить группу</button>
                                @error('newGroup')<span class="text-red-700 text-[12px]">{{ $message }}</span>@enderror
                            </form>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Содержимое группы. --}}
    @if($groupItems !== null)
        @php $label = $group === 'none' ? 'Без группы' : (\App\Models\SupplyGroup::label($group) ?? $group); @endphp
        <div class="ds-card">
            <div class="ds-card-header flex flex-wrap items-center gap-2">
                <h3>Позиции группы «{{ $label }}»</h3>
                <span class="text-fg-3 text-[12px] ml-1">{{ number_format($groupItems->total(), 0, ',', ' ') }}</span>
                <input type="search" wire:model.live.debounce.300ms="groupSearch" placeholder="Артикул, название, бренд"
                       class="ml-auto h-[30px] w-64 px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
                <button type="button" wire:click="$set('group', '')" class="btn btn-sm btn-ghost">Закрыть</button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr><th class="text-left px-3 py-2" style="width:90px">Артикул</th><th class="text-left px-3 py-2">Наименование</th><th class="text-left px-3 py-2">Бренд</th><th class="text-left px-3 py-2">Группы</th><th class="text-left px-3 py-2">Поставщики 1С</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse($groupItems as $it)
                            @php $rows = collect($itemGroupsMap[$it->id] ?? []); $sup = $itemSuppliers->get($it->id, collect()); @endphp
                            @php $isOpen = in_array($it->id, $openItems, true); @endphp
                            <tr wire:key="gi-{{ $it->id }}" @class(['border-b border-border-subtle align-top', 'bg-[var(--bg-selected)]' => $isOpen])>
                                <td class="px-3 py-1.5 mono"><button type="button" wire:click="toggleItem({{ $it->id }})" class="text-left hover:text-sky-700"><span class="text-fg-4 text-[10px] mr-0.5">{{ $isOpen ? '▾' : '▸' }}</span>{{ $it->sku }}</button></td>
                                <td class="px-3 py-1.5 text-fg-1"><button type="button" wire:click="toggleItem({{ $it->id }})" class="text-left hover:text-sky-700" title="Карточка товара и история предложений поставщиков">{{ \Illuminate\Support\Str::limit($it->name, 80) }}</button></td>
                                <td class="px-3 py-1.5 text-fg-3">{{ $it->brand ?: '—' }}@if($it->brand_article) <span class="mono">{{ $it->brand_article }}</span>@endif</td>
                                <td class="px-3 py-1.5 whitespace-nowrap">
                                    @foreach($rows as $gr)<span class="chip chip-sky text-[10.5px] mr-1">{{ \App\Models\SupplyGroup::label($gr->group_code) ?? $gr->group_code }}</span>@endforeach
                                    @if($rows->contains('source', 'manual'))<span class="chip chip-neutral text-[10px]">вручную</span>@endif
                                </td>
                                <td class="px-3 py-1.5 text-fg-2 text-[12px]">
                                    @forelse($sup as $sp)
                                        <div>{{ \Illuminate\Support\Str::limit($sp->supplier_name_1c, 60) }} <span class="text-fg-4">· {{ \App\Models\SupplyGroup::label($sp->region) ?? 'без региона' }}</span></div>
                                    @empty
                                        <span class="text-fg-4">нет закупок в 1С</span>
                                    @endforelse
                                </td>
                                <td class="px-3 py-1.5 text-right"><button type="button" wire:click="editItem('{{ $it->sku }}')" class="text-fg-4 hover:text-fg-2 text-[11px]">изменить</button></td>
                            </tr>
                            @if($isOpen && ($cat = $openCards->get($it->id)))
                                <tr wire:key="gi-card-{{ $it->id }}" class="border-b border-border-subtle bg-surface-2">
                                    <td colspan="6" class="px-4 py-2.5">
                                        @include('livewire.catalog._catalog-item-detail', ['cat' => $cat, 'pc' => $openPcs->get($it->id), 'iqp' => null, 'canIqot' => false, 'allowIqotAnalyze' => false, 'supplyHistoryOpen' => true])
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-fg-3">Нет позиций.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-3 py-2">{{ $groupItems->links() }}</div>
        </div>
    @endif

    <div class="ds-card">
        <div class="ds-card-header flex flex-wrap items-center gap-2">
            <h3>Поставщики 1С</h3>
            <div class="flex flex-wrap gap-1 ml-2">
                <button type="button" wire:click="$set('region', '')" class="btn btn-sm {{ $region === '' ? 'btn-primary' : '' }}">Все {{ array_sum($regionCounts) }}</button>
                @foreach($groups as $g)
                    <button type="button" wire:click="$set('region', '{{ $g->code }}')" class="btn btn-sm {{ $region === $g->code ? 'btn-primary' : '' }}">{{ $g->name }} {{ $regionCounts[$g->code] ?? 0 }}</button>
                @endforeach
                <button type="button" wire:click="$set('region', 'none')" class="btn btn-sm {{ $region === 'none' ? 'btn-primary' : '' }}">Без региона {{ $regionCounts['none'] ?? 0 }}</button>
            </div>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Поиск поставщика"
                   class="ml-auto h-[30px] w-64 px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                    <tr><th class="text-left px-3 py-2">Поставщик (как в 1С)</th><th class="text-right px-3 py-2">Позиций</th><th class="text-left px-3 py-2">Валюты</th><th class="text-left px-3 py-2" style="width:200px">Регион</th></tr>
                </thead>
                <tbody>
                    @forelse($suppliers as $s)
                        <tr wire:key="sr-{{ $s->id }}" class="border-b border-border-subtle">
                            <td class="px-3 py-1.5 text-fg-1">
                                @if($cardId = $cards[$s->supplier_name_1c] ?? null)
                                    @php $card = $cardNames->get($cardId); @endphp
                                    <a href="{{ route('suppliers.registry-edit', $cardId) }}" class="hover:text-sky-700 hover:underline" title="Открыть карточку поставщика">{{ $s->supplier_name_1c }}</a>
                                    <span class="text-fg-4 text-[11px]">→ {{ $card?->organization?->name ?: $card?->name ?: $card?->email }}</span>
                                    @if($linkingId !== $s->id)<button type="button" wire:click="startLink({{ $s->id }})" class="text-fg-4 hover:text-fg-2 text-[11px] ml-1">сменить</button>@endif
                                @else
                                    {{ $s->supplier_name_1c }}
                                    @if($linkingId !== $s->id)<button type="button" wire:click="startLink({{ $s->id }})" class="btn btn-xs ml-1">Привязать к карточке</button>@endif
                                @endif
                                @if($linkingId === $s->id)
                                    <div class="mt-1.5 rounded-md border border-border bg-surface-2 p-2 space-y-1">
                                        <div class="flex items-center gap-2">
                                            <input type="search" wire:model.live.debounce.300ms="linkSearch" placeholder="Название, адрес, домен или организация" class="h-[28px] w-80 px-2 border border-border rounded-md bg-surface text-[12.5px]" autofocus>
                                            <button type="button" wire:click="cancelLink" class="btn btn-xs btn-ghost">Отмена</button>
                                        </div>
                                        @forelse($candidates as $c)
                                            <button type="button" wire:key="lc-{{ $c->id }}" wire:click="linkTo({{ $c->id }})" class="block w-full text-left rounded px-2 py-1 hover:bg-hover text-[12.5px]">
                                                <span class="text-fg-1">{{ $c->name ?: '—' }}</span> <span class="mono text-fg-3">{{ $c->email }}</span>@if($c->organization) <span class="text-fg-3">· {{ $c->organization->name }}</span>@endif
                                            </button>
                                        @empty
                                            <div class="text-fg-4 text-[12px] px-2">{{ mb_strlen(trim($linkSearch)) < 2 ? 'Введите хотя бы 2 символа.' : 'Ничего не найдено — поставщика можно завести в «Поставщики» → «Реестр».' }}</div>
                                        @endforelse
                                    </div>
                                @endif
                            </td>
                            <td class="px-3 py-1.5 text-right mono">{{ $s->items_count }}</td>
                            <td class="px-3 py-1.5 text-fg-3 mono">{{ collect(json_decode((string) $s->currencies, true) ?: [])->map(fn ($n, $c) => ($c ?: '—').' '.$n)->implode(', ') }}</td>
                            <td class="px-3 py-1.5">
                                <div class="flex items-center gap-2">
                                    <select wire:change="setRegion({{ $s->id }}, $event.target.value)" class="h-[28px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px]">
                                        <option value="" @selected($s->region === null)>—</option>
                                        @foreach($groups as $g)<option value="{{ $g->code }}" @selected($s->region === $g->code)>{{ $g->name }}</option>@endforeach
                                    </select>
                                    @if($s->source === 'manual')<span class="chip chip-neutral text-[10px]" title="Задано руками — пересчёт не меняет">вручную</span>@endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-center text-fg-3">Нет поставщиков. Загрузите «Закупки 1С» и нажмите «Пересчитать».</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2">{{ $suppliers->links() }}</div>
    </div>

    <div class="ds-card">
        <div class="ds-card-header" id="item-groups"><h3>Группы позиции вручную</h3></div>
        <div class="ds-card-body text-[13px] space-y-2">
            <form wire:submit="findItem" class="flex flex-wrap items-center gap-2">
                <input type="text" wire:model="sku" placeholder="M-артикул" class="h-[30px] w-40 px-2.5 border border-border rounded-md bg-surface text-[13px] mono">
                <button type="submit" class="btn btn-sm">Найти</button>
                @error('sku')<span class="text-red-700 text-[12px]">{{ $message }}</span>@enderror
            </form>
            @if($item)
                <div class="flex flex-wrap items-center gap-3">
                    <span class="mono">{{ $item->sku }}</span> <span class="text-fg-2">{{ $item->name }}</span>
                    @foreach($groups as $g)
                        <label class="inline-flex items-center gap-1"><input type="checkbox" wire:model="itemGroups" value="{{ $g->code }}"> {{ $g->name }}</label>
                    @endforeach
                    <button type="button" wire:click="saveItemGroups" class="btn btn-sm btn-primary">Сохранить</button>
                    <span class="text-fg-4 text-[12px]">{{ $itemSource === 'manual' ? 'задано вручную' : 'автоматически' }} · снимите все галочки — группы снова посчитаются автоматически</span>
                </div>
            @endif
        </div>
    </div>
</div>
