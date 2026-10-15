{{-- Цены поставщиков по позиции из мониторинга IQOT («Гибрид»): сайты и КП по почте.
     Только подтверждённые и по одному на поставщика (IqotMonitoringService::cleanSources).
     Сравниваем и сортируем БЕЗ НДС, как в разделе IQOT; наша цена каталога — тоже без НДС.
     Ожидает $mon (App\Models\IqotMonitoringItem). --}}
@php
    $rub = fn ($v) => $v !== null ? number_format((float) $v, 0, ',', ' ').' ₽' : '—';
    $vatLabel = fn ($v) => $v === true ? 'с НДС' : ($v === false ? 'без НДС' : 'НДС не указан');
    $srcs = collect($mon->sources ?? [])->filter(fn ($s) => isset($s['net']))->sortBy('net')->values();
    $cmp = $mon->comparison();
    $ourShown = false;
@endphp
<div class="mt-2 rounded-md border border-border-subtle bg-surface px-3 py-2">
    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-[12px]">
        <span class="font-semibold text-fg-1">IQOT · мониторинг цен</span>
        @if($mon->best_price !== null)
            <span>лучшая без НДС <b class="mono">{{ $rub($mon->best_price) }}</b> <span class="text-fg-3">({{ $mon->best_source === 'kp' ? 'КП' : 'сайт' }}{{ $mon->best_supplier_domain ? ', '.$mon->best_supplier_domain : '' }})</span></span>
            <span class="text-fg-3">без НДС: мин {{ $rub($mon->price_min) }} · медиана {{ $rub($mon->price_median) }} · макс {{ $rub($mon->price_max) }} · поставщиков {{ $mon->sources_priced }}</span>
            @if($cmp['rank'])
                <span>наша цена — <b>{{ $cmp['rank'] }}-е место</b> из {{ $cmp['total'] }}
                    @if($cmp['delta'] !== null)<b class="{{ $cmp['delta'] > 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ $cmp['delta'] > 0 ? '+' : '' }}{{ number_format($cmp['delta'], 0, ',', ' ') }} ₽ ({{ $cmp['delta'] > 0 ? '+' : '' }}{{ number_format($cmp['delta_pct'], 1, ',', ' ') }}%)</b> к лучшей@endif
                </span>
            @endif
        @else
            <span class="text-fg-3">{{ ! $mon->active ? 'снята с мониторинга' : (\App\Models\IqotMonitoringItem::MATCH_LABELS[$mon->match_status] ?? 'отправлена, ждём цен') }}</span>
        @endif
        <span class="text-fg-4">OEM {{ $mon->payload['article'] ?? '—' }}{{ $mon->prices_synced_at ? ' · обновлено '.$mon->prices_synced_at->format('d.m H:i') : '' }}</span>
    </div>
    @if($mon->match_status === 'ambiguous' || $mon->match_status === 'rejected' || $mon->import_error)
        <div class="mt-1 text-[11.5px] text-amber-800">{{ $mon->import_error ? 'Ошибка загрузки: '.$mon->import_error : '' }}{{ $mon->match_note ? ' '.$mon->match_note : '' }}{{ $mon->match_status === 'ambiguous' ? ' Уточните название или бренд в каталоге.' : '' }}</div>
    @endif
    @if($srcs->isNotEmpty())
        <table class="mt-1.5 w-full text-[12px]">
            <thead class="text-fg-3 text-[10px] uppercase tracking-wider">
                <tr>
                    <th class="py-1 pr-2 text-left w-6">#</th>
                    <th class="py-1 pr-2 text-left">Поставщик</th>
                    <th class="py-1 pr-2 text-right">Цена</th>
                    <th class="py-1 pr-2 text-right">Без НДС</th>
                    <th class="py-1 pr-2 text-left"></th>
                    <th class="py-1 pr-2 text-left">Наличие</th>
                    <th class="py-1 text-right">Дата</th>
                </tr>
            </thead>
            <tbody>
                @php $n = 0; @endphp
                @foreach($srcs as $s)
                    @if(! $ourShown && $cmp['our_net'] !== null && $cmp['our_net'] <= $s['net'])
                        @php $ourShown = true; $n++; @endphp
                        @include('livewire.iqot._monitoring-our-row', ['n' => $n, 'cmp' => $cmp, 'rub' => $rub, 'actual' => (bool) $mon->catalogItem?->is_price_actual])
                    @endif
                    @php $n++; @endphp
                    <tr class="border-t border-border-subtle align-top">
                        <td class="py-1 pr-2 text-fg-4">{{ $n }}</td>
                        <td class="py-1 pr-2 whitespace-nowrap">
                            @if($s['url'])<a href="{{ $s['url'] }}" target="_blank" rel="noopener noreferrer" class="text-sky-700 hover:underline">{{ $s['domain'] }}</a>@else{{ $s['domain'] }}@endif
                            @if(($s['pages'] ?? 1) > 1)<span class="text-fg-4 text-[11px]" title="У поставщика несколько страниц с этим товаром — показана лучшая цена"> · {{ $s['pages'] }} стр.</span>@endif
                        </td>
                        <td class="py-1 pr-2 whitespace-nowrap mono text-right">
                            {{ $rub($s['value']) }}@if(($s['price_kind'] ?? null) === 'from') <span class="text-fg-4">от</span>@endif
                            <div class="text-[10px] {{ $s['vat'] === null ? 'text-amber-700' : 'text-fg-4' }}" @if($s['vat'] === null) title="Источник не указал НДС — считаем, что цена с НДС" @endif>{{ $vatLabel($s['vat']) }}{{ ! is_numeric($s['price'] ?? null) ? ' · по КП' : '' }}</div>
                        </td>
                        <td class="py-1 pr-2 whitespace-nowrap mono text-right font-semibold">{{ $rub($s['net']) }}</td>
                        <td class="py-1 pr-2 whitespace-nowrap">
                            @if(! empty($s['kp']['price']) && is_numeric($s['price'] ?? null))<span class="chip chip-ok text-[10px]" title="Цена из КП поставщика">КП {{ $rub($s['kp']['price']) }}</span>@endif
                            @if(! empty($s['flags']['email_confirmed']))<span class="chip chip-sky text-[10px]" title="Поставщик подтвердил цену сайта по почте">подтверждена</span>@endif
                        </td>
                        <td class="py-1 pr-2 text-fg-3 whitespace-nowrap">{{ ['in_stock' => 'в наличии', 'to_order' => 'под заказ', 'out_of_stock' => 'нет в наличии'][$s['availability'] ?? ''] ?? '' }}</td>
                        <td class="py-1 text-fg-4 whitespace-nowrap text-right">{{ ! empty($s['observed_at']) ? \Illuminate\Support\Carbon::parse($s['observed_at'])->timezone(config('app.timezone'))->format('d.m.Y') : '' }}</td>
                    </tr>
                @endforeach
                @if(! $ourShown && $cmp['our_net'] !== null)
                    @include('livewire.iqot._monitoring-our-row', ['n' => $n + 1, 'cmp' => $cmp, 'rub' => $rub, 'actual' => (bool) $mon->catalogItem?->is_price_actual])
                @endif
            </tbody>
        </table>
    @endif
</div>
