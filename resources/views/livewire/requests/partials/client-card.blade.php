{{--
    Карточка клиента у адреса в шапке заявки: связанные контрагенты со
    скидками и сводка по заявкам этого e-mail. Данные — ClientCardService.
    $card = ['organizations' => [...], 'stats' => [...]]
--}}
@php
    $s = $card['stats'];
    $email = (string) $req->client_email;
@endphp
<div class="absolute left-0 top-full mt-1.5 z-30 w-[380px] max-w-[calc(100vw-32px)] rounded-md border border-border bg-surface shadow-lg p-3 text-[12.5px] text-fg-2"
     wire:key="client-card-{{ $req->id }}">
    <div class="flex items-center gap-2 mb-2">
        <span class="font-semibold text-fg-1 truncate">{{ $email }}</span>
        <span class="flex-1"></span>
        <button type="button" wire:click="toggleClientCard" class="text-fg-3 hover:text-fg-1 text-[14px] leading-none" title="Закрыть">×</button>
    </div>

    {{-- Контрагенты и скидки --}}
    <div class="text-[10.5px] uppercase tracking-wider text-fg-3 mb-1">Контрагенты</div>
    @forelse($card['organizations'] as $o)
        <div class="flex items-baseline gap-2 py-[3px] border-b border-border-subtle">
            <a href="{{ route('clients.show', $o['id']) }}" class="flex-1 min-w-0 truncate text-sky-700 hover:underline" title="{{ $o['name'] }}">{{ $o['name'] }}</a>
            @if($o['pinned'])
                <span class="text-[10.5px] text-fg-3" title="Закреплён за адресом: автоматика не меняет контрагента">📌</span>
            @endif
            @if($o['defunct'])
                <span class="chip chip-attn text-[10px]">не действует</span>
            @endif
            @if($o['inn'])
                <span class="mono text-[11px] text-fg-4">{{ $o['inn'] }}</span>
            @endif
            <span class="mono tnum w-[64px] text-right {{ $o['cost_plus'] || $o['discount'] > 0 ? 'text-fg-1 font-semibold' : 'text-fg-4' }}">
                @if($o['cost_plus'])
                    <span title="Цена от закупки, скидка не применяется">cost+</span>
                @elseif($o['discount'] > 0)
                    −{{ rtrim(rtrim(number_format($o['discount'], 2, ',', ''), '0'), ',') }}%
                @else
                    без скидки
                @endif
            </span>
        </div>
    @empty
        <p class="text-fg-3">Контрагент к адресу не привязан.</p>
    @endforelse

    {{-- Статистика заявок --}}
    <div class="text-[10.5px] uppercase tracking-wider text-fg-3 mt-3 mb-1">Заявки клиента</div>
    @if($s['total'] === 0)
        <p class="text-fg-3">Других заявок нет.</p>
    @else
        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
            <dt class="text-fg-3">Заявок</dt>
            <dd class="text-fg-1">
                <b class="mono tnum">{{ $s['total'] }}</b>
                <span class="text-fg-3">с {{ $s['first_at']?->format('d.m.Y') }}, за 90 дн. — {{ $s['last_90'] }}</span>
            </dd>

            <dt class="text-fg-3">Частота</dt>
            <dd class="text-fg-1">
                @if($s['every_days'] !== null)
                    раз в ~<b class="mono tnum">{{ $s['every_days'] }}</b> дн. <span class="text-fg-3">(за год)</span>
                @else
                    <span class="text-fg-3">мало заявок за год</span>
                @endif
            </dd>

            <dt class="text-fg-3">Получили КП</dt>
            <dd class="text-fg-1">
                <b class="mono tnum">{{ (int) $s['quoted_pct'] }}%</b>
                <span class="text-fg-3">({{ $s['quoted'] }} из {{ $s['total'] }})</span>
            </dd>

            <dt class="text-fg-3" title="Выигранные среди закрытых заявок с КП; открытые не учитываются">Win rate с КП</dt>
            <dd class="text-fg-1">
                @if($s['win_rate'] !== null)
                    <b class="mono tnum {{ $s['win_rate'] >= 30 ? 'text-emerald-700' : ($s['win_rate'] < 10 ? 'text-amber-700' : '') }}">{{ (int) $s['win_rate'] }}%</b>
                    <span class="text-fg-3">({{ $s['won'] }} из {{ $s['won'] + $s['lost'] }}{{ $s['open_quoted'] ? ', ещё '.$s['open_quoted'].' в работе' : '' }})</span>
                @else
                    <span class="text-fg-3">закрытых с КП нет{{ $s['open_quoted'] ? ', '.$s['open_quoted'].' в работе' : '' }}</span>
                @endif
            </dd>

            <dt class="text-fg-3">Оплаты за 30 дн.</dt>
            <dd class="text-fg-1">
                @if($s['paid_count'] > 0)
                    <b class="mono tnum">{{ number_format($s['paid_sum'], 0, ',', ' ') }} ₽</b>
                    <span class="text-fg-3">({{ $s['paid_count'] }} {{ $s['paid_count'] % 10 === 1 && $s['paid_count'] % 100 !== 11 ? 'счёт' : (in_array($s['paid_count'] % 10, [2, 3, 4], true) && ! in_array($s['paid_count'] % 100, [12, 13, 14], true) ? 'счёта' : 'счетов') }})</span>
                @else
                    <span class="text-fg-3">не было</span>
                @endif
            </dd>
        </dl>
        <a href="{{ route('requests.index', ['q' => $email, 'scope' => 'all']) }}"
           class="inline-block mt-2 text-[12px] text-sky-700 hover:underline">Все заявки клиента →</a>
    @endif
</div>
