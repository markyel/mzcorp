<div class="space-y-3">
    @php
        $money = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
        $inp = 'h-[30px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500';
    @endphp
    <div class="flex flex-wrap items-center gap-2 text-[12.5px]">
        <div class="inline-flex overflow-hidden rounded-md border border-border">
            @foreach ([1 => 'сутки', 7 => '7 дней', 30 => '30 дней', 90 => '90 дней'] as $d => $label)
                <button type="button" wire:click="$set('days', {{ $d }})" class="h-[30px] border-r border-border px-3 last:border-r-0 {{ $days === $d ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">{{ $label }}</button>
            @endforeach
        </div>
        <select wire:model.live="source" class="{{ $inp }} pr-8">
            <option value="">все каналы</option>
            <option value="quoteparts">QuoteParts</option>
            <option value="email">ответы на рассылку (письма)</option>
        </select>
        <label class="inline-flex items-center gap-1.5">
            <input type="checkbox" wire:model.live="toReview" class="w-4 h-4 accent-sky-600"> только на разборе
            @if($pendingReview > 0)<span class="chip chip-warn text-[10.5px]">{{ $pendingReview }}</span>@endif
        </label>
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Артикул, позиция, поставщик" class="{{ $inp }} ml-auto w-64">
        <span class="text-fg-3">{{ number_format($offers->total(), 0, ',', ' ') }} предложений</span>
    </div>

    <div class="ds-card">
        <div class="overflow-x-auto">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                    <tr>
                        <th class="text-left px-3 py-2">Получено</th>
                        <th class="text-left px-3 py-2">Поставщик</th>
                        <th class="text-left px-3 py-2">Позиция</th>
                        <th class="text-left px-3 py-2">Что предложено</th>
                        <th class="text-right px-3 py-2">Цена</th>
                        <th class="text-left px-3 py-2">Срок / действует</th>
                        <th class="text-left px-3 py-2">Канал</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($offers as $o)
                        @php $ch = $o['cid'] ? $chosen->get($o['cid']) : null; @endphp
                        <tr wire:key="sof-{{ $o['row'] }}" @class(['border-b border-border-subtle align-top hover:bg-hover', 'opacity-60' => $o['stale']])>
                            <td class="px-3 py-1.5 whitespace-nowrap text-fg-3 mono">{{ $o['at']?->format('d.m.Y H:i') }}</td>
                            <td class="px-3 py-1.5 text-fg-1">{{ \Illuminate\Support\Str::limit($o['supplier'], 40) }}</td>
                            <td class="px-3 py-1.5">
                                @if($o['sku'])<a href="{{ route('catalog.search', ['q' => $o['sku']]) }}" target="_blank" class="mono text-sky-700 hover:underline">{{ $o['sku'] }}</a>@endif
                                <div class="text-fg-2 max-w-[340px] truncate" title="{{ $o['name'] }}">{{ $o['name'] }}</div>
                                @if($o['request'])<a href="{{ route('requests.show', $o['request_id']) }}" target="_blank" class="mono text-[11px] text-fg-3 hover:underline">{{ $o['request'] }}</a>@endif
                            </td>
                            <td class="px-3 py-1.5">
                                @if($o['kind'] !== 'original')
                                    <span class="chip chip-sky text-[10.5px]">{{ \App\Models\QuotePartsOffer::kindLabel($o['kind']) }}</span>@if($o['product'] !== '') <span class="mono text-fg-2">{{ $o['product'] }}</span>@endif
                                    @if($o['review'] === 'pending')
                                        <div class="mt-1 flex gap-1">
                                            <button type="button" wire:click="accept({{ $o['ref'] }})" class="btn btn-xs" title="Закупать этот вариант по запрошенной позиции">Принять к закупке</button>
                                            <button type="button" wire:click="reject({{ $o['ref'] }})" class="btn btn-xs btn-ghost">Отклонить</button>
                                        </div>
                                    @else
                                        <span class="chip {{ $o['review'] === 'accepted' ? 'chip-ok' : 'chip-neutral' }} text-[10px]">{{ $o['review'] === 'accepted' ? 'принято к закупке' : 'отклонено' }}</span>
                                    @endif
                                @else
                                    <span class="text-fg-3">оригинал</span>
                                @endif
                                @if($o['note'])<div class="text-fg-3 text-[11.5px] max-w-[300px] truncate" title="{{ $o['note'] }}">«{{ $o['note'] }}»</div>@endif
                                @for($i = 0; $i < $o['photos']; $i++)<a href="{{ route('quoteparts.offer-photo', [$o['remote_id'], $i]) }}" target="_blank" class="text-sky-700 text-[11px]">📷</a>@endfor
                            </td>
                            <td class="px-3 py-1.5 text-right whitespace-nowrap mono font-semibold">{{ $o['price'] !== null ? $money($o['price']).' '.$o['currency'] : '—' }}</td>
                            <td class="px-3 py-1.5 whitespace-nowrap text-fg-3">{{ $o['lead'] !== null ? $o['lead'].' дн.' : '' }}{{ $o['lead'] !== null && $o['valid'] ? ' · ' : '' }}{{ $o['valid'] ? 'до '.$o['valid'] : '' }}</td>
                            <td class="px-3 py-1.5 whitespace-nowrap text-fg-3">{{ $o['channel'] }}</td>
                            <td class="px-3 py-1.5 whitespace-nowrap text-right">
                                @if($o['cid'] && $o['key'] && ! $o['stale'] && ! in_array($o['review'], ['pending', 'rejected'], true))
                                    @if($ch && $ch->supplier_key === $o['key'])
                                        <span class="chip chip-ok text-[10.5px]">✓ закупаем здесь</span>
                                    @else
                                        <button type="button" wire:click="choose('{{ $o['row'] }}')" class="btn btn-xs" title="{{ $ch ? 'Сейчас закупаем у: '.$ch->supplier_name : 'Поставщик позиции не выбран' }}">Закупаем здесь</button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-8 text-center text-fg-3">За этот период предложений нет.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2">{{ $offers->links() }}</div>
    </div>
</div>
