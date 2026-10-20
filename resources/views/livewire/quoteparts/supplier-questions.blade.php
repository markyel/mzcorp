{{-- Вкладка «Вопросы поставщиков»: позиции, по которым поставщики спросили; переписка и ответ — под вопросом. --}}
@php
    $items = $this->items;
    $tz = config('app.timezone');
@endphp
<div class="space-y-3">
    <div class="flex flex-wrap items-center gap-3 text-[12.5px]">
        <label class="inline-flex items-center gap-1.5 cursor-pointer text-fg-2">
            <input type="checkbox" wire:model.live="withAnswered" class="w-4 h-4 accent-sky-600"> и отвеченные за {{ \App\Livewire\QuoteParts\SupplierQuestions::ANSWERED_DAYS }} дн.
        </label>
        <span class="text-fg-4">Вопросы поставщиков по запросам QuoteParts и по позициям из их номенклатуры. Сверху — где вопрос ждёт ответа дольше всего.</span>
    </div>

    @forelse($items as $it)
        @php $card = $it['cid'] ? ($this->openCards[$it['cid']] ?? null) : null; @endphp
        <div class="ds-card" wire:key="sq-{{ $it['key'] }}">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 px-4 py-2 text-[12.5px] {{ $it['open'] > 0 ? 'bg-amber-50' : 'bg-surface-2' }}">
                @if($it['cid'])
                    <button type="button" wire:click="toggleItem({{ $it['cid'] }})" class="mono font-semibold text-sky-700 hover:underline" title="Карточка позиции">{{ $it['code'] }} <span class="text-fg-4 text-[10.5px] font-normal">{{ $card ? '▾' : '▸' }}</span></button>
                @elseif($it['code'])
                    <span class="mono font-semibold">{{ $it['code'] }}</span>
                @else
                    <span class="text-fg-3">вопрос по запросу в целом</span>
                @endif
                @if($it['name'])<span class="text-fg-1">{{ $it['name'] }}</span>@endif
                <span class="text-fg-4">·</span>
                @if($it['open'] > 0)
                    <span class="chip chip-warn text-[11px]">ждут ответа: {{ $it['open'] }}</span>
                    @if($it['oldest'])<span class="text-fg-3">спросили {{ $it['oldest']->timezone($tz)->format('d.m H:i') }}</span>@endif
                @else
                    <span class="chip chip-ok text-[11px]">отвечено</span>
                @endif
            </div>
            @if($card)
                <div class="px-4 py-2.5 bg-surface-2 border-t border-border-subtle">
                    @include('livewire.catalog._catalog-item-detail', ['cat' => $card['cat'], 'pc' => $card['pc'], 'iqp' => null, 'canIqot' => false, 'allowIqotAnalyze' => false, 'supplyHistoryOpen' => true])
                </div>
            @endif
            @foreach($it['questions'] as $qi => $q)
                @php $dkey = $q['rfq']->id.'|'.$q['supplier_key']; @endphp
                <div wire:key="sq-{{ $it['key'] }}-{{ $qi }}" class="border-t border-border-subtle px-4 py-2 text-[12.5px] {{ $q['open'] ? '' : 'opacity-60' }}">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-medium text-fg-1">{{ $q['supplier'] }}</span>
                        <span class="text-fg-3">{{ $q['at']?->timezone($tz)->format('d.m H:i') }}</span>
                        <span class="mono text-[11px] text-fg-4">{{ $q['rfq']->tag }}</span>
                        @if($q['rfq']->request)
                            <a href="{{ route('requests.show', $q['rfq']->request_id) }}" class="mono text-[11.5px] text-sky-700 hover:underline">{{ $q['rfq']->request->internal_code }}</a>
                        @elseif($q['rfq']->isSupplierQuestion())
                            <span class="chip chip-warn text-[10px]">из номенклатуры поставщика</span>
                        @else
                            <span class="text-[11px] text-fg-4">снабжение</span>
                        @endif
                        @if(! $q['open'])<span class="text-[11px] text-emerald-700">отвечено</span>@endif
                        <button type="button" wire:click="openDialog({{ $q['rfq']->id }}, '{{ $q['supplier_key'] }}')" class="ml-auto btn btn-sm {{ $dialog === $dkey ? 'btn-primary' : '' }}">💬 {{ $q['open'] ? 'Переписка и ответ' : 'Переписка' }}</button>
                    </div>
                    <div class="mt-0.5 text-fg-1 whitespace-pre-line">{{ $q['text'] }}</div>
                    @if($q['original'])<div class="text-[11.5px] text-fg-4">«{{ $q['original'] }}»</div>@endif
                    @if($dialog === $dkey)
                        <div class="mt-2">
                            @include('livewire.quote-parts._rfq-card', ['rfq' => $q['rfq'], 'supplierNames' => app(\App\Services\QuoteParts\QuotePartsRfqService::class)->supplierNames([$q['rfq']]), 'refreshAction' => 'refreshQuotePartsRfq', 'onlySupplier' => $q['supplier_key']])
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @empty
        <div class="ds-card"><div class="ds-card-body text-center text-fg-3 text-[13px] py-8">
            {{ $withAnswered ? 'Вопросов поставщиков за '.\App\Livewire\QuoteParts\SupplierQuestions::ANSWERED_DAYS.' дн. нет.' : 'Открытых вопросов поставщиков нет.' }}
        </div></div>
    @endforelse
</div>
