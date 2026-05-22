<div class="space-y-4">
    {{-- Header + фильтры --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Счета</h3>
            <span class="text-[12px] text-fg-3 ml-2">Учёт выставленных счетов и оплат</span>
            <span class="flex-1"></span>
            <span class="text-[11.5px] text-fg-3 mono">{{ $this->invoices->total() }} счетов</span>
        </div>

        <div class="px-4 pb-3 flex items-center gap-2 gap-y-2 flex-wrap text-[12px]">
            {{-- Статус --}}
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
                @php
                    $statuses = [
                        'pending'   => 'Ожидает',
                        'overdue'   => 'Просрочены',
                        'paid'      => 'Оплачены',
                        'cancelled' => 'Аннулированы',
                        'expired'   => 'Истекли',
                        'all'       => 'Все',
                    ];
                @endphp
                @foreach($statuses as $k => $label)
                    @php $on = $statusFilter === $k; @endphp
                    <button type="button" wire:click="setStatus('{{ $k }}')"
                            class="h-[26px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            {{-- Период --}}
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
                @php $periods = ['today' => 'Сегодня', '7d' => '7 дн.', '30d' => '30 дн.', '90d' => '90 дн.', 'all' => 'Всё']; @endphp
                @foreach($periods as $k => $label)
                    @php $on = $period === $k; @endphp
                    <button type="button" wire:click="setPeriod('{{ $k }}')"
                            class="h-[26px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            {{-- Scope (только для privileged) --}}
            @if($this->canSeeAll())
                <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
                    @php $scopes = ['mine' => 'Мои', 'all' => 'Все']; @endphp
                    @foreach($scopes as $k => $label)
                        @php $on = $scope === $k; @endphp
                        <button type="button" wire:click="setScope('{{ $k }}')"
                                class="h-[26px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                       {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                {{-- Manager-filter (только при scope=all) --}}
                @if($scope === 'all')
                    <select wire:model.live="managerId"
                            class="h-[26px] px-2 border border-border rounded-md bg-surface text-fg-1 text-[12px] outline-none focus:border-[var(--sky-500)] max-w-[200px]"
                            title="Фильтр по менеджеру">
                        <option value="">👤 Все менеджеры</option>
                        @foreach($this->availableManagers as $mgr)
                            <option value="{{ $mgr->id }}">{{ $mgr->name }}</option>
                        @endforeach
                    </select>
                @endif
            @endif

            {{-- Search --}}
            <input type="search"
                   wire:model.live.debounce.350ms="search"
                   placeholder="№ счёта…"
                   class="h-[26px] px-2 border border-border rounded-md bg-surface text-fg-1 text-[12px] outline-none focus:border-[var(--sky-500)] min-w-[180px] mono" />
        </div>
    </div>

    {{-- Cancel-confirm bar (отображается когда startCancel() вызван) --}}
    @if($cancellingInvoiceId)
        @php
            $cancellingInvoice = $this->invoices->firstWhere('id', $cancellingInvoiceId);
        @endphp
        @if($cancellingInvoice)
            <div class="ds-card p-3 bg-amber-50 border border-amber-300">
                <div class="flex items-start gap-3">
                    <div class="text-amber-800 text-[14px] shrink-0 mt-0.5">⚠</div>
                    <div class="flex-1">
                        <div class="text-[13px] font-medium text-fg-1 mb-1">
                            Аннулировать счёт №{{ $cancellingInvoice->invoice_number }} (заявка {{ $cancellingInvoice->request?->internal_code }})?
                        </div>
                        <div class="text-[11.5px] text-fg-3 mb-2">
                            Заявка вернётся в статус «ожидает счёт», можно будет перевыставить новый. Это действие не отменить.
                        </div>
                        <textarea wire:model="cancellationReason" rows="2" maxlength="500"
                                  placeholder="Причина аннулирования (обязательно)"
                                  class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12px] outline-none focus:border-[var(--sky-500)] resize-none mb-2"></textarea>
                        <div class="flex gap-2">
                            <button type="button"
                                    wire:click="confirmCancel"
                                    wire:loading.attr="disabled"
                                    class="btn btn-sm btn-danger">✕ Аннулировать</button>
                            <button type="button"
                                    wire:click="cancelStartCancel"
                                    class="btn btn-sm">Передумал</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- List --}}
    <div class="ds-card">
        @php $invoices = $this->invoices; @endphp
        @if($invoices->isEmpty())
            <div class="p-12 text-center text-fg-3">
                Счетов не найдено. Попробуй сменить фильтры.
            </div>
        @else
            <div class="overflow-hidden">
            <table class="w-full text-[12.5px] table-fixed">
                <colgroup>
                    <col style="width: 140px">   {{-- № счёта --}}
                    <col style="width: 130px">   {{-- Заявка --}}
                    <col style="width: 110px">   {{-- Выставлен --}}
                    <col style="width: 130px">   {{-- Действителен до --}}
                    <col style="width: 130px">   {{-- Сумма --}}
                    <col style="width: 120px">   {{-- Статус --}}
                    <col>                         {{-- Менеджер / комментарий --}}
                    <col style="width: 210px">   {{-- Actions --}}
                </colgroup>
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                    <tr>
                        <th class="px-3 py-2 text-left">№ счёта</th>
                        <th class="px-3 py-2 text-left">Заявка</th>
                        <th class="px-3 py-2 text-left">Выставлен</th>
                        <th class="px-3 py-2 text-left">Действителен до</th>
                        <th class="px-3 py-2 text-right">Сумма</th>
                        <th class="px-3 py-2 text-left">Статус</th>
                        <th class="px-3 py-2 text-left">Менеджер · комментарий</th>
                        <th class="px-3 py-2 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoices as $inv)
                        @php
                            $req = $inv->request;
                            $isPending = $inv->status?->value === 'pending';
                            $isOverdue = $isPending && $inv->expires_at?->isPast();
                            $daysRemain = $inv->expires_at ? now()->diffInDays($inv->expires_at, false) : null;
                        @endphp
                        <tr wire:key="inv-{{ $inv->id }}" class="border-b border-border-subtle last:border-b-0 hover:bg-hover {{ $isOverdue ? 'bg-red-50' : '' }}">
                            <td class="px-3 py-2 mono text-[12px] text-fg-1 align-top" style="max-width: 0">
                                <span class="truncate block" title="{{ $inv->invoice_number }}">{{ $inv->invoice_number }}</span>
                            </td>
                            <td class="px-3 py-2 align-top">
                                @if($req)
                                    <a href="{{ route('requests.show', $req->id) }}"
                                       wire:navigate
                                       class="chip chip-info hover:opacity-80">
                                        <span class="dot"></span>{{ $req->internal_code }}
                                    </a>
                                @else
                                    <span class="text-fg-4 text-[11px]">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 mono text-[11.5px] text-fg-2 align-top whitespace-nowrap">
                                {{ $inv->issued_at?->format('d.m.Y') ?? '—' }}
                            </td>
                            <td class="px-3 py-2 align-top">
                                <div class="mono text-[11.5px] text-fg-2 whitespace-nowrap">
                                    {{ $inv->expires_at?->format('d.m.Y') ?? '—' }}
                                </div>
                                @if($isPending && $daysRemain !== null)
                                    @if($isOverdue)
                                        <div class="text-[10.5px] text-red-700 font-medium">⚠ просрочен {{ abs($daysRemain) }} дн.</div>
                                    @elseif($daysRemain <= 2)
                                        <div class="text-[10.5px] text-amber-700">⏳ осталось {{ $daysRemain }} дн.</div>
                                    @else
                                        <div class="text-[10.5px] text-fg-3">⏳ {{ $daysRemain }} дн.</div>
                                    @endif
                                @endif
                            </td>
                            <td class="px-3 py-2 mono text-[12px] text-fg-1 align-top text-right whitespace-nowrap">
                                @if($inv->amount_snapshot !== null)
                                    {{ number_format((float) $inv->amount_snapshot, 2, '.', ' ') }} ₽
                                @else
                                    <span class="text-fg-4">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 align-top">
                                <span class="chip {{ $this->statusChipClass($inv->status?->value ?? '') }}">
                                    <span class="dot"></span>{{ $this->statusLabel($inv->status?->value ?? '') }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-[11.5px] text-fg-2 align-top" style="max-width: 0">
                                @if($req?->assignedUser)
                                    <div class="truncate text-fg-1" title="{{ $req->assignedUser->name }}">{{ $req->assignedUser->name }}</div>
                                @endif
                                @if($inv->comment)
                                    <div class="truncate text-fg-3 text-[11px]" title="{{ $inv->comment }}">{{ $inv->comment }}</div>
                                @elseif($inv->cancellation_reason)
                                    <div class="truncate text-red-700 text-[11px]" title="{{ $inv->cancellation_reason }}">отменён: {{ $inv->cancellation_reason }}</div>
                                @elseif($inv->paid_at)
                                    <div class="text-emerald-700 text-[11px] mono">оплачен {{ $inv->paid_at->format('d.m.Y') }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right align-top whitespace-nowrap">
                                @if($isPending)
                                    <button type="button"
                                            wire:click="markPaid({{ $inv->id }})"
                                            wire:confirm="Пометить счёт №{{ $inv->invoice_number }} как оплаченный? Заявка перейдёт в статус «Оплачено»."
                                            class="btn btn-sm btn-primary">✓ Оплачен</button>
                                    <button type="button"
                                            wire:click="startCancel({{ $inv->id }})"
                                            class="btn btn-sm text-red-700"
                                            title="Аннулировать (с указанием причины)">✕</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>

            @if($invoices->hasPages())
                <div class="px-4 py-3 border-t border-border-subtle">{{ $invoices->links() }}</div>
            @endif
        @endif
    </div>
</div>
