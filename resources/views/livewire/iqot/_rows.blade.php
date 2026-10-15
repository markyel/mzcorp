{{-- Таблица раздела IQOT: строка на позицию каталога — заявки на КП и мониторинг цен вместе.
     Цены рынка — App\Services\Iqot\IqotMarket (без НДС). Ожидает $stats, $statusClass, $iqotItemLabel. --}}
<table class="w-full text-[12.5px]">
    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
        <tr>
            <th class="px-2 py-2 text-left">Позиция</th>
            <th class="px-2 py-2 text-left">OEM</th>
            <th class="px-2 py-2 text-right">Кол-во</th>
            <th class="px-2 py-2 text-right">В КП</th>
            <th class="px-2 py-2 text-left">Источник</th>
            <th class="px-2 py-2 text-left">Статус</th>
            <th class="px-2 py-2 text-right" title="Лучшая цена поставщиков без НДС: КП по заявкам и мониторинг вместе">Лучшая, без НДС</th>
            <th class="px-2 py-2 text-right">Предложений</th>
            <th class="px-2 py-2 text-left">Обновлено</th>
            <th class="px-2 py-2 text-right"></th>
        </tr>
    </thead>
    @foreach($this->positions as $row)
        @php
            $ci = $row->cat;
            $p = $row->pos;
            $mon = $row->mon;
            $mk = $row->market;
            $fresh = $p?->hasFreshReport() ?? false;
            $alert = $p?->pricingAlert();
            $isCritical = ($alert['level'] ?? null) === 'critical';
            $updated = collect([$p?->analyzed_at, $mon?->prices_synced_at])->filter()->max();
        @endphp
        <tbody wire:key="iqc-{{ $ci->id }}" x-data="{ open: false }">
            <tr class="border-b border-border-subtle align-top {{ $isCritical ? 'bg-red-50 hover:bg-red-100 border-l-2 border-l-red-500' : 'hover:bg-hover' }}">
                <td class="px-2 py-1.5">
                    <div class="text-fg-1">{{ \Illuminate\Support\Str::limit($ci->name ?? '—', 60) }}</div>
                    <div class="mono text-[11px] text-fg-3">{{ $ci->sku ?? '—' }}</div>
                    @if($alert)
                        @php $devStr = $alert['deviation_pct'] !== null ? ' · +' . number_format($alert['deviation_pct'], 0, ',', ' ') . '%' : ''; @endphp
                        @if($alert['level'] === 'critical')
                            <span class="inline-flex items-center gap-1 mt-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-red-600 text-white"
                                  title="Цена выше рынка (по КП заявки): {{ $alert['rank'] }}-е место из {{ $alert['total'] }} при {{ $alert['suppliers'] }} поставщиках. Приоритет работы по ценообразованию.">
                                🔴 выше рынка · {{ $alert['rank'] }}-е из {{ $alert['total'] }}{{ $devStr }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 mt-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-red-50 text-red-700 border border-red-200"
                                  title="Наша цена {{ $alert['rank'] }}-е место из {{ $alert['total'] }}; на {{ number_format($alert['deviation_pct'], 1, ',', ' ') }}% дороже лучшей цены IQOT (без НДС). Требует пересмотра цены.">
                                ⚠ пересмотреть цену · {{ $alert['rank'] }}-е{{ $devStr }}
                            </span>
                        @endif
                    @endif
                </td>
                <td class="px-2 py-1.5 mono text-[11.5px] text-fg-2">{{ $p?->payload_oem ?: ($mon?->payload['article'] ?? null) ?: ($ci->oemForExternal() ?: '—') }}</td>
                <td class="px-2 py-1.5 text-right mono text-fg-1 whitespace-nowrap">
                    @if($p && $p->qty !== null){{ rtrim(rtrim(number_format((float) $p->qty, 3, '.', ''), '0'), '.') }} <span class="text-fg-3 text-[11px]">{{ $p->unit ?: 'шт.' }}</span>@else <span class="text-fg-4">—</span>@endif
                </td>
                <td class="px-2 py-1.5 text-right mono {{ ($p?->lost_quote_count ?? 0) > 1 ? 'text-amber-700 font-semibold' : 'text-fg-2' }}">{{ $p?->lost_quote_count ?: '—' }}</td>
                <td class="px-2 py-1.5 text-[11px] whitespace-nowrap">
                    @if($p)<div class="{{ $p->source === 'manual' ? 'text-sky-700' : 'text-fg-3' }}">заявка · {{ $p->source === 'manual' ? 'ручная' : 'из КП' }}</div>@endif
                    @if($mon)<div class="{{ $mon->active ? 'text-emerald-700' : 'text-fg-4' }}">мониторинг{{ $mon->active ? '' : ' (снят)' }}</div>@endif
                </td>
                <td class="px-2 py-1.5">
                    @if($p)
                        <span class="text-[11.5px] font-medium {{ $statusClass[$p->status] ?? 'text-fg-2' }}">{{ $p->statusEnum()?->label() ?? $p->status }}</span>
                        @if($p->status === 'analyzing' && $p->iqot_item_status)
                            <div class="text-[10px] text-fg-3">{{ $iqotItemLabel[$p->iqot_item_status] ?? $p->iqot_item_status }}</div>
                        @endif
                        @if($p->status === 'analyzing' && $p->last_enqueued_at)
                            @php $daysAnalyzing = (int) abs(now()->diffInDays($p->last_enqueued_at)); @endphp
                            <div class="text-[10px] {{ $daysAnalyzing >= 2 ? 'text-amber-700' : 'text-fg-4' }}">в анализе {{ $daysAnalyzing }} дн</div>
                        @endif
                        @if($p->status === 'failed' && $p->error_message)
                            <div class="text-[10px] text-red-600" title="{{ $p->error_message }}">{{ \Illuminate\Support\Str::limit($p->error_message, 40) }}</div>
                        @endif
                    @endif
                    @if($mon && $mon->active)
                        <div class="text-[10.5px] {{ in_array($mon->match_status, ['ambiguous', 'rejected'], true) || $mon->import_error ? 'text-amber-700' : 'text-fg-3' }}">мониторинг: {{ $mon->import_error ? 'ошибка загрузки' : ($mon->best_price !== null ? 'есть цены' : (\App\Models\IqotMonitoringItem::MATCH_LABELS[$mon->match_status] ?? 'отправлена')) }}</div>
                    @endif
                </td>
                <td class="px-2 py-1.5 text-right mono whitespace-nowrap">
                    @if($mk['best_net'] !== null)
                        <span class="text-fg-1 font-semibold">{{ number_format((float) $mk['best_net'], 0, ',', ' ') }} ₽</span>
                        @if($mk['our_rank'])<div class="text-[10.5px] {{ $mk['delta'] > 0 ? 'text-red-700' : 'text-emerald-700' }}">мы {{ $mk['our_rank'] }}-е из {{ $mk['total'] }}{{ $mk['delta_pct'] !== null ? ' · '.($mk['delta_pct'] > 0 ? '+' : '').number_format($mk['delta_pct'], 0, ',', ' ').'%' : '' }}</div>@endif
                    @else
                        <span class="text-fg-4">—</span>
                    @endif
                </td>
                <td class="px-2 py-1.5 text-right mono text-fg-2 whitespace-nowrap">
                    @if($mk['offers'] > 0)
                        <button type="button" @click="open = !open" class="text-sky-700 hover:underline" title="КП по заявке: {{ $mk['from_requests'] }}, мониторинг: {{ $mk['from_monitoring'] }}">{{ $mk['offers'] }} <span x-text="open ? '▾' : '▸'"></span></button>
                        <div class="text-[10px] text-fg-4">{{ $mk['from_requests'] > 0 ? 'КП '.$mk['from_requests'] : '' }}{{ $mk['from_requests'] > 0 && $mk['from_monitoring'] > 0 ? ' + ' : '' }}{{ $mk['from_monitoring'] > 0 ? 'мон. '.$mk['from_monitoring'] : '' }}</div>
                    @else
                        —
                    @endif
                </td>
                <td class="px-2 py-1.5 text-[11.5px]">
                    @if($updated)
                        <span class="{{ $fresh || $mon?->prices_synced_at ? 'text-emerald-700' : 'text-fg-3' }}">{{ \Illuminate\Support\Carbon::parse($updated)->format('d.m.Y') }}</span>
                        @if($p && $p->analyzed_at && ! $fresh && ! $mon)<span class="text-[10px] text-amber-700 ml-1">устарел</span>@endif
                    @else
                        <span class="text-fg-4">—</span>
                    @endif
                </td>
                <td class="px-2 py-1.5 text-right whitespace-nowrap">
                    @if($p)
                        @if($p->status === 'excluded')
                            <button type="button" wire:click="unexclude({{ $p->id }})" class="btn btn-sm" wire:loading.attr="disabled">Вернуть в пул</button>
                        @else
                            {{-- Заявки на КП: «Повторить»/«Переотправить» — только в режиме заявок (в мониторинге пачки не шлём). --}}
                            @if($stats['mode'] !== 'monitoring' && ($p->status === 'failed' || (in_array($p->status, ['completed', 'no_offers'], true) && ! $fresh)))
                                <button type="button" wire:click="reanalyze({{ $p->id }})" class="btn btn-sm" wire:loading.attr="disabled">Повторить</button>
                            @endif
                            @if($stats['mode'] !== 'monitoring' && $p->status === 'analyzing')
                                <button type="button" wire:click="resendAnalysis({{ $p->id }})"
                                        wire:confirm="Переотправить позицию на анализ в IQOT? Текущая (зависшая) отправка отменяется, позиция уйдёт заново."
                                        class="btn btn-sm" wire:loading.attr="disabled">Переотправить</button>
                            @endif
                            <button type="button" wire:click="exclude({{ $p->id }})"
                                    wire:confirm="Исключить позицию из пула заявок? Она больше не будет отправляться в IQOT на КП."
                                    class="btn btn-sm btn-danger" wire:loading.attr="disabled">Исключить</button>
                        @endif
                    @endif
                </td>
            </tr>
            @if($mk['offers'] > 0)
                <tr x-show="open" x-cloak class="border-b border-border-subtle bg-surface-2">
                    <td colspan="10" class="px-4 py-2.5">
                        @include('livewire.iqot._market', ['market' => $mk, 'mon' => $mon, 'pos' => $p])
                    </td>
                </tr>
            @endif
        </tbody>
    @endforeach
</table>
