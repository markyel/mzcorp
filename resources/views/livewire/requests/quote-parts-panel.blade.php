<div>
@if($this->enabled || $this->rfqs->isNotEmpty())
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>QuoteParts</h3>
            <span class="text-[12px] text-fg-3 ml-2">запрос поставщикам через платформу · пилот</span>
            @if($this->enabled && ! $open)
                <button type="button" wire:click="$set('open', true)" class="btn btn-sm btn-primary ml-auto">Запрос через QuoteParts</button>
            @endif
        </div>
        <div class="ds-card-body space-y-3 text-[13px]">
            @foreach($this->rfqs as $rfq)
                @include('livewire.quote-parts._rfq-card', ['rfq' => $rfq, 'supplierNames' => $supplierNames])
            @endforeach

            @if($this->enabled && $open)
                @php $inputCls = 'h-[28px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500'; @endphp
                <div class="border border-sky-200 bg-sky-50/40 rounded-md p-3 space-y-3">
                    <div class="text-[12px] text-fg-3">Письмо поставщику QuoteParts отправит сам — от MyZip, на английском и китайском, с формой ответа. Ответы придут сюда.</div>

                    <div>
                        <div class="text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Позиции</div>
                        @foreach($this->catalogItems as $it)
                            <label wire:key="qpi-{{ $it->id }}" class="flex items-center gap-2 py-0.5">
                                <input type="checkbox" wire:model="items.{{ $it->id }}" class="w-4 h-4 accent-sky-600">
                                <span class="mono w-[70px]">{{ $it->catalogItem?->sku ?? ($it->quoteparts_code ?: '—') }}</span>
                                <span class="flex-1 truncate">{{ $it->catalogItem?->name ?? $it->parsed_name }}</span>
                                @unless($it->catalog_item_id)
                                    <span class="chip chip-sky text-[10px]" title="Товара нет в каталоге: уйдёт предварительной позицией с временным кодом — название (по-английски), бренд, артикул, характеристики">новый товар</span>
                                @endunless
                                <input type="text" wire:model="qty.{{ $it->id }}" class="{{ $inputCls }} w-[70px] text-right">
                            </label>
                        @endforeach
                    </div>

                    <div>
                        <div class="text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Поставщики</div>
                        @forelse($this->activeSuppliers as $s)
                            <label wire:key="qps-{{ $s->id }}" class="inline-flex items-center gap-1.5 mr-4 py-0.5">
                                <input type="checkbox" wire:model="suppliers.{{ $s->id }}" class="w-4 h-4 accent-sky-600"> {{ $s->organization?->name ?: ($s->name ?: $s->email) }}
                                @if($s->quoteparts_status !== 'active')
                                    <span class="text-[11px] text-fg-4">· {{ \App\Services\QuoteParts\QuotePartsSupplierService::STATUS_LABELS[$s->quoteparts_status] ?? 'Переписка' }}</span>
                                @endif
                            </label>
                        @empty
                            <div class="text-fg-4 text-[12px]">В QuoteParts пока нет поставщиков — найдите нужного в справочнике ниже.</div>
                        @endforelse

                        <div class="mt-2 relative max-w-md">
                            <input type="text" wire:model.live.debounce.300ms="supplierSearch" placeholder="Добавить из справочника: название, адрес, домен"
                                   class="w-full border border-border rounded px-2 py-1 text-[13px]">
                            @if($this->foundSuppliers->isNotEmpty())
                                <div class="mt-1 border border-border rounded bg-surface divide-y divide-border-subtle">
                                    @foreach($this->foundSuppliers as $f)
                                        <button type="button" wire:key="qpf-{{ $f->id }}" wire:click="addSupplier({{ $f->id }})"
                                                class="block w-full text-left px-2 py-1 text-[13px] hover:bg-surface-2">
                                            {{ $f->name ?: $f->email }} <span class="text-fg-4">{{ $f->email }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @elseif(mb_strlen(trim($supplierSearch)) >= 2)
                                <div class="mt-1 text-[12px] text-fg-4">Не нашлось поставщиков с адресом.</div>
                            @endif
                        </div>
                        <div class="mt-1 text-[11px] text-fg-4">Кто не принял приглашение, получит обычное письмо с ящика MyZip и ответит письмом или по ссылке — без регистрации. Ответ разберётся в предложения так же.</div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <label class="flex items-center gap-2">Ответить до <input type="date" wire:model="replyBy" class="{{ $inputCls }}"></label>
                        <input type="text" wire:model="message" placeholder="Сообщение поставщикам (по-английски, необязательно)" class="{{ $inputCls }} flex-1 min-w-[240px]">
                    </div>

                    @include('livewire.quote-parts._mirror-note')

                    @if($error)<div class="text-red-700 text-[12.5px]">{{ $error }}</div>@endif

                    <div class="flex gap-2">
                        <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send" class="btn btn-sm btn-primary">Отправить через QuoteParts</button>
                        <button type="button" wire:click="$set('open', false)" class="btn btn-sm">Отмена</button>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endif
</div>
