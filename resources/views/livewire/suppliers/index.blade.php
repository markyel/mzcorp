<div class="space-y-4">
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Поставщики</h3>
            {{-- Вкладки --}}
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden ml-3 text-[12px]">
                @foreach(['inquiries' => 'Запросы', 'nomenclature' => 'Номенклатура', 'registry' => 'Реестр'] as $k => $label)
                    @php $on = $tab === $k; @endphp
                    <button type="button" wire:click="setTab('{{ $k }}')"
                            class="h-[26px] px-3 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <span class="flex-1"></span>
            <span class="text-[11.5px] text-fg-3 mono">{{ $tab === 'registry' ? $this->suppliers->total() : ($tab === 'nomenclature' ? $this->positions->total() : $this->inquiries->total()) }}</span>
        </div>

        <div class="px-4 pb-3">
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="{{ $tab === 'registry' ? 'Поиск: email / домен / название' : ($tab === 'nomenclature' ? 'Поиск: название / артикул / SKU' : 'Поиск: e-mail / название / тема') }}"
                   class="h-[30px] w-full max-w-[440px] px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
        </div>

        @if($tab === 'nomenclature')
            {{-- Номенклатура: позиции, по которым запрашивали цену + предложения --}}
            <div class="px-4 pb-2 text-[12px] text-fg-3">
                Позиции, по которым отправлены запросы поставщикам, и предложенные цены. После внесения цены в корп. базу (1С) и импорта каталога статус цены позиции станет «актуальная».
            </div>
            <div class="divide-y divide-border-subtle border-t border-border">
                @forelse($this->positions as $pos)
                    @php
                        $name = $pos->catalog_item_id ? ($pos->catalogItem?->name ?: $pos->parsed_name) : $pos->parsed_name;
                        $offers = $pos->supplierInquiryItems->flatMap->offers;
                        $quoted = $offers->where('outcome', 'quoted')->filter(fn ($o) => $o->price !== null);
                        $minP = $quoted->min('price');
                        $maxP = $quoted->max('price');
                        $stale = $pos->catalog_item_id && $pos->catalogItem && ! $pos->catalogItem->is_price_actual;
                    @endphp
                    <div wire:key="pos-{{ $pos->id }}" class="px-4 py-3">
                        <div class="flex items-start gap-2 flex-wrap">
                            <div class="flex-1 min-w-[260px]">
                                <span class="text-[13px] text-fg-1 font-medium">{{ \Illuminate\Support\Str::limit($name, 70) }}</span>
                                <div class="text-[11px] text-fg-4 mt-0.5">
                                    @if($pos->parsed_article)<span class="mono">арт. {{ $pos->parsed_article }}</span> · @endif
                                    @if($pos->catalogItem?->sku)<span class="mono">{{ $pos->catalogItem->sku }}</span> · @endif
                                    заявка <a href="{{ route('requests.show', $pos->request_id) }}" wire:navigate class="text-sky-700 hover:underline mono">{{ $pos->request?->internal_code }}</a>
                                    · цена @if($pos->catalog_item_id)<span class="{{ $stale ? 'text-amber-700' : 'text-emerald-700' }}">{{ $stale ? 'неактуальна' : 'актуальна' }}</span>@else<span class="text-fg-4">не в каталоге</span>@endif
                                </div>
                            </div>
                            @if($quoted->isNotEmpty())
                                <span class="chip chip-ok text-[10.5px]">{{ $minP == $maxP ? number_format((float)$minP,2,'.',' ') : number_format((float)$minP,2,'.',' ').'–'.number_format((float)$maxP,2,'.',' ') }} ₽ · предложений: {{ $quoted->count() }}</span>
                            @else
                                <span class="chip chip-sky text-[10.5px]">ждём предложений</span>
                            @endif
                        </div>

                        {{-- Предложения от разных поставщиков --}}
                        <div class="mt-2 ml-0 flex flex-wrap gap-1.5">
                            @foreach($pos->supplierInquiryItems as $sii)
                                @php $o = $sii->offers->first(); @endphp
                                <div class="inline-flex items-center gap-1.5 border border-border-subtle rounded-md px-2 py-1 bg-surface text-[11.5px]">
                                    <a href="{{ route('suppliers.show', $sii->supplier_inquiry_id) }}" wire:navigate class="text-sky-700 hover:underline">{{ $sii->inquiry?->supplier_name ?: $sii->inquiry?->supplier_email }}</a>
                                    @if($o && $o->outcome === 'quoted')
                                        <span class="text-emerald-700 font-medium">{{ number_format((float)$o->price,2,'.',' ') }} {{ $o->currency ?: '₽' }}</span>
                                        @if($o->valid_until_text)<span class="text-fg-4">· {{ \Illuminate\Support\Str::limit($o->valid_until_text, 24) }}</span>@endif
                                    @elseif($o && $o->outcome === 'refused')
                                        <span class="text-red-700">отказ{{ $o->refusal_reason ? ': '.\Illuminate\Support\Str::limit($o->refusal_reason, 28) : '' }}</span>
                                    @else
                                        <span class="text-fg-4">ждём</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="px-4 py-10 text-center text-fg-3 text-[13px]">{{ trim($search) !== '' ? 'Ничего не найдено.' : 'Пока нет запросов цен. Отправьте запрос из карточки заявки → таб «Поставщики».' }}</div>
                @endforelse
            </div>
            <div class="px-4 py-3">{{ $this->positions->links() }}</div>
        @elseif($tab === 'inquiries')
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="text-left px-3 py-2">Поставщик</th>
                            <th class="text-left px-3 py-2">Тема запроса</th>
                            <th class="text-right px-3 py-2">Писем</th>
                            <th class="text-left px-3 py-2">Ответ</th>
                            <th class="text-left px-3 py-2">Статус</th>
                            <th class="text-left px-3 py-2">Кто пометил</th>
                            <th class="text-left px-3 py-2">Дата</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->inquiries as $i)
                            <tr wire:key="inq-{{ $i->id }}" class="border-b border-border-subtle hover:bg-hover">
                                <td class="px-3 py-2">
                                    <a href="{{ route('suppliers.show', $i->id) }}" wire:navigate class="text-sky-700 hover:underline font-medium">{{ $i->supplier_name ?: $i->supplier_email }}</a>
                                    @if($i->supplier_name)<div class="text-[11px] text-fg-4 mono">{{ $i->supplier_email }}</div>@endif
                                </td>
                                <td class="px-3 py-2 text-fg-2"><span class="truncate inline-block max-w-[300px] align-bottom">{{ $i->subject ?: '—' }}</span></td>
                                <td class="px-3 py-2 text-right mono text-fg-2">{{ $i->messages_count }}</td>
                                <td class="px-3 py-2">
                                    @php $rs = $i->responseState(); @endphp
                                    @if($rs === 'answered')
                                        <span class="chip chip-ok text-[10.5px]">ответил</span>
                                    @elseif($rs === 'awaiting')
                                        <span class="chip chip-warn text-[10.5px]">ждём{{ $i->reminders_sent > 0 ? ' · нап. '.$i->reminders_sent : '' }}</span>
                                    @else
                                        <span class="text-fg-4 text-[11px]">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2"><span class="chip {{ $i->status === 'closed' ? 'chip-neutral' : 'chip-sky' }} text-[10.5px]">{{ $i->status === 'closed' ? 'закрыт' : 'открыт' }}</span></td>
                                <td class="px-3 py-2 text-fg-3 whitespace-nowrap">{{ $i->createdBy?->name ?? 'авто' }}</td>
                                <td class="px-3 py-2 text-fg-3 mono whitespace-nowrap">{{ $i->created_at?->format('d.m.Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-10 text-center text-fg-3 text-[13px]">{{ trim($search) !== '' ? 'Ничего не найдено.' : 'Пока нет запросов поставщикам. Появляются автоматически при отправке RFQ поставщику из реестра.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $this->inquiries->links() }}</div>
            </div>
        @else
            {{-- Реестр поставщиков --}}
            <div class="px-4 pb-3">
                <div class="flex flex-wrap items-start gap-2 border border-border rounded-md p-3 bg-surface-2 max-w-[760px]">
                    <input type="email" wire:model="newEmail" placeholder="email@supplier.ru" class="h-[30px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500 flex-1 min-w-[180px]">
                    <input type="text" wire:model="newDomain" placeholder="или домен: supplier.ru" class="h-[30px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500 flex-1 min-w-[160px]">
                    <input type="text" wire:model="newName" placeholder="Название (необязательно)" class="h-[30px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500 flex-1 min-w-[160px]">
                    <button type="button" wire:click="addSupplier" class="btn btn-sm btn-primary shrink-0">Добавить</button>
                    @error('newEmail') <div class="w-full text-[11px] text-red-600">{{ $message }}</div> @enderror
                </div>
                <div class="text-[11.5px] text-fg-3 mt-1.5">Наше исходящее письмо получателю из реестра + подтверждение LLM «это запрос расценки» → тред регистрируется автоматически, ответы поставщика не создают заявок.</div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="text-left px-3 py-2">E-mail</th>
                            <th class="text-left px-3 py-2">Домен</th>
                            <th class="text-left px-3 py-2">Название</th>
                            <th class="text-left px-3 py-2">Добавил</th>
                            <th class="text-right px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->suppliers as $s)
                            <tr wire:key="sup-{{ $s->id }}" class="border-b border-border-subtle hover:bg-hover">
                                <td class="px-3 py-2 mono"><a href="{{ route('suppliers.registry-edit', $s->id) }}" wire:navigate class="text-sky-700 hover:underline">{{ $s->email ?: '—' }}</a></td>
                                <td class="px-3 py-2 mono text-fg-2">{{ $s->domain ?: '—' }}</td>
                                <td class="px-3 py-2 text-fg-2"><a href="{{ route('suppliers.registry-edit', $s->id) }}" wire:navigate class="hover:underline">{{ $s->name ?: '—' }}</a></td>
                                <td class="px-3 py-2 text-fg-3 whitespace-nowrap">{{ $s->createdBy?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-right">
                                    <a href="{{ route('suppliers.registry-edit', $s->id) }}" wire:navigate class="text-[11.5px] text-sky-700 hover:underline mr-2">профиль</a>
                                    <button type="button" wire:click="removeSupplier({{ $s->id }})" wire:confirm="Удалить из реестра поставщиков?" class="text-[11.5px] text-red-600 hover:underline">удалить</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-10 text-center text-fg-3 text-[13px]">{{ trim($search) !== '' ? 'Ничего не найдено.' : 'Реестр пуст. Добавьте email/домен поставщика выше — или пометьте тред кнопкой на заявке (поставщик добавится автоматически).' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="px-4 py-3">{{ $this->suppliers->links() }}</div>
            </div>
        @endif
    </div>
</div>
