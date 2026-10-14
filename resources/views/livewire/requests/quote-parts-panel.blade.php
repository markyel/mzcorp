<div>
@if($this->enabled || $this->rfqs->isNotEmpty())
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>QuoteParts</h3>
            <span class="text-[12px] text-fg-3 ml-2">запрос поставщикам через платформу · пилот</span>
            @if($this->enabled && ! $open)
                <button type="button" wire:click="$set('open', true)" class="btn btn-sm btn-primary ml-auto">Запрос через QuoteParts</button>
            @endif
        </div>
        <div class="ds-card-body space-y-3 text-[13px]">
            @foreach($this->rfqs as $rfq)
                @php $snap = $rfq->snapshot ?? []; @endphp
                <div wire:key="qpr-{{ $rfq->id }}" class="border border-border rounded-md">
                    <div class="flex flex-wrap items-center gap-2 px-3 py-2 border-b border-border-subtle">
                        <span class="mono font-semibold">{{ $rfq->tag }}</span>
                        <span class="chip {{ ($snap['status'] ?? $rfq->status) === 'open' ? 'chip-sky' : 'chip-neutral' }} text-[10.5px]">{{ ($snap['status'] ?? $rfq->status) === 'open' ? 'открыт' : 'закрыт' }}</span>
                        <span class="text-fg-3 text-[12px]">{{ $rfq->created_at->format('d.m.Y H:i') }} · {{ $rfq->createdBy?->name }}{{ $rfq->reply_by ? ' · ответить до '.$rfq->reply_by->format('d.m.Y') : '' }}</span>
                        <span class="text-fg-4 text-[11px] ml-auto">обновлено {{ $rfq->synced_at?->format('d.m H:i') }}</span>
                        <button type="button" wire:click="refresh({{ $rfq->id }})" class="btn btn-xs">Обновить</button>
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
            @endforeach

            @if($this->enabled && $open)
                @php $inputCls = 'h-[28px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500'; @endphp
                <div class="border border-sky-200 bg-sky-50/40 rounded-md p-3 space-y-3">
                    <div class="text-[12px] text-fg-3">Письмо поставщику QuoteParts отправит сам — от MyZip, на английском и китайском, с формой ответа. Ответы придут сюда.</div>

                    <div>
                        <div class="text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Позиции</div>
                        @foreach($this->catalogItems as $it)
                            <label wire:key="qpi-{{ $it->id }}" class="flex items-center gap-2 py-0.5 {{ $it->catalog_item_id ? '' : 'opacity-50' }}">
                                <input type="checkbox" wire:model="items.{{ $it->id }}" @disabled(! $it->catalog_item_id) class="w-4 h-4 accent-sky-600">
                                <span class="mono w-[70px]">{{ $it->catalogItem?->sku ?? '—' }}</span>
                                <span class="flex-1 truncate">{{ $it->catalogItem?->name ?? $it->parsed_name }}</span>
                                @if($it->catalog_item_id)
                                    <input type="text" wire:model="qty.{{ $it->id }}" class="{{ $inputCls }} w-[70px] text-right">
                                @else
                                    <span class="text-[11px] text-fg-4">без M-артикула — только «как раньше»</span>
                                @endif
                            </label>
                        @endforeach
                    </div>

                    <div>
                        <div class="text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Поставщики, принявшие приглашение в QuoteParts</div>
                        @forelse($this->activeSuppliers as $s)
                            <label wire:key="qps-{{ $s->id }}" class="inline-flex items-center gap-1.5 mr-4 py-0.5">
                                <input type="checkbox" wire:model="suppliers.{{ $s->id }}" class="w-4 h-4 accent-sky-600"> {{ $s->organization?->name ?: ($s->name ?: $s->email) }}
                            </label>
                        @empty
                            <div class="text-fg-4 text-[12px]">Пока никто не принял приглашение. Пригласить — в карточке поставщика.</div>
                        @endforelse
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <label class="flex items-center gap-2">Ответить до <input type="date" wire:model="replyBy" class="{{ $inputCls }}"></label>
                        <input type="text" wire:model="message" placeholder="Сообщение поставщикам (по-английски, необязательно)" class="{{ $inputCls }} flex-1 min-w-[240px]">
                    </div>

                    @if($error)<div class="text-red-700 text-[12.5px]">{{ $error }}</div>@endif

                    <div class="flex gap-2">
                        <button type="button" wire:click="send" wire:loading.attr="disabled" wire:target="send" class="btn btn-sm btn-primary">Отправить через QuoteParts</button>
                        <button type="button" wire:click="$set('open', false)" class="btn btn-sm">Отмена</button>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endif
</div>
