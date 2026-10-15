<div class="space-y-3">
    @php $groups = \App\Enums\SupplyGroup::cases(); @endphp
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Группы товаров</h3>
            <span class="text-[12px] text-fg-3 ml-2">по поставщикам из «Закупок 1С»: китайским поставщикам — то, что везём из Китая, российским — что берём в России, европейским — что импортируем только из Европы</span>
        </div>
        <div class="ds-card-body text-[13px] space-y-3">
            <div class="flex flex-wrap items-center gap-6">
                @foreach($groups as $g)
                    <div><span class="text-[20px] font-semibold mono">{{ number_format($groupCounts[$g->value] ?? 0, 0, ',', ' ') }}</span> <span class="text-fg-3">позиций · {{ $g->label() }}</span></div>
                @endforeach
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

    <div class="ds-card">
        <div class="ds-card-header flex flex-wrap items-center gap-2">
            <h3>Поставщики 1С</h3>
            <div class="flex flex-wrap gap-1 ml-2">
                <button type="button" wire:click="$set('region', '')" class="btn btn-sm {{ $region === '' ? 'btn-primary' : '' }}">Все {{ array_sum($regionCounts) }}</button>
                @foreach($groups as $g)
                    <button type="button" wire:click="$set('region', '{{ $g->value }}')" class="btn btn-sm {{ $region === $g->value ? 'btn-primary' : '' }}">{{ $g->label() }} {{ $regionCounts[$g->value] ?? 0 }}</button>
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
                            <td class="px-3 py-1.5 text-fg-1">{{ $s->supplier_name_1c }}</td>
                            <td class="px-3 py-1.5 text-right mono">{{ $s->items_count }}</td>
                            <td class="px-3 py-1.5 text-fg-3 mono">{{ collect(json_decode((string) $s->currencies, true) ?: [])->map(fn ($n, $c) => ($c ?: '—').' '.$n)->implode(', ') }}</td>
                            <td class="px-3 py-1.5">
                                <div class="flex items-center gap-2">
                                    <select wire:change="setRegion({{ $s->id }}, $event.target.value)" class="h-[28px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px]">
                                        <option value="" @selected($s->region === null)>—</option>
                                        @foreach($groups as $g)<option value="{{ $g->value }}" @selected($s->region === $g->value)>{{ $g->label() }}</option>@endforeach
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
        <div class="ds-card-header"><h3>Группы позиции вручную</h3></div>
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
                        <label class="inline-flex items-center gap-1"><input type="checkbox" wire:model="itemGroups" value="{{ $g->value }}"> {{ $g->label() }}</label>
                    @endforeach
                    <button type="button" wire:click="saveItemGroups" class="btn btn-sm btn-primary">Сохранить</button>
                    <span class="text-fg-4 text-[12px]">{{ $itemSource === 'manual' ? 'задано вручную' : 'автоматически' }} · снимите все галочки — группы снова посчитаются автоматически</span>
                </div>
            @endif
        </div>
    </div>
</div>
