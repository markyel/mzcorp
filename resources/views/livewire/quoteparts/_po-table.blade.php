{{-- Таблица предложений вкладки «Актуализация цен». Ожидает $rows, $showRequest (колонка «Заявка»), $showItem (колонка «Позиция»). --}}
@php
    $sources = ['form' => 'форма запроса', 'email' => 'письмо', 'cabinet' => 'кабинет', 'price_list' => 'прайс', 'api' => 'API'];
    $money = fn ($v, $d = 2) => $v === null ? '—' : number_format((float) $v, $d, ',', ' ');
    $showItem = $showItem ?? true;
    $cols = 5 + ($showItem ? 3 : 0) + ($showRequest ? 1 : 0);
@endphp
{{-- Ширины колонок фиксированы и одинаковы во всех блоках: таблицы стоят друг под другом и не «пляшут». --}}
<div class="overflow-x-auto">
    <table class="w-full table-fixed text-[12.5px] min-w-[980px]">
        <colgroup>
            <col style="width:110px">
            @if($showItem)<col>@endif
            <col @if($showItem) style="width:230px" @endif>
            <col style="width:130px">
            <col style="width:100px">
            @if($showItem)<col style="width:100px"><col style="width:120px">@endif
            <col style="width:120px">
            @if($showRequest)<col style="width:140px">@endif
        </colgroup>
        <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
            <tr>
                <th class="text-left px-3 py-2">Когда</th>
                @if($showItem)<th class="text-left px-3 py-2">Позиция</th>@endif
                <th class="text-left px-3 py-2">Поставщик</th>
                <th class="text-right px-3 py-2">Цена</th>
                <th class="text-right px-3 py-2" title="Цена поставщика в рублях по курсу ЦБ">≈ ₽</th>

                @if($showItem)
                    <th class="text-right px-3 py-2" title="Закупочная цена из каталога (1С) — с логистикой и таможней, с ценой поставщика напрямую не сравнивается">Закупка</th>
                    <th class="text-right px-3 py-2" title="Цена продажи в каталоге">Продажа</th>
                @endif
                <th class="text-left px-3 py-2">Срок · действует</th>
                @if($showRequest)<th class="text-left px-3 py-2">Заявка</th>@endif
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $r)
                <tr wire:key="po-{{ $r['id'] }}" class="border-b border-border-subtle align-top {{ $r['stale'] ? 'opacity-60' : '' }}">
                    <td class="px-3 py-1.5 whitespace-nowrap text-fg-3">
                        {{ $r['at']?->timezone(config('app.timezone'))->format('d.m H:i') }}
                        <div class="text-[11px] text-fg-4">{{ $sources[$r['source']] ?? $r['source'] }}</div>
                    </td>
                    @if($showItem)
                        <td class="px-3 py-1.5">
                            @if($r['cid'])
                                <button type="button" wire:click="toggleItem({{ $r['cid'] }})" class="text-left group" title="Карточка позиции">
                                    <span class="mono text-sky-700 group-hover:underline">{{ $r['sku'] }}</span> <span class="text-fg-4 text-[10px]">{{ in_array($r['cid'], $openItems, true) ? '▾' : '▸' }}</span>
                                    @if($r['name'])<div class="text-[11.5px] text-fg-3 truncate" title="{{ $r['name'] }}">{{ $r['name'] }}</div>@endif
                                </button>
                            @else
                                <span class="mono">{{ $r['sku'] ?: '—' }}</span>
                            @endif
                        </td>
                    @endif
                    <td class="px-3 py-1.5 break-words">
                        {{ $r['supplier'] }}
                        @if($r['kind'] !== 'original')
                            <div class="mt-0.5">
                                <span class="chip chip-sky text-[10.5px]">{{ \App\Models\QuotePartsOffer::kindLabel($r['kind']) }}</span>
                                <span class="chip {{ $r['review'] === 'accepted' ? 'chip-ok' : ($r['review'] === 'rejected' ? 'chip-neutral' : 'chip-warn') }} text-[10px]" title="Отклонения разбирает снабжение: «Снабжение» → «Предложения поставщиков»">{{ ['accepted' => 'принято к закупке', 'rejected' => 'отклонено'][$r['review']] ?? 'на разборе' }}</span>
                                @if($r['product'])<span class="mono text-[11px] text-fg-3">{{ $r['product'] }}</span>@endif
                            </div>
                        @endif
                        @if($r['note'])<div class="text-[11px] text-fg-3">«{{ \Illuminate\Support\Str::limit($r['note'], 120) }}»</div>@endif
                        @if($r['stale'])<div class="text-[10.5px] text-fg-4">устарело</div>@endif
                    </td>
                    <td class="px-3 py-1.5 text-right mono whitespace-nowrap">
                        @forelse($r['tiers'] as $t)
                            <div><span class="font-semibold">{{ $money($t['price'] ?? null) }} {{ $r['currency'] }}</span>@if(count($r['tiers']) > 1)<span class="text-fg-4 text-[11px]"> от {{ $t['min_qty'] ?? 1 }}</span>@endif</div>
                        @empty
                            <span class="font-semibold">{{ $money($r['price']) }} {{ $r['currency'] }}</span>
                        @endforelse
                    </td>
                    <td class="px-3 py-1.5 text-right mono whitespace-nowrap">{{ $r['rub'] !== null ? $money($r['rub'], 0).' ₽' : '—' }}</td>
                    @if($showItem)
                        <td class="px-3 py-1.5 text-right mono whitespace-nowrap text-fg-2">{{ $r['purchase'] !== null ? $money($r['purchase'], 0).' ₽' : '—' }}</td>
                        <td class="px-3 py-1.5 text-right mono whitespace-nowrap text-fg-2">
                            {{ $r['sale'] !== null ? $money($r['sale'], 0).' ₽' : '—' }}
                            @if($r['sku'])<div class="text-[10.5px] {{ $r['actual'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $r['actual'] ? 'актуальна' : 'к актуализации' }}</div>@endif
                        </td>
                    @endif
                    <td class="px-3 py-1.5 whitespace-nowrap text-fg-3 text-[11.5px]">
                        {{ $r['lead'] ? $r['lead'].' дн.' : '—' }}
                        @if($r['valid'])<div>до {{ $r['valid']->format('d.m.Y') }}</div>@endif
                    </td>
                    @if($showRequest)
                        <td class="px-3 py-1.5 whitespace-nowrap">
                            @if($r['request'])<a href="{{ route('requests.show', $r['request']->id) }}" class="mono text-sky-700 hover:underline">{{ $r['request']->internal_code }}</a>@else<span class="text-fg-4">—</span>@endif
                            @if($r['rfq_tag'])<div class="mono text-[11px] text-fg-4">{{ $r['rfq_tag'] }}</div>@endif
                        </td>
                    @endif
                </tr>
                @if($showItem && $r['cid'] && ($card = $this->openCards[$r['cid']] ?? null))
                    <tr wire:key="po-card-{{ $r['id'] }}" class="border-b border-border-subtle bg-surface-2">
                        <td colspan="{{ $cols }}" class="px-4 py-2.5">
                            @include('livewire.catalog._catalog-item-detail', ['cat' => $card['cat'], 'pc' => $card['pc'], 'iqp' => null, 'canIqot' => false, 'allowIqotAnalyze' => false, 'supplyHistoryOpen' => true])
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
</div>
