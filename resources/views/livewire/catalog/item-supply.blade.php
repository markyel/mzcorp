<div class="bg-surface border border-border rounded-md overflow-hidden mt-1">
    @php $money = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ','); @endphp
    <div class="px-3.5 py-2 bg-surface-2 border-b border-border-subtle text-[10px] uppercase tracking-wider text-fg-3 font-semibold">Снабжение</div>

    {{-- Группы товаров: позиция может быть в нескольких. --}}
    <div class="px-3.5 py-2 border-b border-border-subtle text-[12.5px]">
        <div class="text-fg-3 text-[12px] font-medium mb-1">Группы товаров</div>
        <div class="flex flex-wrap gap-x-3 gap-y-1">
            @foreach($allGroups as $g)
                <label class="inline-flex items-center gap-1 {{ $canEdit ? 'cursor-pointer' : '' }}">
                    <input type="checkbox" @checked(in_array($g->code, $codes, true)) @disabled(! $canEdit)
                           wire:click="toggleGroup('{{ $g->code }}')" class="w-4 h-4 accent-sky-600"> {{ $g->name }}
                </label>
            @endforeach
        </div>
        <div class="text-fg-4 text-[11px] mt-1">
            @if($manual)
                заданы вручную @if($canEdit)· <button type="button" wire:click="resetGroups" class="text-sky-700 hover:underline">по закупкам 1С</button>@endif
            @else
                по закупкам 1С
            @endif
        </div>
    </div>

    {{-- У кого закупаем. --}}
    <div class="px-3.5 py-2 border-b border-border-subtle text-[12.5px]">
        <div class="text-fg-3 text-[12px] font-medium mb-1">Закупаем у</div>
        @if($choice)
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span class="chip chip-ok text-[11px]">✓ {{ $choice->supplier_name }}</span>
                @if($chosenOffer && $chosenOffer['price'] !== null)
                    <span class="mono text-fg-1">{{ $money($chosenOffer['price']) }} {{ $chosenOffer['currency'] }}</span>
                    @if($chosenOffer['variant'])<span class="text-fg-3 text-[11px]">{{ $chosenOffer['variant'] }}</span>@endif
                @else
                    <span class="text-fg-4 text-[11px]">нет текущей цены</span>
                @endif
            </div>
            <div class="text-fg-4 text-[11px] mt-0.5">выбрано {{ $choice->chosen_at?->format('d.m.Y') }}{{ $choice->chosenBy ? ' · '.$choice->chosenBy->name : '' }}
                @if($canEdit)· <button type="button" wire:click="$toggle('choosing')" class="text-sky-700 hover:underline">сменить</button> · <button type="button" wire:click="clearChoice" class="text-fg-3 hover:underline">снять</button>@endif
            </div>
        @else
            <div class="text-fg-4 text-[12px]">не выбран
                @if($canEdit && $offers->isNotEmpty())· <button type="button" wire:click="$toggle('choosing')" class="text-sky-700 hover:underline">выбрать</button>@endif
                @if($offers->isEmpty())— нет текущих цен поставщиков@endif
            </div>
        @endif
        @if($choosing && $canEdit)
            <div class="mt-1.5 space-y-0.5">
                @forelse($offers->sortBy('price') as $o)
                    <button type="button" wire:key="ch-{{ $o['key'] }}" wire:click="choose('{{ $o['key'] }}')"
                            @class(['flex w-full items-baseline gap-2 rounded px-1.5 py-1 text-left hover:bg-hover', 'bg-[var(--bg-selected)]' => $choice?->supplier_key === $o['key']])>
                        <span class="text-fg-1 truncate">{{ $o['supplier'] }}</span>
                        <span class="mono text-fg-2 ml-auto whitespace-nowrap">{{ $o['price'] !== null ? $money($o['price']).' '.$o['currency'] : '—' }}</span>
                        @if($o['variant'])<span class="text-fg-3 text-[10.5px]">{{ $o['variant'] }}</span>@endif
                        <span class="text-fg-4 text-[10.5px] whitespace-nowrap">{{ $o['at']?->format('d.m.Y') }}</span>
                    </button>
                @empty
                    <div class="text-fg-4 text-[12px]">Нет текущих цен поставщиков.</div>
                @endforelse
            </div>
        @endif
    </div>

    {{-- История ценовых предложений. --}}
    @if($withHistory)
        <div class="px-3.5 py-2 text-[12.5px]">
            <button type="button" wire:click="$toggle('historyOpen')" class="text-sky-700 hover:underline text-[12px]">
                {{ $historyOpen ? '▾' : '▸' }} История предложений поставщиков
            </button>
            @if($historyOpen)
                @include('livewire.suppliers._catalog-supplier-history', ['history' => $history])
            @endif
        </div>
    @endif
</div>
