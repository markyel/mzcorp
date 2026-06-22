{{-- Редактор КП (наш Quotation клиенту).
     Рендерится внутри таба «КП» в Detail.blade.php, поверх блока
     входящих OutboundQuote-snapshot'ов. --}}
@php
    $q = $this->activeQuotation;
    $versions = $this->versions;
    $unmatched = $this->unmatchedItems;
    $canEdit = $this->canEdit;
    $editable = $q && $q->status->isEditable() && $canEdit;
    $statusToneMap = [
        'draft'     => ['Черновик',  'bg-amber-50 text-amber-800 border-amber-200'],
        'sent'      => ['Отправлено','bg-sky-50 text-sky-800 border-sky-200'],
        'accepted'  => ['Принято',   'bg-emerald-50 text-emerald-800 border-emerald-200'],
        'rejected'  => ['Отклонено', 'bg-red-50 text-red-800 border-red-200'],
        'cancelled' => ['Отменено',  'bg-neutral-100 text-fg-3 border-border-subtle'],
    ];
@endphp

<div class="ds-card mb-4">
    <div class="ds-card-header">
        <h3>Наше КП клиенту</h3>
        <span class="flex-1"></span>
        @if($q)
            <span class="font-mono text-[12px] text-fg-2">{{ $q->internal_code }} · v{{ $q->version }}</span>
            @php [$lbl, $cls] = $statusToneMap[$q->status->value] ?? ['?', 'bg-neutral-100 text-fg-3']; @endphp
            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[11.5px] border {{ $cls }}">{{ $lbl }}</span>
        @endif
    </div>

    <div class="ds-card-body">

        {{-- ───────── Версии (history strip) ───────── --}}
        @if($versions->count() > 1)
            <div class="mb-3 flex items-center gap-1 flex-wrap text-[11.5px]">
                <span class="text-fg-3 uppercase tracking-wider mr-1">Версии:</span>
                @foreach($versions as $v)
                    @php [$vlbl, $vcls] = $statusToneMap[$v->status->value] ?? ['?', 'bg-neutral-100 text-fg-3']; @endphp
                    <button type="button" wire:click="switchToVersion({{ $v->id }})"
                            class="inline-flex items-center px-2 py-0.5 rounded border {{ $vcls }} {{ $q?->id === $v->id ? 'ring-2 ring-sky-400' : 'opacity-80 hover:opacity-100' }}"
                            title="{{ $v->internal_code }} · {{ $vlbl }} · {{ number_format((float)$v->total, 0, '.', ' ') }} ₽">
                        v{{ $v->version }}
                    </button>
                @endforeach
            </div>
        @endif

        {{-- ───────── Пусто (нет ни одной версии) ───────── --}}
        @if(! $q)
            <div class="text-center py-8">
                @if($this->matchedItems->isEmpty())
                    <div class="text-fg-2 mb-2">Сначала сматчите позиции заявки с каталогом.</div>
                    <button type="button" wire:click="$parent.setTab('items')" class="btn btn-sm">→ Перейти к позициям</button>
                @else
                    <div class="text-fg-2 mb-3">КП по этой заявке ещё не создавалось.</div>
                    <button type="button" wire:click="createDraft" class="btn btn-primary"
                            @if(! $canEdit) disabled @endif
                            wire:loading.attr="disabled" wire:target="createDraft">
                        ＋ Создать черновик КП ({{ $this->matchedItems->count() }} {{ $this->matchedItems->count() === 1 ? 'позиция' : 'позиций' }})
                    </button>
                @endif
            </div>
        @else

            {{-- ───────── Warning по несматченным позициям ───────── --}}
            @if($unmatched->isNotEmpty())
                <div class="mb-3 px-3 py-2 rounded bg-amber-50 border border-amber-200 text-[12px] text-amber-800">
                    ⚠ <b>{{ $unmatched->count() }}</b> {{ $unmatched->count() === 1 ? 'позиция заявки не сматчена' : 'позиций заявки не сматчены' }} с каталогом — не попадут в КП.
                    <button type="button" wire:click="$parent.setTab('items')" class="ml-2 underline text-amber-900">→ К позициям</button>
                </div>
            @endif

            {{-- ───────── Шапка КП: реквизиты заказчика + ответственный + сроки ───────── --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 text-[12.5px]">
                <div>
                    <div class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Заказчик</div>

                    {{-- Подставить получателя из известной организации (у клиента
                         может быть несколько) + её назначенная скидка. --}}
                    @if($editable)
                        @php $clientOrgs = $this->clientOrganizations; @endphp
                        @if($clientOrgs->isNotEmpty())
                            <div class="flex items-center gap-1.5 flex-wrap mb-1.5">
                                <span class="text-[11px] text-fg-3">Из организации:</span>
                                @foreach($clientOrgs as $o)
                                    @php $sel = ($o->inn && $q->recipient_inn === $o->inn) || (trim((string)$q->recipient_name) !== '' && $q->recipient_name === $o->name); @endphp
                                    <button type="button" wire:click="applyOrganization({{ $o->id }})" wire:key="qorg-{{ $o->id }}"
                                            class="px-2 py-0.5 rounded border text-[11.5px] {{ $sel ? 'border-sky-500 bg-sky-50 text-sky-800 font-medium' : 'border-border bg-surface text-fg-2 hover:bg-surface-2' }}">
                                        {{ \Illuminate\Support\Str::limit($o->name, 30) }}@if($o->discount_percent > 0) <span class="text-emerald-700">·{{ rtrim(rtrim(number_format($o->discount_percent,2,'.',''),'0'),'.') }}%</span>@endif
                                    </button>
                                @endforeach
                            </div>
                        @endif
                        <input type="text" wire:model.live.debounce.300ms="organizationSearch"
                               placeholder="Найти другую организацию (название / ИНН)…"
                               class="w-full text-[11.5px] text-fg-2 px-2 py-1 border border-border rounded mb-1 bg-surface">
                        @if($this->searchedOrganizations->isNotEmpty())
                            <div class="border border-border rounded-md divide-y divide-border-subtle mb-1.5 max-h-[180px] overflow-y-auto">
                                @foreach($this->searchedOrganizations as $o)
                                    <button type="button" wire:click="applyOrganization({{ $o->id }})" wire:key="qorgs-{{ $o->id }}"
                                            class="w-full text-left px-2 py-1 hover:bg-hover text-[12px]">
                                        {{ $o->name }}@if($o->inn) <span class="mono text-fg-4">· {{ $o->inn }}</span>@endif@if($o->discount_percent > 0) <span class="text-emerald-700">· {{ rtrim(rtrim(number_format($o->discount_percent,2,'.',''),'0'),'.') }}%</span>@endif
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    @endif

                    <input type="text" placeholder="Наименование / ФИО"
                           value="{{ $q->recipient_name }}"
                           @if(!$editable) disabled @endif
                           wire:blur="updateQuotationField('recipient_name', $event.target.value)"
                           class="w-full text-fg-1 px-2 py-1.5 border border-border rounded mb-1">
                    <div class="flex gap-1">
                        <input type="text" placeholder="ИНН"
                               value="{{ $q->recipient_inn }}"
                               @if(!$editable) disabled @endif
                               wire:blur="updateQuotationField('recipient_inn', $event.target.value)"
                               class="w-32 mono text-fg-2 px-2 py-1.5 border border-border rounded">
                        <input type="text" placeholder="Адрес"
                               value="{{ $q->recipient_address }}"
                               @if(!$editable) disabled @endif
                               wire:blur="updateQuotationField('recipient_address', $event.target.value)"
                               class="flex-1 text-fg-2 px-2 py-1.5 border border-border rounded">
                    </div>
                </div>
                <div>
                    <div class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Ответственный</div>
                    <div class="px-2 py-1.5 text-fg-1">
                        {{ $q->responsibleUser?->name ?: '—' }}
                        @if($q->responsibleUser?->phone)
                            · <span class="mono text-fg-2">{{ $q->responsibleUser->phone }}</span>
                            @if($q->responsibleUser->phone_extension)
                                <span class="text-fg-3">доб. {{ $q->responsibleUser->phone_extension }}</span>
                            @endif
                        @endif
                        @if($q->responsibleUser?->email)
                            · <span class="mono text-fg-3 text-[11.5px]">{{ $q->responsibleUser->email }}</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-[11.5px] text-fg-3">Срок действия КП:</span>
                        <input type="number" min="1" max="365"
                               value="{{ $q->valid_days }}"
                               @if(!$editable) disabled @endif
                               wire:blur="updateQuotationField('valid_days', $event.target.value)"
                               class="w-16 mono text-right px-2 py-1 border border-border rounded">
                        <span class="text-[11.5px] text-fg-3">дн</span>
                        @if($q->valid_until)
                            <span class="text-[11.5px] text-fg-2 ml-2">→ до {{ $q->valid_until->format('d.m.Y') }}</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ───────── Бар «общая скидка» с пресетами ─────────
                 Доступные варианты по требованию заказчика:
                   — (без скидки) / 5 / 10 / 15 / 17 / 20 %.
                 Manual-input оставлен для нестандартных значений. --}}
            <div class="mb-3 flex items-center gap-2 text-[12px] flex-wrap">
                <span class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold">Общая скидка:</span>
                @foreach([0, 5, 10, 15, 17, 20] as $preset)
                    <button type="button" wire:click="updateQuotationField('discount_percent', {{ $preset }})"
                            class="px-2 py-1 rounded border text-[12px] {{ (float)$q->discount_percent === (float)$preset ? 'border-sky-500 bg-sky-50 text-sky-800 font-semibold' : 'border-border bg-surface text-fg-2 hover:bg-surface-2' }}"
                            @if(!$editable) disabled @endif>
                        {{ $preset === 0 ? '— без скидки' : $preset . '%' }}
                    </button>
                @endforeach
                <span class="text-fg-3 mx-1">или</span>
                <input type="number" min="0" max="100" step="0.01"
                       value="{{ $q->discount_percent }}"
                       @if(!$editable) disabled @endif
                       wire:blur="updateQuotationField('discount_percent', $event.target.value)"
                       class="w-20 mono text-right px-2 py-1 border border-border rounded">
                <span class="text-fg-3">%</span>
                <span class="flex-1"></span>
                <span class="text-[11.5px] text-fg-3">defence: цена ≥ price_min из каталога</span>
            </div>

            {{-- ───────── Таблица позиций ───────── --}}
            <div class="overflow-x-auto border border-border rounded">
                <table class="w-full text-[12.5px]">
                    <thead class="bg-surface-2 text-fg-3 text-[10.5px] uppercase tracking-wider">
                        <tr>
                            <th class="text-left px-2 py-1.5 w-8">№</th>
                            <th class="text-left px-2 py-1.5">Наименование / SKU</th>
                            <th class="text-right px-2 py-1.5 w-24">Кол-во</th>
                            <th class="text-right px-2 py-1.5 w-32">Цена</th>
                            <th class="text-right px-2 py-1.5 w-20">Скидка %</th>
                            <th class="text-right px-2 py-1.5 w-32">Со скидкой</th>
                            <th class="text-right px-2 py-1.5 w-32">Сумма</th>
                            <th class="text-left px-2 py-1.5 w-40">Срок</th>
                            <th class="px-2 py-1.5 w-8"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($q->items as $item)
                            <tr class="border-t border-border-subtle">
                                <td class="px-2 py-1.5 mono text-fg-3 align-top">{{ $item->position }}</td>
                                <td class="px-2 py-1.5 align-top">
                                    <div class="text-fg-1">{{ $item->snapshot_name }}</div>
                                    <div class="text-[10.5px] text-fg-3 mono flex items-center gap-2 flex-wrap mt-0.5">
                                        @if($item->snapshot_sku) <span>{{ $item->snapshot_sku }}</span> @endif
                                        @if($item->snapshot_brand) <span class="text-fg-2">{{ $item->snapshot_brand }}</span> @endif
                                        @if($item->snapshot_brand_article) <span>{{ $item->snapshot_brand_article }}</span> @endif
                                        @if(!$item->catalog_in_stock) <span class="text-amber-700">нет на складе</span> @endif
                                        @php $iqp = $item->catalog_item_id ? $this->iqotByCatalogId->get($item->catalog_item_id) : null; @endphp
                                        @if($iqp)
                                            <a href="{{ route('iqot.index') }}" wire:navigate
                                               title="Анализ цен конкурентов IQOT — открыть раздел"
                                               class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-[10px] font-semibold normal-case hover:bg-emerald-100">
                                                IQOT{{ $iqp->report_min_price !== null ? ': ' . number_format((float) $iqp->report_min_price, 0, ',', ' ') . ' ₽' : '' }}@if($iqp->report_offers_count) · {{ $iqp->report_offers_count }} офф.@endif
                                            </a>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-2 py-1.5 align-top text-right">
                                    <input type="number" min="0.001" step="0.001"
                                           value="{{ rtrim(rtrim((string)$item->qty, '0'), '.') }}"
                                           @if(!$editable) disabled @endif
                                           wire:blur="updateItemField({{ $item->id }}, 'qty', $event.target.value)"
                                           class="w-20 mono text-right px-2 py-1 border border-border rounded">
                                    <span class="text-[11px] text-fg-3 ml-0.5">{{ $item->unit }}</span>
                                </td>
                                <td class="px-2 py-1.5 mono text-right text-fg-2 align-top">
                                    {{ number_format((float)$item->catalog_unit_price, 2, '.', ' ') }}
                                    @if($item->catalog_price_min !== null)
                                        <div class="text-[10px] text-fg-3">min {{ number_format((float)$item->catalog_price_min, 2, '.', ' ') }}</div>
                                    @endif
                                </td>
                                <td class="px-2 py-1.5 align-top text-right">
                                    <input type="number" min="0" max="100" step="0.01"
                                           placeholder="—"
                                           value="{{ $item->discount_percent !== null ? rtrim(rtrim((string)$item->discount_percent, '0'), '.') : '' }}"
                                           @if(!$editable) disabled @endif
                                           wire:blur="updateItemField({{ $item->id }}, 'discount_percent', $event.target.value)"
                                           class="w-16 mono text-right px-1.5 py-1 border border-border rounded">
                                </td>
                                <td class="px-2 py-1.5 mono text-right text-fg-1 align-top">
                                    {{ number_format((float)$item->final_unit_price, 2, '.', ' ') }}
                                </td>
                                <td class="px-2 py-1.5 mono text-right text-fg-1 font-semibold align-top">
                                    {{ number_format((float)$item->line_total, 2, '.', ' ') }}
                                </td>
                                <td class="px-2 py-1.5 align-top">
                                    <input type="text" placeholder="напр. со склада / 2 нед"
                                           value="{{ $item->delivery_text }}"
                                           @if(!$editable) disabled @endif
                                           wire:blur="updateItemField({{ $item->id }}, 'delivery_text', $event.target.value)"
                                           class="w-full text-fg-2 px-2 py-1 border border-border rounded">
                                </td>
                                <td class="px-2 py-1.5 text-center align-top">
                                    @if($editable)
                                        <button type="button" wire:click="removeItem({{ $item->id }})"
                                                wire:confirm="Удалить позицию из КП?"
                                                class="text-fg-3 hover:text-red-600" title="Удалить из КП">✕</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center py-4 text-fg-3">Нет позиций в КП</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- ───────── Добавить позицию из заявки (если есть matched но не в КП) ───────── --}}
            @php
                $inQuotation = $q->items->pluck('request_item_id')->filter()->all();
                $candidates = $this->matchedItems->reject(fn ($it) => in_array($it->id, $inQuotation, true));
            @endphp
            @if($candidates->isNotEmpty() && $editable)
                <div class="mt-3 px-3 py-2 rounded bg-sky-50 border border-sky-200 text-[12px]">
                    <div class="text-sky-800 mb-1">＋ Добавить в КП ещё позицию заявки:</div>
                    <div class="flex items-center gap-1 flex-wrap">
                        @foreach($candidates as $it)
                            <button type="button" wire:click="addItemFromRequest({{ $it->id }})"
                                    class="inline-flex items-center px-2 py-0.5 rounded bg-white border border-sky-300 text-sky-800 hover:bg-sky-100 text-[11.5px]">
                                ＋ #{{ $it->position }} {{ \Illuminate\Support\Str::limit($it->parsed_name, 40) }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ───────── Итоги ───────── --}}
            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="text-[12px] text-fg-3">
                    <textarea placeholder="Примечание к КП (опционально)" rows="2"
                              @if(!$editable) disabled @endif
                              wire:blur="updateQuotationField('notes', $event.target.value)"
                              class="w-full px-2 py-1.5 border border-border rounded text-fg-2">{{ $q->notes }}</textarea>
                </div>
                <div class="border border-border rounded p-3 bg-surface-2">
                    <div class="space-y-1 text-[12.5px]">
                        <div class="flex justify-between"><span class="text-fg-3">Итого:</span> <span class="mono">{{ number_format((float)$q->subtotal, 2, '.', ' ') }} ₽</span></div>
                        @if((float)$q->discount_amount > 0)
                            <div class="flex justify-between text-amber-700"><span>Скидка {{ rtrim(rtrim((string)$q->discount_percent, '0'), '.') }}%:</span> <span class="mono">−{{ number_format((float)$q->discount_amount, 2, '.', ' ') }} ₽</span></div>
                        @endif
                        <div class="flex justify-between font-semibold text-fg-1"><span>Итого со скидкой:</span> <span class="mono">{{ number_format((float)$q->total, 2, '.', ' ') }} ₽</span></div>
                        <div class="flex justify-between text-fg-3 text-[11.5px]"><span>в т. ч. НДС {{ rtrim(rtrim((string)$q->vat_rate, '0'), '.') }}%:</span> <span class="mono">{{ number_format((float)$q->vat_amount, 2, '.', ' ') }} ₽</span></div>
                    </div>
                </div>
            </div>

            {{-- ───────── Действия ───────── --}}
            <div class="mt-4 flex items-center gap-2 pt-3 border-t border-border-subtle">
                @if($editable)
                    <span class="text-[11.5px] text-fg-3 mr-2">
                        Правки сохраняются автоматически при выходе из поля.
                    </span>
                    <button type="button" wire:click="refreshPrices"
                            wire:loading.attr="disabled" wire:target="refreshPrices"
                            class="btn btn-sm" title="Пере-snapshot catalog в текущий draft">
                        ↻ Обновить цены из каталога
                    </button>
                    <button type="button" wire:click="createNextVersion"
                            wire:confirm="Создать новый вариант v{{ $q->version + 1 }} на основе текущего? Текущий v{{ $q->version }} будет заморожен (доступен только для просмотра)."
                            class="btn btn-sm" title="Клонировать текущий КП в v+1 для альтернативного варианта">
                        📋 Создать новый вариант (v{{ $q->version + 1 }})
                    </button>
                    <span class="flex-1"></span>
                    <button type="button" wire:click="cancelDraft"
                            wire:confirm="Отменить черновик {{ $q->internal_code }}?"
                            class="btn btn-sm text-red-700">Отменить черновик</button>
                    <a href="{{ route('quotations.preview', $q) }}" target="_blank" rel="noopener"
                       class="btn btn-sm">👁 Превью PDF</a>
                    <a href="{{ route('quotations.download', $q) }}"
                       class="btn btn-sm" title="Скачать PDF на диск">⤓ PDF</a>
                    <button type="button"
                            wire:click="sendQuotation({{ $q->id }})"
                            wire:loading.attr="disabled"
                            wire:target="sendQuotation"
                            wire:confirm="Подготовить КП {{ $q->internal_code }} v{{ $q->version }} к отправке клиенту? Откроется черновик с прикреплённым PDF — вы сможете проверить и отправить."
                            class="btn btn-primary btn-sm"
                            title="Сгенерировать PDF, прикрепить к письму и открыть Compose для проверки и отправки">
                        📨 Отправить КП клиенту
                    </button>
                @else
                    <div class="text-[11.5px] text-fg-3">Просмотр read-only.</div>
                    <span class="flex-1"></span>
                    <a href="{{ route('quotations.preview', $q) }}" target="_blank" rel="noopener"
                       class="btn btn-sm">👁 PDF</a>
                    <a href="{{ route('quotations.download', $q) }}"
                       class="btn btn-sm">⤓ Скачать</a>
                    <button type="button" wire:click="createDraft"
                            @if(! $canEdit) disabled @endif
                            class="btn btn-sm">＋ Создать новую версию КП</button>
                @endif
            </div>
        @endif
    </div>
</div>
