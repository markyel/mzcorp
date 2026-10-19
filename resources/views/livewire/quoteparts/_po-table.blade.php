{{-- Таблица предложений вкладки «Актуализация цен». Ожидает $rows, $showRequest (колонка «Заявка»), $showItem (колонки позиции).
     Лучшее предложение позиции выделено; у каждого — «Закупаем здесь», у отклонений — учитывать или нет, «Уточнить» — диалог с поставщиком. --}}
@php
    $sources = ['form' => 'форма запроса', 'email' => 'письмо', 'cabinet' => 'кабинет', 'price_list' => 'прайс', 'api' => 'API'];
    $money = fn ($v, $d = 2) => $v === null ? '—' : number_format((float) $v, $d, ',', ' ');
    $showItem = $showItem ?? true;
    $cols = 6 + ($showItem ? 3 : 0) + ($showRequest ? 1 : 0);
    $bestIds = $this->bestIds;
    $choices = $this->choices;
    $clarifications = $this->clarifications;
    $tzc = config('app.timezone');
@endphp
{{-- Ширины колонок фиксированы и одинаковы во всех блоках: таблицы стоят друг под другом и не «пляшут». --}}
<div class="overflow-x-auto">
    <table class="w-full table-fixed text-[12.5px]" style="min-width:1080px">
        <colgroup>
            <col style="width:100px">
            @if($showItem)<col>@endif
            <col @if($showItem) style="width:220px" @endif>
            <col style="width:120px">
            <col style="width:95px">
            @if($showItem)<col style="width:95px"><col style="width:110px">@endif
            <col style="width:110px">
            @if($showRequest)<col style="width:130px">@endif
            <col style="width:200px">
        </colgroup>
        @if($showItem)
        <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
            <tr>
                <th class="text-left px-3 py-2">Когда</th>
                @if($showItem)<th class="text-left px-3 py-2">Позиция</th>@endif
                <th class="text-left px-3 py-2">Поставщик · что предложил</th>
                <th class="text-right px-3 py-2">Цена</th>
                <th class="text-right px-3 py-2" title="Цена поставщика в рублях по курсу ЦБ">≈ ₽</th>
                @if($showItem)
                    <th class="text-right px-3 py-2" title="Закупочная цена из каталога (1С) — с логистикой и таможней, с ценой поставщика напрямую не сравнивается">Закупка</th>
                    <th class="text-right px-3 py-2" title="Цена продажи в каталоге">Продажа</th>
                @endif
                <th class="text-left px-3 py-2">Срок · действует</th>
                @if($showRequest)<th class="text-left px-3 py-2">Заявка</th>@endif
                <th class="text-left px-3 py-2">Решение</th>
            </tr>
        </thead>
        @endif
        <tbody>
            @foreach($rows as $r)
                @php
                    $isBest = in_array($r['id'], $bestIds, true);
                    $choice = $r['cid'] ? $choices->get($r['cid']) : null;
                    $chosenHere = $choice && $choice->source === 'quoteparts' && (int) $choice->offer_ref === $r['id'];
                    $chosenSupplier = $choice && ! $chosenHere && $choice->supplier_key === $r['key'];
                    $deviation = $r['kind'] !== 'original';
                    $cl = $clarifications[$r['id']] ?? null;
                    $clWaiting = ($cl['state'] ?? null) === \App\Services\QuoteParts\OfferClarifications::WAITING;
                @endphp
                <tr wire:key="po-{{ $r['id'] }}" class="border-b border-border-subtle align-top {{ $r['stale'] ? 'opacity-60' : '' }} {{ $chosenHere ? 'bg-sky-50' : ($isBest ? 'bg-emerald-50' : '') }}">
                    <td class="px-3 py-1.5 whitespace-nowrap text-fg-3">
                        {{ $r['at']?->timezone(config('app.timezone'))->format('d.m H:i') }}
                        <div class="text-[11px] text-fg-4">{{ $sources[$r['source']] ?? $r['source'] }}</div>
                        {{-- Переписка с поставщиком по этому предложению — та же панель, что «Уточнить»: письмо запроса, ответы, уточнения. --}}
                        @if($r['key'])
                            @php $nLetters = $this->threadCounts[$r['id']] ?? null; @endphp
                            <button type="button" wire:click="openDialog({{ $r['id'] }})" class="mt-0.5 text-[11px] {{ $dialog === $r['id'] ? 'text-fg-1 font-medium' : 'text-sky-700' }} hover:underline"
                                    title="{{ $r['rfq_remote_id'] ? 'Переписка с поставщиком по запросу '.$r['rfq_tag'] : 'Обращения к поставщику через QuoteParts' }}">
                                {{ $dialog === $r['id'] ? '▾' : '▸' }} {{ $r['rfq_remote_id'] ? 'переписка'.($nLetters ? ' ('.$nLetters.')' : '') : 'обращения' }}
                            </button>
                        @endif
                    </td>
                    @if($showItem)
                        <td class="px-3 py-1.5">
                            @if($r['cid'])
                                <button type="button" wire:click="toggleItem({{ $r['cid'] }})" class="text-left group w-full" title="Карточка позиции">
                                    <span class="mono text-sky-700 group-hover:underline">{{ $r['sku'] }}</span> <span class="text-fg-4 text-[10px]">{{ in_array($r['cid'], $openItems, true) ? '▾' : '▸' }}</span>
                                    @if($r['name'])<div class="text-[11.5px] text-fg-3 truncate" title="{{ $r['name'] }}">{{ $r['name'] }}</div>@endif
                                </button>
                            @else
                                <span class="mono">{{ $r['sku'] ?: '—' }}</span>
                            @endif
                        </td>
                    @endif
                    <td class="px-3 py-1.5 break-words">
                        {{-- Поставщик и текст предложения одной строкой; текст — полностью, переносится. --}}
                        <div>
                            <span class="font-medium text-fg-1">{{ $r['supplier'] }}</span>
                            @if($isBest)<span class="chip chip-ok text-[10px]">лучшее</span>@endif
                            @if($r['note'])<span class="text-[11.5px] text-fg-3">· {{ $r['note'] }}</span>@endif
                        </div>
                        @if($deviation)
                            <div class="mt-0.5">
                                <span class="chip chip-warn text-[10.5px] font-semibold" title="Не оригинал — учитывать или нет, решает снабжение">{{ mb_strtoupper(\App\Models\QuotePartsOffer::kindLabel($r['kind'])) }} · не оригинал</span>
                                @if($r['product'])<span class="mono text-[11px] text-fg-2">{{ $r['product'] }}</span>@endif
                            </div>
                        @endif
                        @if($r['stale'])<div class="text-[10.5px] text-fg-4">устарело</div>@endif
                        {{-- Уточнение у поставщика: ждём ответа (решение отложено), ответил, не ответил в срок. --}}
                        @if($cl)
                            <div class="mt-1">
                                @if($clWaiting)
                                    <span class="chip chip-warn text-[10.5px]" title="Спросили {{ $cl['asked_at']->timezone($tzc)->format('d.m H:i') }}; ждём ответа до {{ $cl['until']->timezone($tzc)->format('d.m H:i') }} (1 рабочий день)">⏳ задан уточняющий вопрос · ждём ответа до {{ $cl['until']->timezone($tzc)->format('d.m H:i') }}</span>
                                @elseif($cl['state'] === \App\Services\QuoteParts\OfferClarifications::ANSWERED)
                                    <span class="chip chip-ok text-[10.5px]" title="Спросили {{ $cl['asked_at']->timezone($tzc)->format('d.m H:i') }}">↩ ответил на уточнение {{ $cl['answered_at']->timezone($tzc)->format('d.m H:i') }} — решите по ответу</span>
                                @else
                                    <span class="chip chip-neutral text-[10.5px]" title="Спросили {{ $cl['asked_at']->timezone($tzc)->format('d.m H:i') }}">⌛ на уточнение не ответил за рабочий день</span>
                                @endif
                            </div>
                        @endif
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
                    <td class="px-3 py-1.5">
                        <div class="flex flex-wrap items-center gap-1">
                            @if($clWaiting)
                                {{-- Задан уточняющий вопрос: решение — по ответу поставщика (или по истечении рабочего дня). --}}
                                <span class="text-[11px] text-amber-700">решение после ответа</span>
                                <button type="button" wire:click="clearClarification({{ $r['id'] }})" class="text-[11px] text-fg-4 hover:text-fg-1 underline" title="Снять ожидание и решить сейчас">решить без ответа</button>
                            @elseif($deviation && $r['review'] !== 'accepted')
                                {{-- Отклонение: сначала решить, учитывать ли. --}}
                                <button type="button" wire:click="review({{ $r['id'] }}, true)" class="btn btn-sm" title="Цена идёт как цена позиции; в выгрузке 1С — с примечанием, что предложено">Учитывать</button>
                                @if($r['review'] === 'rejected')
                                    <span class="text-[11px] text-fg-4">не учитываем</span>
                                @else
                                    <button type="button" wire:click="review({{ $r['id'] }}, false)" class="btn btn-sm">Не учитывать</button>
                                @endif
                            @else
                                @if($chosenHere)
                                    <span class="chip chip-info text-[10.5px]">✓ закупаем здесь</span>
                                    <button type="button" wire:click="clearChoice({{ $r['cid'] }})" class="text-[11px] text-fg-4 hover:text-red-700">снять</button>
                                @elseif($r['cid'] && $r['key'] && ! $r['stale'])
                                    <button type="button" wire:click="choose({{ $r['id'] }})" class="btn btn-sm {{ $isBest && ! $choice ? 'btn-primary' : '' }}"
                                            title="Это предложение — закупочное: покупаем у этого поставщика (идёт в выгрузку 1С как выбранный поставщик)">Закупаем здесь</button>
                                    @if($chosenSupplier)<span class="text-[10.5px] text-sky-700">уже закупаем у него</span>@endif
                                @endif
                                @if($deviation)
                                    <button type="button" wire:click="review({{ $r['id'] }}, false)" class="text-[11px] text-fg-4 hover:text-red-700" title="Сейчас учитываем это отклонение">не учитывать</button>
                                @endif
                            @endif
                            @if($r['key'])
                                <button type="button" wire:click="openDialog({{ $r['id'] }})" class="btn btn-sm {{ $dialog === $r['id'] ? 'btn-primary' : '' }}" title="Уточнить у поставщика — переписка по запросу или обращение">💬 Уточнить</button>
                            @endif
                        </div>
                    </td>
                </tr>
                @if($dialog === $r['id'])
                    <tr wire:key="po-dialog-{{ $r['id'] }}" class="border-b border-border-subtle bg-surface-2">
                        <td colspan="{{ $cols }}" class="px-4 py-3">
                            @if($rfq = $this->dialogRfq)
                                <div class="text-[12px] text-fg-3 mb-1.5">Переписка с поставщиком по запросу <span class="mono">{{ $rfq->tag }}</span> — ответ уйдёт в его ветку письмом от MyZip.</div>
                                @include('livewire.quote-parts._rfq-card', ['rfq' => $rfq, 'supplierNames' => app(\App\Services\QuoteParts\QuotePartsRfqService::class)->supplierNames([$rfq]), 'refreshAction' => 'refreshQuotePartsRfq', 'onlySupplier' => $r['key']])
                            @elseif($sup = $this->dialogSupplier)
                                <div class="text-[12px] text-fg-3 mb-1.5">Предложение не по запросу ({{ $sources[$r['source']] ?? $r['source'] }}) — уточнить можно обращением к поставщику через QuoteParts.</div>
                                <livewire:suppliers.quote-parts-conversations :supplier="$sup" :key="'po-conv-'.$r['id']" />
                            @else
                                <div class="text-[12.5px] text-fg-3">Поставщик не найден в справочнике — написать ему из mzCorp нельзя.</div>
                            @endif
                        </td>
                    </tr>
                @endif
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
