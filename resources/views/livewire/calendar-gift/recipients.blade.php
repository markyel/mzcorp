{{-- «Календарь в подарок» — список получателей (Livewire CalendarGift\Recipients). --}}
@php
    $tz = config('app.timezone');
    $rows = $this->recipients;
    $c = $this->counts;
    $filters = ['active' => 'Все', 'new' => 'Записаны', 'sent' => 'Отправлены', 'cancelled' => 'Отменены'];
@endphp
<div style="display: flex; flex-direction: column; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
        <h1 style="font-size: 18px; font-weight: 600; margin: 0;">Календарь {{ $this->campaign() }} в подарок</h1>
        <span class="chip {{ $open ? 'chip-ok' : 'chip-neutral' }}">{{ $open ? 'запись открыта' : 'запись закрыта' }}{{ $until ? ' · до '.\Illuminate\Support\Carbon::parse($until)->format('d.m.Y') : '' }}</span>
        <span style="font-size: 13px; color: var(--fg-3);">записались {{ $c['total'] }} · отправлено {{ $c['sent'] }}@if($c['unsubscribed']) · <span style="color: var(--red-700);">отписались {{ $c['unsubscribed'] }}</span>@endif @if($c['cancelled'])· отменены {{ $c['cancelled'] }}@endif</span>
    </div>

    <div style="display: flex; flex-direction: column; gap: 6px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg-surface-2); font-size: 13px;">
        <div style="color: var(--fg-2);">Ссылки для письма клиентам и поста в канале: бот попросит подписаться на канал, проверит подписку и спросит, куда отправить календарь.</div>
        @foreach($this->links as $label => $url)
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;" x-data="{ copied: false }">
                <b style="width: 70px;">{{ $label }}</b>
                @if($url)
                    <code style="font-size: 12.5px;">{{ $url }}</code>
                    <button type="button" class="btn btn-xs" x-on:click="navigator.clipboard.writeText(@js($url)).then(() => { copied = true; setTimeout(() => copied = false, 1500) })" x-text="copied ? 'скопировано' : 'копировать'"></button>
                @else
                    <span style="color: var(--fg-3);">бот не подключён</span>
                @endif
            </div>
        @endforeach
    </div>

    @if($note)<div class="chip chip-ok" style="align-self: flex-start;">{{ $note }}</div>@endif

    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Имя, компания, телефон, адрес"
               style="height: 30px; padding: 0 10px; border: 1px solid var(--border-strong); border-radius: 6px; font-size: 13px; min-width: 260px;">
        @foreach($filters as $key => $label)
            <button type="button" wire:click="$set('statusFilter', '{{ $key }}')" class="chip {{ $statusFilter === $key ? 'chip-info' : 'chip-neutral' }}">{{ $label }}</button>
        @endforeach
        <span style="flex: 1"></span>
        <button type="button" wire:click="recheck" wire:loading.attr="disabled" class="btn btn-sm" title="Перед отправкой: подписаны ли ещё получатели">Проверить подписки</button>
        <a href="{{ route('calendar-gift.export') }}" class="btn btn-sm btn-primary">Скачать Excel</a>
    </div>

    <div style="overflow-x: auto; border: 1px solid var(--border); border-radius: 8px; background: var(--bg-surface);">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="text-align: left; color: var(--fg-3); font-size: 12px; border-bottom: 1px solid var(--border-strong);">
                    <th style="padding: 8px 10px;">Получатель</th><th style="padding: 8px 10px;">Телефон</th><th style="padding: 8px 10px;">Адрес</th>
                    <th style="padding: 8px 10px;">Канал</th><th style="padding: 8px 10px;">Подписка</th><th style="padding: 8px 10px;">Записался</th><th style="padding: 8px 10px;">Статус</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    <tr wire:key="cg-{{ $r->id }}" style="border-top: 1px solid var(--border-subtle); vertical-align: top; {{ $r->status === 'cancelled' ? 'opacity: .55;' : '' }}">
                        <td style="padding: 8px 10px;">
                            <div style="font-weight: 600;">{{ $r->contact_name }}</div>
                            <div style="color: var(--fg-2);">{{ $r->company_name }}</div>
                            @if($r->organization)<a href="{{ route('clients.show', $r->organization_id) }}" style="font-size: 12px; color: var(--sky-700);">{{ $r->organization->name }}</a>@endif
                        </td>
                        <td style="padding: 8px 10px; white-space: nowrap;">{{ $r->phone }}</td>
                        <td style="padding: 8px 10px; max-width: 360px;">{{ $r->address }}</td>
                        <td style="padding: 8px 10px;">{{ $r->channel->label() }}</td>
                        <td style="padding: 8px 10px; white-space: nowrap;">
                            <span class="chip {{ $r->subscribed ? 'chip-ok' : 'chip-danger' }}">{{ $r->subscribed ? 'подписан' : 'отписался' }}</span>
                            @if($r->subscription_checked_at)<div style="font-size: 11.5px; color: var(--fg-3);">проверено {{ $r->subscription_checked_at->timezone($tz)->format('d.m H:i') }}</div>@endif
                        </td>
                        <td style="padding: 8px 10px; white-space: nowrap; color: var(--fg-3);">{{ $r->created_at?->timezone($tz)->format('d.m.Y H:i') }}</td>
                        <td style="padding: 8px 10px; white-space: nowrap;">
                            <select wire:change="setStatus({{ $r->id }}, $event.target.value)" style="font-size: 12.5px; padding: 3px 6px; border: 1px solid var(--border-strong); border-radius: 6px;"
                                    title="«Отправлен» — получателю уйдёт сообщение в бот">
                                @foreach(\App\Models\CalendarGiftRecipient::STATUSES as $key => $label)
                                    <option value="{{ $key }}" @selected($r->status === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @if($r->sent_at)<div style="font-size: 11.5px; color: var(--fg-3);">{{ $r->sent_at->timezone($tz)->format('d.m.Y') }}</div>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="padding: 24px; text-align: center; color: var(--fg-3);">Пока никто не записался.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
