{{-- Цены поставщиков по позиции из мониторинга IQOT («Гибрид»): сайты и КП по почте.
     Ожидает $mon (App\Models\IqotMonitoringItem). --}}
@php
    $rub = fn ($v) => $v !== null ? number_format((float) $v, 0, ',', ' ').' ₽' : '—';
    $srcs = collect($mon->sources ?? [])->sortBy(fn ($s) => $s['price'] ?? ($s['kp']['price'] ?? PHP_INT_MAX))->values();
@endphp
<div class="mt-2 rounded-md border border-border-subtle bg-surface px-3 py-2">
    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-[12px]">
        <span class="font-semibold text-fg-1">IQOT · мониторинг цен</span>
        @if($mon->best_price !== null)
            <span>лучшая <b class="mono">{{ $rub($mon->best_price) }}</b> <span class="text-fg-3">({{ $mon->best_source === 'kp' ? 'КП' : 'сайт' }}{{ $mon->best_supplier_domain ? ', '.$mon->best_supplier_domain : '' }})</span></span>
            <span class="text-fg-3">мин {{ $rub($mon->price_min) }} · медиана {{ $rub($mon->price_median) }} · макс {{ $rub($mon->price_max) }} · источников с ценой {{ $mon->sources_priced }}</span>
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
            <tbody>
                @foreach($srcs as $s)
                    <tr class="border-t border-border-subtle align-top">
                        <td class="py-1 pr-2 whitespace-nowrap">
                            @if($s['url'])<a href="{{ $s['url'] }}" target="_blank" rel="noopener noreferrer" class="text-sky-700 hover:underline">{{ $s['domain'] }}</a>@else{{ $s['domain'] }}@endif
                        </td>
                        <td class="py-1 pr-2 whitespace-nowrap mono">
                            @if($s['price'] !== null){{ $rub($s['price']) }}@if(($s['price_kind'] ?? null) === 'from') <span class="text-fg-4">от</span>@endif
                            @elseif(($s['price_kind'] ?? null) === 'on_request')<span class="text-fg-4">по запросу</span>@endif
                        </td>
                        <td class="py-1 pr-2 whitespace-nowrap">
                            @if(! empty($s['kp']['price']))<span class="chip chip-ok text-[10px]">КП {{ $rub($s['kp']['price']) }}</span>@endif
                            @if(! empty($s['flags']['email_confirmed']))<span class="chip chip-sky text-[10px]" title="Поставщик подтвердил цену сайта по почте">подтверждена</span>@endif
                            @if(($s['status'] ?? null) === 'suspicious')<span class="chip chip-warn text-[10px]" title="Резкий скачок цены, IQOT перепроверяет">перепроверка</span>@endif
                        </td>
                        <td class="py-1 pr-2 text-fg-3 whitespace-nowrap">{{ ['in_stock' => 'в наличии', 'to_order' => 'под заказ', 'out_of_stock' => 'нет в наличии'][$s['availability'] ?? ''] ?? '' }}</td>
                        <td class="py-1 text-fg-4 whitespace-nowrap text-right">{{ ! empty($s['observed_at']) ? \Illuminate\Support\Carbon::parse($s['observed_at'])->timezone(config('app.timezone'))->format('d.m.Y') : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
