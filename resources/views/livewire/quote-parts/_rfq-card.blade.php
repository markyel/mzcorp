{{-- Запрос через QuoteParts: снимок ответов поставщиков. Нужны $rfq, $supplierNames; кнопка «Обновить» зовёт $refreshAction (по умолчанию refresh) компонента. --}}
@php $snap = $rfq->snapshot ?? []; @endphp
<div wire:key="qpr-{{ $rfq->id }}" class="border border-border rounded-md">
    <div class="flex flex-wrap items-center gap-2 px-3 py-2 border-b border-border-subtle">
        <span class="mono font-semibold">{{ $rfq->tag }}</span>
        <span class="chip {{ ($snap['status'] ?? $rfq->status) === 'open' ? 'chip-sky' : 'chip-neutral' }} text-[10.5px]">{{ ($snap['status'] ?? $rfq->status) === 'open' ? 'открыт' : 'закрыт' }}</span>
        <span class="text-fg-3 text-[12px]">{{ $rfq->created_at->format('d.m.Y H:i') }} · {{ $rfq->createdBy?->name }}{{ $rfq->reply_by ? ' · ответить до '.$rfq->reply_by->format('d.m.Y') : '' }}</span>
        <span class="text-fg-4 text-[11px] ml-auto">обновлено {{ $rfq->synced_at?->format('d.m H:i') }}</span>
        <button type="button" wire:click="{{ $refreshAction ?? 'refresh' }}({{ $rfq->id }})" class="btn btn-xs">Обновить</button>
        <a href="{{ rtrim(config('services.quoteparts.url'), '/') }}/rfqs/{{ $rfq->remote_id }}" target="_blank" rel="noopener" class="btn btn-xs">В QuoteParts ↗</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-[12.5px]">
            <tbody>
                @foreach($snap['suppliers'] ?? [] as $s)
                    @foreach($s['lines'] ?? [] as $line)
                        <tr class="border-b border-border-subtle align-top">
                            @if($loop->first)
                                <td class="px-3 py-1.5 font-medium" rowspan="{{ count($s['lines']) }}">
                                    {{ $supplierNames[$s['external_id']] ?? $s['external_id'] }}
                                    @if(! empty($s['send_error']))<div class="text-[11px] text-red-700">{{ $s['send_error'] }}</div>@endif
                                </td>
                            @endif
                            <td class="px-3 py-1.5 mono">{{ $line['code'] }}</td>
                            <td class="px-3 py-1.5">
                                @if(($line['status'] ?? '') === 'offered' && ! empty($line['offer']))
                                    @foreach($line['offer']['tiers'] as $t)
                                        <span class="mono font-semibold">{{ number_format((float) $t['price'], 2, ',', ' ') }} {{ $line['offer']['currency'] }}</span><span class="text-fg-4 text-[11px]"> от {{ $t['min_qty'] }}</span>@if(! $loop->last), @endif
                                    @endforeach
                                    <span class="text-fg-3 text-[11.5px]">
                                        {{ $line['offer']['lead_time_days'] ? '· '.$line['offer']['lead_time_days'].' дн.' : '' }}
                                        {{ ($line['offer']['kind'] ?? '') === 'analog' ? '· аналог' : '' }}
                                        {{ $line['offer']['valid_until'] ? '· до '.\Illuminate\Support\Carbon::parse($line['offer']['valid_until'])->format('d.m.Y') : '' }}
                                    </span>
                                @else
                                    @php $st = $line['status'] ?? 'pending'; @endphp
                                    <span class="text-[11.5px] {{ $st === 'declined' ? 'text-red-700' : ($st === 'question' ? 'text-amber-700' : 'text-fg-4') }}">
                                        {{ ['pending' => 'ждём ответа', 'declined' => 'отказ', 'question' => 'вопрос к нам — см. QuoteParts', 'no_answer' => 'нет ответа'][$st] ?? $st }}
                                    </span>
                                    @if(! empty($line['decline_reason']))<span class="text-fg-3 text-[11.5px]">«{{ $line['decline_reason'] }}»</span>@endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
    </div>
</div>
