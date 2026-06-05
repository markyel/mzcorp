<div class="space-y-4">
    @php
        $stats = $this->stats;
        $statusChips = [
            '' => 'Активные',
            'pending' => 'В очереди',
            'analyzing' => 'Анализируется',
            'completed' => 'Готов отчёт',
            'failed' => 'Ошибка',
            'excluded' => 'Исключённые',
        ];
        $statusClass = [
            'pending' => 'text-fg-3',
            'queued' => 'text-fg-3',
            'analyzing' => 'text-amber-700',
            'completed' => 'text-emerald-700',
            'failed' => 'text-red-700',
            'excluded' => 'text-fg-4',
        ];
        // Прогресс сбора со стороны IQOT (items[].status), пока идёт «Анализируется».
        $iqotItemLabel = [
            'dispatched' => 'разослано поставщикам',
            'awaiting_suppliers' => 'ждём поставщиков',
            'with_offers' => 'есть офферы',
            'with_offers_minimum' => 'офферы собраны',
            'collecting' => 'сбор офферов',
            'completed' => 'готово',
        ];
    @endphp

    {{-- Header --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>IQOT · анализ цен конкурентов</h3>
            @if($stats['enabled'] && $stats['configured'])
                <span class="chip chip-ok ml-2"><span class="dot"></span>включено</span>
            @elseif(! $stats['configured'])
                <span class="chip chip-warn ml-2" title="Заполните API-ключ в Настройках">
                    <span class="dot"></span>ключ не задан
                </span>
            @else
                <span class="chip chip-paused ml-2"><span class="dot"></span>выключено</span>
            @endif
        </div>
        <div class="px-4 pb-3 flex items-center gap-x-5 gap-y-1.5 flex-wrap text-[12px]">
            <span class="text-fg-3">Дневной лимит: <span class="text-fg-1 mono font-semibold">{{ $stats['daily_limit'] }}</span></span>
            <span class="text-fg-3">Отправлено сегодня: <span class="text-fg-1 mono font-semibold">{{ $stats['used_today'] }}</span></span>
            <span class="text-fg-3">Свежих отчётов: <span class="text-emerald-700 mono font-semibold">{{ $stats['fresh'] }}</span></span>
            <span class="text-fg-3">В пуле: <span class="text-fg-1 mono font-semibold">{{ $stats['total'] }}</span></span>
            <span class="flex-1"></span>
            <button type="button" wire:click="refreshPool" class="btn btn-sm" wire:loading.attr="disabled">Обновить пул</button>
            <button type="button" wire:click="runPoll" class="btn btn-sm" wire:loading.attr="disabled">Обновить статусы</button>
            <button type="button" wire:click="runDispatch" class="btn btn-sm btn-primary" wire:loading.attr="disabled"
                    @if(! $stats['enabled'] || ! $stats['configured']) disabled title="Включите IQOT и задайте ключ в Настройках" @endif>
                Запустить анализ
            </button>
        </div>
        @if(session('iqot-flash'))
            <div class="mx-4 mb-3 px-3 py-2 rounded-md bg-sky-50 border border-sky-200 text-[12.5px] text-sky-900">
                {{ session('iqot-flash') }}
            </div>
        @endif
    </div>

    {{-- Filters --}}
    <div class="ds-card">
        <div class="px-4 py-3 flex items-center gap-2 gap-y-2 flex-wrap text-[12px]">
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
                @foreach($statusChips as $k => $label)
                    @php $on = $statusFilter === $k; @endphp
                    <button type="button" wire:click="$set('statusFilter', '{{ $k }}')"
                            class="h-[26px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
                @foreach(['' => 'Все источники', 'auto' => 'Из КП', 'manual' => 'Ручные'] as $k => $label)
                    @php $on = $sourceFilter === $k; @endphp
                    <button type="button" wire:click="$set('sourceFilter', '{{ $k }}')"
                            class="h-[26px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <span class="flex-1"></span>
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Поиск: название / M-SKU / OEM"
                   class="h-[30px] px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500 w-[280px]">
        </div>
    </div>

    {{-- Table --}}
    <div class="ds-card">
        <div class="ds-card-body overflow-x-auto">
            @if($this->positions->isEmpty())
                <div class="text-center text-fg-3 py-8 text-[13px]">
                    Пул пуст. Нажмите «Обновить пул», чтобы собрать позиции из проигранных КП,
                    или добавьте позицию из карточки каталога.
                </div>
            @else
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                        <tr>
                            <th class="px-2 py-2 text-left">Позиция</th>
                            <th class="px-2 py-2 text-left">OEM</th>
                            <th class="px-2 py-2 text-right">Кол-во</th>
                            <th class="px-2 py-2 text-right">В КП</th>
                            <th class="px-2 py-2 text-left">Источник</th>
                            <th class="px-2 py-2 text-left">Статус</th>
                            <th class="px-2 py-2 text-right">Мин. цена</th>
                            <th class="px-2 py-2 text-right">Офферов</th>
                            <th class="px-2 py-2 text-left">Анализ</th>
                            <th class="px-2 py-2 text-right"></th>
                        </tr>
                    </thead>
                    @foreach($this->positions as $p)
                        @php
                            $ci = $p->catalogItem;
                            $fresh = $p->hasFreshReport();
                            $offers = $p->offers();
                        @endphp
                        <tbody wire:key="iqp-{{ $p->id }}" x-data="{ open: false }">
                            <tr class="border-b border-border-subtle hover:bg-hover align-top">
                                <td class="px-2 py-1.5">
                                    <div class="text-fg-1">{{ \Illuminate\Support\Str::limit($ci->name ?? '—', 60) }}</div>
                                    <div class="mono text-[11px] text-fg-3">{{ $ci->sku ?? '—' }}</div>
                                </td>
                                <td class="px-2 py-1.5 mono text-[11.5px] text-fg-2">{{ $p->payload_oem ?: ($ci?->oemForExternal() ?: '—') }}</td>
                                <td class="px-2 py-1.5 text-right mono text-fg-1 whitespace-nowrap">
                                    @if($p->qty !== null){{ rtrim(rtrim(number_format((float) $p->qty, 3, '.', ''), '0'), '.') }} <span class="text-fg-3 text-[11px]">{{ $p->unit ?: 'шт.' }}</span>@else <span class="text-fg-4">—</span>@endif
                                </td>
                                <td class="px-2 py-1.5 text-right mono {{ $p->lost_quote_count > 1 ? 'text-amber-700 font-semibold' : 'text-fg-2' }}">{{ $p->lost_quote_count ?: '—' }}</td>
                                <td class="px-2 py-1.5">
                                    @if($p->source === 'manual')
                                        <span class="text-[11px] text-sky-700">ручная</span>
                                    @else
                                        <span class="text-[11px] text-fg-3">из КП</span>
                                    @endif
                                </td>
                                <td class="px-2 py-1.5">
                                    <span class="text-[11.5px] font-medium {{ $statusClass[$p->status] ?? 'text-fg-2' }}">
                                        {{ $p->statusEnum()?->label() ?? $p->status }}
                                    </span>
                                    @if($p->status === 'analyzing' && $p->iqot_item_status)
                                        <div class="text-[10px] text-fg-3">{{ $iqotItemLabel[$p->iqot_item_status] ?? $p->iqot_item_status }}</div>
                                    @endif
                                    @if($p->status === 'failed' && $p->error_message)
                                        <div class="text-[10px] text-red-600" title="{{ $p->error_message }}">{{ \Illuminate\Support\Str::limit($p->error_message, 40) }}</div>
                                    @endif
                                </td>
                                <td class="px-2 py-1.5 text-right mono {{ $p->report_min_price !== null ? 'text-fg-1 font-semibold' : 'text-fg-4' }}">
                                    {{ $p->report_min_price !== null ? number_format((float) $p->report_min_price, 2, ',', ' ') . ' ₽' : '—' }}
                                </td>
                                <td class="px-2 py-1.5 text-right mono text-fg-2">
                                    @if($offers !== [])
                                        <button type="button" @click="open = !open" class="text-sky-700 hover:underline">{{ $p->report_offers_count ?? count($offers) }} <span x-text="open ? '▾' : '▸'"></span></button>
                                    @else
                                        {{ $p->report_offers_count ?? '—' }}
                                    @endif
                                </td>
                                <td class="px-2 py-1.5 text-[11.5px]">
                                    @if($p->analyzed_at)
                                        <span class="{{ $fresh ? 'text-emerald-700' : 'text-fg-3' }}">{{ $p->analyzed_at->format('d.m.Y') }}</span>
                                        @unless($fresh)<span class="text-[10px] text-amber-700 ml-1">устарел</span>@endunless
                                    @else
                                        <span class="text-fg-4">—</span>
                                    @endif
                                </td>
                                <td class="px-2 py-1.5 text-right whitespace-nowrap">
                                    @if($p->status === 'excluded')
                                        <button type="button" wire:click="unexclude({{ $p->id }})" class="btn btn-sm" wire:loading.attr="disabled">Вернуть в пул</button>
                                    @else
                                        {{-- «Повторить» только если нет свежего отчёта (иначе не пере-отправляем — бережём баланс) --}}
                                        @if($p->status === 'failed' || ($p->status === 'completed' && ! $fresh))
                                            <button type="button" wire:click="reanalyze({{ $p->id }})" class="btn btn-sm" wire:loading.attr="disabled">Повторить</button>
                                        @endif
                                        <button type="button" wire:click="exclude({{ $p->id }})"
                                                wire:confirm="Исключить позицию из пула? Она больше не будет отправляться в IQOT."
                                                class="btn btn-sm btn-danger" wire:loading.attr="disabled">Исключить</button>
                                    @endif
                                </td>
                            </tr>
                            @if($offers !== [])
                                @php $cmp = $p->priceComparison(); @endphp
                                <tr x-show="open" x-cloak class="border-b border-border-subtle bg-surface-2">
                                    <td colspan="10" class="px-4 py-2.5">
                                        @if($cmp['our_rank'])
                                            <div class="text-[12px] text-fg-2 mb-2">
                                                Наше КП <b class="text-red-700">{{ $cmp['our_quotation_code'] ?: '—' }}</b>
                                                занимает <b>{{ $cmp['our_rank'] }}-е место</b> из {{ $cmp['total'] }} по цене (без НДС)
                                                @if($cmp['delta'] !== null)
                                                    · vs лучший IQOT:
                                                    <b class="{{ $cmp['delta'] > 0 ? 'text-red-700' : 'text-emerald-700' }}">{{ $cmp['delta'] > 0 ? '+' : '' }}{{ number_format($cmp['delta'], 0, ',', ' ') }} ₽ ({{ $cmp['delta'] > 0 ? '+' : '' }}{{ number_format($cmp['delta_pct'], 1, ',', ' ') }}%)</b>
                                                @endif
                                            </div>
                                        @else
                                            <div class="text-[10px] uppercase tracking-wider text-fg-3 font-semibold mb-1.5">Предложения поставщиков ({{ $cmp['total'] }}) · сравнение по цене без НДС</div>
                                        @endif
                                        <table class="w-full text-[12px]">
                                            <thead class="text-fg-3 text-[10px] uppercase tracking-wider">
                                                <tr>
                                                    <th class="px-2 py-1 text-center w-8">#</th>
                                                    <th class="px-2 py-1 text-left">Поставщик</th>
                                                    <th class="px-2 py-1 text-left">Контакты</th>
                                                    <th class="px-2 py-1 text-right">Цена/шт</th>
                                                    <th class="px-2 py-1 text-right">Без НДС</th>
                                                    <th class="px-2 py-1 text-right">Срок</th>
                                                    <th class="px-2 py-1 text-left">Получено</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($cmp['rows'] as $i => $r)
                                                    <tr class="border-t border-border-subtle {{ $r['is_ours'] ? 'bg-red-50' : '' }}" @if(!empty($r['notes'])) title="{{ $r['notes'] }}" @endif>
                                                        <td class="px-2 py-1 text-center font-bold {{ $r['is_ours'] ? 'text-red-700' : 'text-fg-3' }}">{{ $i + 1 }}</td>
                                                        <td class="px-2 py-1 {{ $r['is_ours'] ? 'text-red-700' : 'text-fg-1' }}">
                                                            @if($r['is_ours'])<span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-red-600 text-white mr-1">КП</span>@endif{{ $r['supplier'] }}
                                                        </td>
                                                        <td class="px-2 py-1 text-fg-2 text-[11px] mono">
                                                            @if($r['is_ours'])<span class="text-fg-3">собственное КП</span>@else{{ $r['phone'] }}{{ !empty($r['email']) ? ' · ' . $r['email'] : '' }}@endif
                                                        </td>
                                                        <td class="px-2 py-1 text-right mono font-semibold {{ $r['is_ours'] ? 'text-red-700' : 'text-fg-1' }}">
                                                            {{ number_format($r['raw'], 2, ',', ' ') }} ₽
                                                            <div class="text-[9px] text-fg-3">{{ $r['vat_label'] }}</div>
                                                        </td>
                                                        <td class="px-2 py-1 text-right mono text-fg-2">{{ number_format($r['net'], 2, ',', ' ') }}</td>
                                                        <td class="px-2 py-1 text-right mono text-fg-2">{{ $r['delivery_days'] !== null ? $r['delivery_days'] . ' дн' : '—' }}</td>
                                                        <td class="px-2 py-1 text-fg-3 text-[11px]">{{ $r['received_at'] ? \Illuminate\Support\Carbon::parse($r['received_at'])->format('d.m H:i') : '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    @endforeach
                </table>
                <div class="mt-3">{{ $this->positions->links() }}</div>
            @endif
        </div>
    </div>
</div>
