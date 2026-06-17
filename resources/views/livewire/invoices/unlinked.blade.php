<div class="space-y-4">
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Непривязанные счета</h3>
            <span class="text-[12px] text-fg-3 ml-2">Исходящие счета, не нашедшие заявку — привяжите вручную</span>
            <span class="flex-1"></span>
            <a href="{{ route('invoices.index') }}" wire:navigate
               class="text-[12px] text-[var(--sky-700)] hover:underline mr-3">← Реестр счетов</a>
            <span class="text-[11.5px] text-fg-3 mono">{{ count($this->rows) }} шт</span>
        </div>

        <div class="px-4 pb-3 flex items-center gap-2 flex-wrap text-[12px]">
            <span class="text-fg-3">Свежесть (по дате счёта):</span>
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
                @php $periods = [7 => '7 дн.', 30 => '30 дн.', 90 => '90 дн.', 365 => 'Год']; @endphp
                @foreach($periods as $k => $label)
                    @php $on = $period === $k; @endphp
                    <button type="button" wire:click="setPeriod({{ $k }})"
                            class="h-[26px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>

        <div class="px-4 pb-2 text-[11.5px] text-fg-3 leading-snug">
            Сюда попадают счета, для которых не сработала авто-привязка по заголовкам письма
            (родителя треда нет в системе). Старые пересылки отфильтрованы окном свежести.
            После привязки счёт автоматически разбирается и появляется в реестре.
        </div>

        @if(empty($this->rows))
            <div class="px-4 py-10 text-center text-fg-3 text-[13px]">
                Нет непривязанных счетов за выбранный период 🎉
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead>
                        <tr class="text-fg-3 text-[11px] uppercase tracking-wide border-y border-border">
                            <th class="text-left font-medium px-3 py-2">№ счёта</th>
                            <th class="text-left font-medium px-3 py-2">Дата</th>
                            <th class="text-left font-medium px-3 py-2">Отправитель</th>
                            <th class="text-left font-medium px-3 py-2">Клиент</th>
                            <th class="text-left font-medium px-3 py-2">Тема письма</th>
                            <th class="text-right font-medium px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->rows as $row)
                            <tr wire:key="unlinked-{{ $row['att_id'] }}" class="border-b border-border align-top hover:bg-[var(--bg-hover)]">
                                <td class="px-3 py-2">
                                    <span class="mono font-semibold text-fg-1">{{ $row['number'] ?? '—' }}</span>
                                    <div class="text-[10.5px] text-fg-4 truncate max-w-[180px]" title="{{ $row['filename'] }}">{{ $row['filename'] }}</div>
                                </td>
                                <td class="px-3 py-2 mono text-fg-2 whitespace-nowrap">{{ $row['doc_date'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-fg-2 whitespace-nowrap">{{ $row['mailbox'] ?? $row['from_email'] }}</td>
                                <td class="px-3 py-2 text-fg-2">{{ $row['client'] ?? '—' }}</td>
                                <td class="px-3 py-2 text-fg-2">
                                    <span class="truncate inline-block max-w-[280px] align-bottom" title="{{ $row['subject'] }}">{{ $row['subject'] ?: '—' }}</span>
                                    <span class="text-[10.5px] text-fg-4 ml-1">{{ $row['sent_at'] }}</span>
                                </td>
                                <td class="px-3 py-2 text-right whitespace-nowrap">
                                    <button type="button" wire:click="toggleInvoiceText({{ $row['att_id'] }})"
                                            class="btn btn-sm mr-1" title="Показать позиции счёта (текст из PDF)">
                                        {{ $expandedInvoiceAtt === $row['att_id'] ? '▴ Счёт' : '▾ Счёт' }}
                                    </button>
                                    @if($attachingMsgId === $row['msg_id'])
                                        <button type="button" wire:click="cancelAttach" class="btn btn-sm">Отмена</button>
                                    @else
                                        <button type="button" wire:click="startAttach({{ $row['msg_id'] }}, {{ $row['att_id'] }})" class="btn btn-sm btn-primary">Привязать к заявке</button>
                                    @endif
                                </td>
                            </tr>

                            @if($expandedInvoiceAtt === $row['att_id'])
                                <tr wire:key="inv-text-{{ $row['att_id'] }}" class="bg-[var(--bg-surface-2,var(--bg-hover))]">
                                    <td colspan="6" class="px-3 py-3">
                                        <div class="flex items-center gap-3 mb-1">
                                            <span class="text-[11.5px] text-fg-3">Позиции счёта {{ $row['number'] ? '№'.$row['number'] : '' }} (текст из PDF):</span>
                                            <a href="{{ route('attachments.preview', $row['att_id']) }}" target="_blank" rel="noopener"
                                               class="text-[11.5px] text-[var(--sky-700)] hover:underline">📄 Открыть оригинал ↗</a>
                                        </div>
                                        @if($invoiceText)
                                            <pre class="text-[11px] text-fg-2 whitespace-pre-wrap mono bg-surface border border-border rounded-md p-2 max-h-[320px] overflow-y-auto">{{ $invoiceText }}</pre>
                                        @else
                                            <div class="text-[11.5px] text-fg-4">Текст из PDF не извлёкся (вероятно скан) — откройте оригинал по ссылке.</div>
                                        @endif
                                    </td>
                                </tr>
                            @endif

                            @if($attachingMsgId === $row['msg_id'])
                                <tr wire:key="attach-{{ $row['msg_id'] }}" class="bg-[var(--bg-surface-2,var(--bg-hover))]">
                                    <td colspan="6" class="px-3 py-3">
                                        <div class="max-w-[680px] space-y-3">
                                            @php $mgrId = $this->attachingManagerId; @endphp
                                            {{-- 🎯 Совпадение по M-артикулам счёта — сильнейший сигнал --}}
                                            @php $artMatches = $this->articleMatchedRequests; @endphp
                                            @if($artMatches->isNotEmpty())
                                                <div>
                                                    <div class="text-[11.5px] text-fg-3 mb-1">
                                                        🎯 Совпадение по M-артикулам счёта
                                                        @if(!empty($attachingMCodes))<span class="mono text-fg-4">({{ implode(', ', $attachingMCodes) }})</span>@endif:
                                                    </div>
                                                    <div class="border border-[var(--emerald-300,#6ee7b7)] rounded-md divide-y divide-[var(--border)] max-h-[240px] overflow-y-auto">
                                                        @foreach($artMatches as $am)
                                                            @include('livewire.invoices._candidate-row', ['cand' => $am['req'], 'msgId' => $row['msg_id'], 'number' => $row['number'], 'prefix' => 'art', 'badge' => count($am['skus']).' арт.: '.implode(', ', $am['skus']), 'ownManager' => ($mgrId && (int) $am['req']->assigned_user_id === $mgrId)])
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif

                                            {{-- Открытые заявки этого заказчика — главные кандидаты --}}
                                            <div>
                                                <div class="text-[11.5px] text-fg-3 mb-1">
                                                    Открытые заявки заказчика
                                                    <span class="mono text-fg-2">{{ $this->attachingClientEmail ?? '—' }}</span>:
                                                </div>
                                                @php $clientReqs = $this->clientOpenRequests; @endphp
                                                @if($clientReqs->isEmpty())
                                                    <div class="text-[11.5px] text-fg-4">Открытых заявок этого заказчика нет — найдите вручную ниже.</div>
                                                @else
                                                    <div class="border border-border rounded-md divide-y divide-[var(--border)] max-h-[240px] overflow-y-auto">
                                                        @foreach($clientReqs as $cand)
                                                            @include('livewire.invoices._candidate-row', ['cand' => $cand, 'msgId' => $row['msg_id'], 'number' => $row['number'], 'prefix' => 'cl', 'ownManager' => ($mgrId && (int) $cand->assigned_user_id === $mgrId)])
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>

                                            {{-- Ручной поиск любой заявки (как раньше) --}}
                                            <div>
                                                <label class="block text-[11.5px] text-fg-3 mb-1">Или найти другую заявку (код / клиент / тема):</label>
                                                <input type="text" wire:model.live.debounce.300ms="requestSearch"
                                                       placeholder="M-2026-… / email клиента / слово из темы"
                                                       class="w-full h-[30px] px-2 border border-border rounded-md bg-surface text-fg-1 text-[12.5px] outline-none focus:border-[var(--sky-500)]">
                                                @if(mb_strlen(trim($requestSearch)) >= 2)
                                                    <div class="mt-2 border border-border rounded-md divide-y divide-[var(--border)] max-h-[240px] overflow-y-auto">
                                                        @forelse($this->requestCandidates as $cand)
                                                            @include('livewire.invoices._candidate-row', ['cand' => $cand, 'msgId' => $row['msg_id'], 'number' => $row['number'], 'prefix' => 'mn', 'ownManager' => ($mgrId && (int) $cand->assigned_user_id === $mgrId)])
                                                        @empty
                                                            <div class="px-2.5 py-3 text-[12px] text-fg-3">Ничего не найдено по «{{ $requestSearch }}».</div>
                                                        @endforelse
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
