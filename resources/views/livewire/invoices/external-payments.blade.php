<div class="space-y-4">
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Внешние оплаты (импорт 1С)</h3>
            <span class="text-[12px] text-fg-3 ml-2">оплаченные по банку счета, которых нет в CRM</span>
            <span class="flex-1"></span>
            <a href="{{ route('invoices.index') }}" wire:navigate class="btn btn-sm">← Счета</a>
        </div>
        <div class="px-4 pb-3 flex items-center gap-2 flex-wrap text-[12px]">
            @php $counts = $this->counts; @endphp
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
                @foreach(['unknown' => 'Ждут разбора · '.$counts['unknown'], 'linked' => 'Привязаны · '.$counts['linked'], 'ignored' => 'Неактуальные · '.$counts['ignored']] as $k => $label)
                    @php $on = $tab === $k; @endphp
                    <button type="button" wire:click="setTab('{{ $k }}')"
                            class="h-[26px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <input type="search" wire:model.live.debounce.350ms="search" placeholder="№ счёта / контрагент / менеджер…"
                   class="h-[26px] px-2 border border-border rounded-md bg-surface text-fg-1 text-[12px] outline-none focus:border-[var(--sky-500)] min-w-[240px]" />
        </div>
        <div class="px-4 pb-3 text-[11.5px] text-fg-4">
            Если счёт позже появится в CRM (детектор исходящих / привязка непривязанного) — оплата подтянется автоматически.
            «Привязать» — создаёт счёт в указанной заявке и сразу отмечает оплату (частичную при Оп% &lt; 100).
        </div>
    </div>

    <div class="ds-card">
        <div class="ds-card-body overflow-x-auto p-0">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                    <tr>
                        <th class="text-left px-3 py-2" style="width:90px">№ счёта</th>
                        <th class="text-left px-3 py-2">Контрагент</th>
                        <th class="text-left px-3 py-2" style="width:170px">Менеджер (1С)</th>
                        <th class="text-right px-3 py-2" style="width:110px">Оплачено, ₽</th>
                        <th class="text-right px-3 py-2" style="width:55px">Оп%</th>
                        <th class="text-left px-3 py-2" style="width:95px">Дата оплаты</th>
                        <th class="text-left px-3 py-2" style="width:95px">Дата счёта</th>
                        <th class="text-left px-3 py-2" style="width:220px"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->payments as $p)
                        <tr class="border-b border-border-subtle hover:bg-hover align-top" wire:key="ext-{{ $p->id }}">
                            <td class="px-3 py-2 mono text-fg-1">{{ $p->invoice_number_int ?? $p->invoice_number }}</td>
                            <td class="px-3 py-2 text-fg-2">
                                {{ \Illuminate\Support\Str::limit($p->client_name, 60) ?: '—' }}
                                @if($p->payment_purpose)
                                    <div class="text-[11px] text-fg-4 truncate" style="max-width:420px" title="{{ $p->payment_purpose }}">{{ $p->payment_purpose }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-fg-3">{{ $p->manager_name ?: '—' }}</td>
                            <td class="px-3 py-2 text-right mono">{{ number_format((float) $p->paid_sum, 2, '.', ' ') }}</td>
                            <td class="px-3 py-2 text-right mono {{ (int) $p->paid_percent < 100 ? 'text-amber-700 font-semibold' : 'text-fg-3' }}">{{ $p->paid_percent }}</td>
                            <td class="px-3 py-2 mono text-fg-3">{{ $p->paid_date?->format('d.m.Y') }}</td>
                            <td class="px-3 py-2 mono text-fg-3">{{ $p->invoice_date?->format('d.m.Y') }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                @if($tab === 'unknown')
                                    @if($linkingId === $p->id)
                                        <div class="flex items-center gap-1.5 justify-end">
                                            <input type="text" wire:model="linkCode" wire:keydown.enter="confirmLink"
                                                   placeholder="M-2026-…" class="h-[26px] px-2 border border-border rounded-md bg-surface text-[12px] mono outline-none focus:border-[var(--sky-500)]" style="width:130px" autofocus>
                                            <button type="button" wire:click="confirmLink" class="btn btn-sm btn-primary">OK</button>
                                            <button type="button" wire:click="cancelLink" class="btn btn-sm">✕</button>
                                        </div>
                                        @error('linkCode')<div class="text-[11px] text-red-600 mt-1">{{ $message }}</div>@enderror
                                    @else
                                        <button type="button" wire:click="startLink({{ $p->id }})" class="btn btn-sm btn-primary"
                                                title="Создать счёт в заявке и отметить оплату">🔗 Привязать</button>
                                        <button type="button" wire:click="ignore({{ $p->id }})" class="btn btn-sm"
                                                wire:confirm="Пометить оплату по счёту {{ $p->invoice_number }} как неактуальную для CRM?"
                                                title="Не относится к CRM (LiftWay, прямые продажи и т.п.)">Неактуально</button>
                                    @endif
                                @elseif($tab === 'ignored')
                                    <button type="button" wire:click="restore({{ $p->id }})" class="btn btn-sm">↩ Вернуть</button>
                                @else
                                    @if($p->request)
                                        <a href="{{ route('requests.show', $p->request_id) }}" wire:navigate class="text-sky-700 hover:underline mono text-[12px]">{{ $p->request->internal_code }}</a>
                                    @endif
                                    @if($p->resolvedBy)<div class="text-[10.5px] text-fg-4">{{ $p->resolvedBy->name }} · {{ $p->resolved_at?->format('d.m.Y') }}</div>@endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-10 text-center text-fg-3 text-[13px]">
                            {{ $tab === 'unknown' ? 'Нет внешних оплат, ожидающих разбора. Они появятся после импорта оплат из 1С (раздел «Счета»).' : 'Пусто.' }}
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($this->payments->hasPages())
            <div class="px-4 py-3 border-t border-border-subtle">{{ $this->payments->links() }}</div>
        @endif
    </div>
</div>
