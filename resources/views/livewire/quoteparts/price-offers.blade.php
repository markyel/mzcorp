{{-- Вкладка «Актуализация цен»: свежие предложения поставщиков из QuoteParts, по заявкам или одним списком. --}}
@php
    $sources = ['form' => 'форма запроса', 'email' => 'письмо', 'cabinet' => 'кабинет', 'price_list' => 'прайс', 'api' => 'API'];
    $money = fn ($v, $d = 2) => $v === null ? '—' : number_format((float) $v, $d, ',', ' ');
    $rows = $this->rows;
@endphp
<div class="space-y-3">
    <div class="flex flex-wrap items-center gap-2 text-[12.5px]">
        <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
            @foreach(\App\Livewire\QuoteParts\PriceOffers::DAYS as $d)
                <button type="button" wire:click="$set('days', {{ $d }})"
                        class="h-[28px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0 {{ $days === $d ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                    {{ $d }} дн.
                </button>
            @endforeach
        </div>
        <label class="inline-flex items-center gap-1.5 cursor-pointer text-fg-2">
            <input type="checkbox" wire:model.live="byRequest" class="w-4 h-4 accent-sky-600"> по заявкам
        </label>
        <label class="inline-flex items-center gap-1.5 cursor-pointer text-fg-2" title="Предложения, заменённые новыми, и с истёкшим сроком действия">
            <input type="checkbox" wire:model.live="withStale" class="w-4 h-4 accent-sky-600"> и устаревшие
        </label>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Артикул, позиция, поставщик, заявка, QP-…"
               class="h-[30px] w-full max-w-[320px] px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
        <span class="text-fg-4">Предложений: {{ $rows->count() }}{{ $rows->count() >= 500 ? ' (показаны последние 500)' : '' }}. Цена в ₽ — по курсу ЦБ; сравнение с закупочной ценой каталога.</span>
    </div>

    @forelse($this->groups as $g)
        <div class="ds-card" wire:key="pog-{{ $g['key'] }}">
            @if($byRequest)
                <div class="ds-card-header flex-wrap gap-y-1">
                    @if($g['request'])
                        <a href="{{ route('requests.show', $g['request']->id) }}" class="mono font-semibold text-sky-700 hover:underline">{{ $g['request']->internal_code }}</a>
                        <span class="text-[12px] text-fg-3 ml-2 truncate max-w-[520px]">{{ $g['request']->client_name ?: '' }}{{ $g['request']->client_name && $g['request']->subject ? ' · ' : '' }}{{ \Illuminate\Support\Str::limit((string) $g['request']->subject, 80) }}</span>
                        @if($g['request']->assignedUser)<span class="text-[12px] text-fg-4 ml-2">· {{ $g['request']->assignedUser->name }}</span>@endif
                    @else
                        <h3>Без заявки</h3>
                        <span class="text-[12px] text-fg-3 ml-2">кабинет, прайсы и запросы не по заявке</span>
                    @endif
                    <span class="ml-auto text-[11.5px] text-fg-3">
                        @foreach($g['rfqs'] as $tag)<span class="mono">{{ $tag }}</span>@if(! $loop->last), @endif @endforeach
                        · предложений {{ $g['rows']->count() }}
                    </span>
                </div>
            @endif
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                        <tr>
                            <th class="text-left px-3 py-2">Когда</th>
                            <th class="text-left px-3 py-2">Позиция</th>
                            <th class="text-left px-3 py-2">Поставщик</th>
                            <th class="text-right px-3 py-2">Цена</th>
                            <th class="text-right px-3 py-2">≈ ₽</th>
                            <th class="text-right px-3 py-2" title="Закупочная цена из каталога (1С)">Закупка</th>
                            <th class="text-right px-3 py-2" title="Цена продажи в каталоге">Продажа</th>
                            <th class="text-left px-3 py-2">Срок · действует</th>
                            @unless($byRequest)<th class="text-left px-3 py-2">Заявка</th>@endunless
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($g['rows'] as $r)
                            <tr wire:key="po-{{ $r['id'] }}" class="border-b border-border-subtle align-top {{ $r['stale'] ? 'opacity-60' : '' }}">
                                <td class="px-3 py-1.5 whitespace-nowrap text-fg-3">
                                    {{ $r['at']?->timezone(config('app.timezone'))->format('d.m H:i') }}
                                    <div class="text-[11px] text-fg-4">{{ $sources[$r['source']] ?? $r['source'] }}</div>
                                </td>
                                <td class="px-3 py-1.5">
                                    <span class="mono">{{ $r['sku'] ?: '—' }}</span>
                                    @if($r['name'])<div class="text-[11.5px] text-fg-3 truncate max-w-[320px]" title="{{ $r['name'] }}">{{ $r['name'] }}</div>@endif
                                </td>
                                <td class="px-3 py-1.5">
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
                                <td class="px-3 py-1.5 text-right mono whitespace-nowrap">
                                    {{ $r['rub'] !== null ? $money($r['rub'], 0).' ₽' : '—' }}
                                    @if($r['delta_pct'] !== null)
                                        <div class="text-[11px] {{ $r['delta_pct'] > 0 ? 'text-red-700' : 'text-emerald-700' }}" title="К закупочной цене каталога">{{ $r['delta_pct'] > 0 ? '+' : '' }}{{ number_format($r['delta_pct'], 0, ',', ' ') }}%</div>
                                    @endif
                                </td>
                                <td class="px-3 py-1.5 text-right mono whitespace-nowrap text-fg-2">{{ $r['purchase'] !== null ? $money($r['purchase'], 0).' ₽' : '—' }}</td>
                                <td class="px-3 py-1.5 text-right mono whitespace-nowrap text-fg-2">
                                    {{ $r['sale'] !== null ? $money($r['sale'], 0).' ₽' : '—' }}
                                    @if($r['sku'])<div class="text-[10.5px] {{ $r['actual'] ? 'text-emerald-700' : 'text-amber-700' }}">{{ $r['actual'] ? 'актуальна' : 'к актуализации' }}</div>@endif
                                </td>
                                <td class="px-3 py-1.5 whitespace-nowrap text-fg-3 text-[11.5px]">
                                    {{ $r['lead'] ? $r['lead'].' дн.' : '—' }}
                                    @if($r['valid'])<div>до {{ $r['valid']->format('d.m.Y') }}</div>@endif
                                </td>
                                @unless($byRequest)
                                    <td class="px-3 py-1.5 whitespace-nowrap">
                                        @if($r['request'])<a href="{{ route('requests.show', $r['request']->id) }}" class="mono text-sky-700 hover:underline">{{ $r['request']->internal_code }}</a>@else<span class="text-fg-4">—</span>@endif
                                        @if($r['rfq_tag'])<div class="mono text-[11px] text-fg-4">{{ $r['rfq_tag'] }}</div>@endif
                                    </td>
                                @endunless
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="ds-card"><div class="ds-card-body text-center text-fg-3 text-[13px] py-8">
            За {{ $days }} дн. свежих предложений нет. Они приходят из QuoteParts каждые 10 минут: ответы на запросы (форма, письмо), кабинет поставщика и прайсы.
        </div></div>
    @endforelse
</div>
