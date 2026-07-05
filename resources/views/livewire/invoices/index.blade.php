<div class="space-y-4">
    {{-- Header + фильтры --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Счета</h3>
            <span class="text-[12px] text-fg-3 ml-2">Учёт выставленных счетов и оплат</span>
            <span class="flex-1"></span>
            <button type="button" wire:click="exportExcel" wire:loading.attr="disabled" wire:target="exportExcel"
                    class="btn btn-sm mr-3"
                    title="Выгрузить весь отфильтрованный список (все страницы) в Excel">
                <span wire:loading.remove wire:target="exportExcel">📥 Excel</span>
                <span wire:loading wire:target="exportExcel">Формирую…</span>
            </button>
            <button type="button" wire:click="openBulk" class="btn btn-sm mr-3"
                    title="Отметить оплаченными несколько счетов по списку номеров">✓ Массовая оплата</button>
            @if($this->canSeeAll())
                <button type="button" wire:click="openImport" class="btn btn-sm mr-3"
                        title="Загрузить выгрузку оплат из 1С: отметить оплаченные счета и зафиксировать оплаты по неизвестным">⬇ Оплаты из 1С</button>
                <a href="{{ route('invoices.external') }}" wire:navigate class="btn btn-sm mr-3"
                   title="Оплаченные по банку счета, которых нет в CRM (из импорта 1С)">💳 Внешние оплаты</a>
                <a href="{{ route('invoices.unlinked') }}" wire:navigate class="btn btn-sm mr-3"
                   title="Исходящие счета, не нашедшие заявку — привязать вручную">⚠ Непривязанные</a>
            @endif
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
                        'partially_paid' => 'Частично',
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
            @php $customRange = trim($dateFrom) !== '' || trim($dateTo) !== ''; @endphp
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
                @php $periods = ['today' => 'Сегодня', '7d' => '7 дн.', '30d' => '30 дн.', '90d' => '90 дн.', 'all' => 'Всё']; @endphp
                @foreach($periods as $k => $label)
                    @php $on = ! $customRange && $period === $k; @endphp
                    <button type="button" wire:click="setPeriod('{{ $k }}')"
                            class="h-[26px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            {{-- Произвольный диапазон по дате выставления (приоритетнее пресета) --}}
            <div class="inline-flex items-center gap-1 {{ $customRange ? 'text-fg-1' : 'text-fg-3' }}">
                <input type="date" wire:model.live="dateFrom"
                       class="h-[26px] px-1.5 border {{ $customRange ? 'border-[var(--accent)]' : 'border-border' }} rounded-md bg-surface text-[12px] outline-none focus:border-[var(--sky-500)]"
                       title="Счета, выписанные с даты (включительно)">
                <span class="text-fg-4">—</span>
                <input type="date" wire:model.live="dateTo"
                       class="h-[26px] px-1.5 border {{ $customRange ? 'border-[var(--accent)]' : 'border-border' }} rounded-md bg-surface text-[12px] outline-none focus:border-[var(--sky-500)]"
                       title="Счета, выписанные по дату (включительно)">
                @if($customRange)
                    <button type="button" wire:click="setPeriod('30d')" class="text-fg-4 hover:text-fg-1 px-1" title="Сбросить диапазон">✕</button>
                @endif
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

        {{-- Итоги за период (фильтр статуса не применяется — тут разбивка по статусам).
             Быстрая сверка: выставлено vs оплачено/частично vs ждёт денег. --}}
        @php
            $tot = $this->periodTotals;
            $fmt = fn (float $v) => number_format($v, 0, '.', ' ');
            $paidB = $tot['by']['paid'] ?? null;
            $partB = $tot['by']['partially_paid'] ?? null;
            $pendB = $tot['by']['pending'] ?? null;
            $expB  = $tot['by']['expired'] ?? null;
            $cancB = $tot['by']['cancelled'] ?? null;
            $waitCount = ($pendB['count'] ?? 0) + ($expB['count'] ?? 0);
            $waitSum = ($pendB['sum'] ?? 0) + ($expB['sum'] ?? 0);
        @endphp
        <div class="px-4 pb-3 flex items-center gap-x-4 gap-y-1 flex-wrap text-[12px] border-t border-border-subtle pt-2.5">
            <span class="text-fg-2">За период выставлено:
                <b class="mono text-fg-1">{{ $tot['total']['count'] }}</b> счетов на
                <b class="mono text-fg-1">{{ $fmt($tot['total']['sum']) }} ₽</b>
            </span>
            <span class="text-emerald-700">✓ оплачено {{ $paidB['count'] ?? 0 }} · {{ $fmt($paidB['sum'] ?? 0) }} ₽@if(($paidB['received'] ?? 0) > 0) <span class="text-fg-4" title="Фактически поступившая сумма по данным импорта оплат 1С (заполнена не у всех счетов — только у прошедших через импорт)">(по 1С поступило {{ $fmt($paidB['received']) }})</span>@endif</span>
            @if($partB)
                <span class="text-sky-700">◐ частично {{ $partB['count'] }} · {{ $fmt($partB['sum']) }} ₽ <span class="text-fg-4">(поступило {{ $fmt($partB['received']) }})</span></span>
            @endif
            <span class="{{ $waitSum > 0 ? 'text-amber-700' : 'text-fg-4' }}">⏳ ждут оплаты {{ $waitCount }} · {{ $fmt($waitSum) }} ₽
                @if(($expB['count'] ?? 0) > 0)<span class="text-fg-4">(из них истекло {{ $expB['count'] }} · {{ $fmt($expB['sum']) }})</span>@endif
            </span>
            @if($cancB)
                <span class="text-fg-4">✕ аннулировано {{ $cancB['count'] }} · {{ $fmt($cancB['sum']) }} ₽</span>
            @endif
            @if(($tot['paid_in_period']['count'] ?? 0) > 0)
                <span class="text-fg-2" title="Счета этого периода, оплаченные (в т.ч. частично) внутри этого же периода — по дате оплаты. Сопоставимо с выгрузкой оплат 1С, отфильтрованной по дате счёта: количество и поступившие суммы.">
                    💰 из них оплачено в этом же периоде: <b class="mono">{{ $tot['paid_in_period']['count'] }}</b> · <b class="mono">{{ $fmt($tot['paid_in_period']['received']) }} ₽</b>
                </span>
            @endif
            @if(($tot['dups']['numbers'] ?? 0) > 0)
                <span class="text-red-700" title="Один и тот же номер счёта числится на нескольких заявках — сумма периода завышена. Найдите дубли поиском по номеру и аннулируйте лишний счёт.">
                    ⚠ дубли номеров: {{ $tot['dups']['numbers'] }} (+{{ $fmt($tot['dups']['extra_sum']) }} ₽ задвоено)
                </span>
            @endif
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
                            $isCancelled = $inv->status?->value === 'cancelled';
                            $canMarkPaid = in_array($inv->status?->value, ['pending', 'expired', 'cancelled', 'partially_paid'], true);
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
                                @if($canMarkPaid)
                                    <button type="button"
                                            wire:click="markPaid({{ $inv->id }})"
                                            wire:confirm="{{ $isCancelled
                                                ? 'Счёт №' . $inv->invoice_number . ' был аннулирован. Отметить его оплаченным? Заявка перейдёт в статус «Оплачено».'
                                                : 'Пометить счёт №' . $inv->invoice_number . ' как оплаченный? Заявка перейдёт в статус «Оплачено».' }}"
                                            class="btn btn-sm btn-primary"
                                            title="{{ $isCancelled ? 'Отметить оплаченным (реанимация аннулированного счёта)' : 'Отметить оплаченным' }}">✓ Оплачен</button>
                                @endif
                                @if($isPending)
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

    {{-- ============== Импорт оплат из 1С (xlsx) ============== --}}
    @if($importOpen)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:mousedown.self="closeImport">
            <div class="ds-card p-0 w-full max-w-[860px] max-h-[85vh] flex flex-col overflow-hidden" wire:click.stop>
                <div class="px-5 pt-5 pb-3 border-b border-border-subtle shrink-0">
                    <h3 class="text-[15px] font-semibold text-fg-1 mb-1">Импорт оплат из 1С</h3>
                    <div class="text-[12px] text-fg-3">
                        Выгрузка оплат (xlsx, колонки «Номер счета» / «Дата оплаты» / «Оп%» / «Сумма &lt;Оплата&gt;»).
                        Найденные счета будут отмечены оплаченными (частичные — «частично оплачен», заявка закроется успехом),
                        неизвестные — зафиксированы как внешние оплаты. Сначала предпросмотр, ничего не применяется без подтверждения.
                    </div>
                </div>

                @if(! $importPreviewed)
                    <form wire:submit="previewImport" class="flex flex-col min-h-0 flex-1">
                        <div class="px-5 py-4 overflow-y-auto flex-1 min-h-0 space-y-3">
                            <input type="file" wire:model="importFile" accept=".xlsx,.xls"
                                   class="block w-full text-[13px] text-fg-2 file:mr-3 file:px-3 file:h-[30px] file:border file:border-border file:rounded-md file:bg-surface file:text-fg-1 file:text-[12.5px] file:cursor-pointer" />
                            @error('importFile')<div class="text-[12px] text-red-600">{{ $message }}</div>@enderror
                            <div wire:loading wire:target="importFile" class="text-[12px] text-fg-3">Загружаю файл…</div>
                        </div>
                        <div class="px-5 py-3 border-t border-border-subtle flex items-center gap-2 shrink-0">
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="previewImport,importFile"
                                    @disabled($importFile === null)>
                                <span wire:loading.remove wire:target="previewImport">Проверить файл →</span>
                                <span wire:loading wire:target="previewImport">Сверяю со счетами…</span>
                            </button>
                            <button type="button" wire:click="closeImport" class="btn">Отмена</button>
                        </div>
                    </form>
                @else
                    <div class="flex flex-col min-h-0 flex-1">
                        <div class="px-5 py-3 border-b border-border-subtle shrink-0">
                            <div class="text-[12px] text-fg-3 mb-1.5 mono">{{ $importFileName }}</div>
                            <div class="flex flex-wrap gap-2">
                                @foreach($importSummary as $action => $s)
                                    @php [$label, $chip] = $this->importActionLabel($action); @endphp
                                    <span class="chip {{ $chip }} text-[11px]">{{ $label }}: <b class="mono">{{ $s['count'] }}</b> · {{ number_format($s['sum'], 0, '.', ' ') }} ₽</span>
                                @endforeach
                            </div>
                        </div>
                        <div class="px-5 py-2 overflow-y-auto flex-1 min-h-0">
                            <table class="w-full text-[12px]">
                                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border sticky top-0 bg-surface">
                                    <tr>
                                        <th class="text-left px-1.5 py-1.5">№ счёта</th>
                                        <th class="text-left px-1.5 py-1.5">Контрагент</th>
                                        <th class="text-right px-1.5 py-1.5">Оплата, ₽</th>
                                        <th class="text-right px-1.5 py-1.5">Оп%</th>
                                        <th class="text-left px-1.5 py-1.5">Дата оплаты</th>
                                        <th class="text-left px-1.5 py-1.5">Заявка</th>
                                        <th class="text-left px-1.5 py-1.5">Результат</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($importPreviewRows as $i => $r)
                                        @php [$label, $chip] = $this->importActionLabel($r['action']); @endphp
                                        <tr class="border-b border-border-subtle" wire:key="imp-{{ $i }}">
                                            <td class="px-1.5 py-1 mono">{{ $r['number'] }}</td>
                                            <td class="px-1.5 py-1 text-fg-2">{{ $r['client'] }}</td>
                                            <td class="px-1.5 py-1 text-right mono">{{ number_format((float) $r['paid_sum'], 2, '.', ' ') }}</td>
                                            <td class="px-1.5 py-1 text-right mono {{ $r['percent'] < 100 ? 'text-amber-700 font-semibold' : 'text-fg-3' }}">{{ $r['percent'] }}</td>
                                            <td class="px-1.5 py-1 mono text-fg-3">{{ $r['paid_date'] }}</td>
                                            <td class="px-1.5 py-1 mono text-sky-700">{{ $r['request_code'] ?? '—' }}</td>
                                            <td class="px-1.5 py-1">
                                                <span class="chip {{ $chip }} text-[10.5px]">{{ $label }}</span>
                                                @if($r['sum_mismatch'])<span class="text-amber-700 text-[10.5px]" title="Сумма оплаты отличается от суммы счёта в CRM">⚠ сумма</span>@endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            @if(count($importPreviewRows) === 250)
                                <div class="text-[11px] text-fg-4 py-2">Показаны первые 250 строк — применение затронет весь файл.</div>
                            @endif
                        </div>
                        <div class="px-5 py-3 border-t border-border-subtle flex items-center gap-2 shrink-0">
                            <button type="button" wire:click="applyImport" wire:loading.attr="disabled" wire:target="applyImport"
                                    class="btn btn-primary"
                                    wire:confirm="Применить импорт? Счета будут отмечены оплаченными, заявки закроются. Действие не отменить.">
                                <span wire:loading.remove wire:target="applyImport">Применить импорт</span>
                                <span wire:loading wire:target="applyImport">Применяю…</span>
                            </button>
                            <button type="button" wire:click="backToImportUpload" class="btn">← Другой файл</button>
                            <button type="button" wire:click="closeImport" class="btn">Отмена</button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ============== Массовая оплата по списку номеров ============== --}}
    @if($bulkOpen)
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: center; justify-content: center; padding: 24px;"
             wire:mousedown.self="closeBulk">
            {{-- Карта: фикс. высота, шапка/подвал не скроллятся, тело прокручивается. --}}
            <div class="ds-card p-0 w-full max-w-[720px] max-h-[85vh] flex flex-col overflow-hidden" wire:click.stop>
                {{-- Шапка --}}
                <div class="px-5 pt-5 pb-3 border-b border-border-subtle shrink-0">
                    <h3 class="text-[15px] font-semibold text-fg-1 mb-1">Массовая оплата счетов</h3>
                    <div class="text-[12px] text-fg-3">
                        Вставьте номера счетов — система найдёт совпадения и отметит выбранные оплаченными
                        (заявки перейдут в статус «Оплачено»). Оплатить можно счета в статусе «Ожидает», «Истёк» и «Аннулирован».
                    </div>
                </div>

                @if(! $bulkPreviewed)
                    {{-- Шаг 1: ввод номеров --}}
                    <form wire:submit="previewBulk" class="flex flex-col min-h-0 flex-1">
                        <div class="px-5 py-3 overflow-y-auto flex-1 min-h-0">
                            <label class="block text-[11.5px] uppercase tracking-wider text-fg-3 font-semibold mb-1.5">
                                Номера счетов
                                <span class="text-fg-4 normal-case font-normal">— по одному в строке (или через запятую)</span>
                            </label>
                            <textarea wire:model="bulkNumbers" rows="8"
                                      placeholder="МЗ-5687&#10;МЗ-5688&#10;МЗ-5690"
                                      class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] mono outline-none focus:border-[var(--sky-500)] resize-y"></textarea>
                        </div>
                        <div class="px-5 py-3 flex items-center gap-2 border-t border-border-subtle shrink-0">
                            <button type="submit" class="btn btn-primary">Найти счета</button>
                            <button type="button" wire:click="closeBulk" class="btn">Отмена</button>
                        </div>
                    </form>
                @else
                    {{-- Шаг 2: превью + подтверждение --}}
                    @php
                        $eligibleCount = collect($bulkFound)->where('eligible', true)->count();
                        $skipCount = count($bulkFound) - $eligibleCount;
                    @endphp
                    <div class="px-5 py-3 overflow-y-auto flex-1 min-h-0 space-y-4">
                        {{-- Сводка --}}
                        <div class="text-[12.5px] text-fg-2">
                            Найдено: <span class="font-semibold">{{ count($bulkFound) }}</span> ·
                            к оплате: <span class="font-semibold text-[var(--emerald-700)]">{{ $eligibleCount }}</span> ·
                            пропуск: <span class="font-semibold text-fg-3">{{ $skipCount }}</span> ·
                            не найдено: <span class="font-semibold text-fg-3">{{ count($bulkNotFound) }}</span>
                        </div>

                        {{-- Найденные счета --}}
                        @if(count($bulkFound) > 0)
                            <div class="border border-border rounded-md divide-y divide-border-subtle max-h-[44vh] overflow-y-auto">
                                @foreach($bulkFound as $row)
                                    <label class="flex items-center gap-2.5 px-3 py-2 text-[12.5px] {{ $row['eligible'] ? 'cursor-pointer hover:bg-[var(--bg-hover)]' : 'opacity-60' }}">
                                        <input type="checkbox" value="{{ $row['id'] }}"
                                               wire:model="bulkSelectedIds"
                                               @disabled(! $row['eligible'])>
                                        <span class="mono text-fg-1 font-medium min-w-[120px]">{{ $row['invoice_number'] }}</span>
                                        <span class="chip {{ $this->statusChipClass($row['status']) }}"><span class="dot"></span>{{ $row['status_label'] }}</span>
                                        <span class="text-fg-3 truncate flex-1">
                                            @if($row['request_code']) {{ $row['request_code'] }} @endif
                                            @if($row['manager']) · {{ $row['manager'] }} @endif
                                            @if($row['amount']) · {{ number_format((float) $row['amount'], 2, ',', ' ') }} ₽ @endif
                                        </span>
                                        @if(! $row['eligible'])
                                            <span class="text-[11px] text-amber-700 whitespace-nowrap">{{ $row['reason'] }}</span>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        @endif

                        {{-- Не найдено --}}
                        @if(count($bulkNotFound) > 0)
                            <div>
                                <div class="text-[11.5px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Не найдено</div>
                                <div class="text-[12px] text-fg-3 mono break-words">{{ implode(', ', $bulkNotFound) }}</div>
                            </div>
                        @endif
                    </div>

                    <div class="px-5 py-3 flex items-center gap-2 border-t border-border-subtle shrink-0">
                        <button type="button"
                                wire:click="confirmBulk"
                                wire:confirm="Отметить выбранные счета ({{ count($bulkSelectedIds) }}) как оплаченные? Заявки перейдут в статус «Оплачено»."
                                class="btn btn-primary"
                                @disabled(empty($bulkSelectedIds))>Отметить оплаченными ({{ count($bulkSelectedIds) }})</button>
                        <button type="button" wire:click="backToBulkInput" class="btn">← Назад</button>
                        <button type="button" wire:click="closeBulk" class="btn">Отмена</button>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
