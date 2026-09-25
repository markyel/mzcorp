<div class="space-y-4 max-w-[760px]">
    @php
        $p = $linkRequest;
        $org = $p->organization;
        $what = $p->documentLabel() . ($p->document_number ? ' № ' . $p->document_number : '');
        $known = collect($p->known_emails ?? []);
    @endphp

    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('clients.index') }}" wire:navigate class="text-[12px] text-sky-700 hover:underline">← Клиенты</a>
        <h2 class="text-[16px] font-semibold text-fg-1">Привязка реквизитов к заказчику</h2>
        <span class="chip text-[10.5px]"
              style="{{ $p->isPending()
                  ? 'background:var(--amber-50);color:var(--amber-700)'
                  : ($p->status === \App\Enums\OrganizationLinkStatus::Confirmed
                      ? 'background:var(--emerald-50);color:var(--emerald-700)'
                      : 'background:var(--red-50);color:var(--red-700)') }}">{{ $p->status->label() }}</span>
    </div>

    <div class="ds-card">
        <div class="ds-card-body space-y-3 text-[13px] text-fg-2">
            <p>
                @if($p->request)
                    По заявке <a href="{{ route('requests.show', $p->request_id) }}" class="text-sky-700 hover:underline font-medium">{{ $p->request->internal_code }}</a>
                    выдан {{ $what }}
                @else
                    Выдан {{ $what }}
                @endif
                с реквизитами
                <a href="{{ route('clients.show', $p->organization_id) }}" wire:navigate class="text-sky-700 hover:underline font-medium">{{ $org?->name }}</a>@if($org?->inn)<span class="mono text-fg-3">, ИНН {{ $org->inn }}</span>@endif.
            </p>

            <dl class="grid grid-cols-[180px_1fr] gap-x-3 gap-y-1.5 text-[12.5px]">
                <dt class="text-fg-3">Адрес заказчика</dt>
                <dd>
                    <a href="{{ route('clients.contact', $p->client_contact_id) }}" wire:navigate class="mono text-sky-700 hover:underline">{{ $p->contact?->email }}</a>
                    <span class="text-fg-4">— к этим реквизитам не привязан</span>
                </dd>
                <dt class="text-fg-3">Реквизиты уже привязаны к</dt>
                <dd class="mono text-fg-2">{{ $known->isEmpty() ? '—' : $known->implode(', ') }}</dd>
                @if($p->request?->subject)
                    <dt class="text-fg-3">Тема заявки</dt>
                    <dd>{{ \Illuminate\Support\Str::limit($p->request->subject, 120) }}</dd>
                @endif
                <dt class="text-fg-3">Письмо получил</dt>
                <dd>{{ $p->notifiedUser?->name ?? '—' }}@if($p->notified_at) <span class="text-fg-4">· {{ $p->notified_at->format('d.m.Y H:i') }}</span>@endif</dd>
                @if(! $p->isPending())
                    <dt class="text-fg-3">Решение</dt>
                    <dd>{{ $p->decidedBy?->name ?? '—' }}@if($p->decided_at) <span class="text-fg-4">· {{ $p->decided_at->format('d.m.Y H:i') }}</span>@endif</dd>
                @endif
            </dl>

            @if($p->isPending())
                <div class="pt-3 border-t border-border-subtle space-y-2">
                    <p class="text-fg-2">
                        Это может быть ошибкой: реквизиты чужого контрагента, письмо в чужой заявке, счёт посреднику.
                        Автоматической привязки не произошло.
                    </p>
                    @if($canDecide)
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="btn btn-sm btn-primary" wire:click="confirm" wire:loading.attr="disabled">
                                Подтвердить привязку
                            </button>
                            <button type="button" class="btn btn-sm" wire:click="reject" wire:loading.attr="disabled"
                                    wire:confirm="Отметить как ошибку? Связь не будет создана, и система больше не предложит её для этого адреса.">
                                Это ошибка — не привязывать
                            </button>
                        </div>
                    @else
                        <p class="text-[12px] text-fg-4">Решить может менеджер, выдавший документ, РОП или директор.</p>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
