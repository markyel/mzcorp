<div class="space-y-4">
    @php
        $stats = $this->stats;
        $statusChips = [
            '' => 'Активные',
            'pending' => 'В очереди',
            'analyzing' => 'Анализируется',
            'completed' => 'Готов отчёт',
            'no_offers' => 'Без предложений',
            'failed' => 'Ошибка',
            'excluded' => 'Исключённые',
        ];
        $statusClass = [
            'pending' => 'text-fg-3',
            'queued' => 'text-fg-3',
            'analyzing' => 'text-amber-700',
            'completed' => 'text-emerald-700',
            'no_offers' => 'text-amber-700',
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
            @if($stats['critical'] > 0)
                <span class="text-fg-3">🔴 Выше рынка: <span class="text-red-700 mono font-bold">{{ $stats['critical'] }}</span></span>
            @endif
            <span class="text-fg-3">В пуле заявок: <span class="text-fg-1 mono font-semibold">{{ $stats['total'] }}</span></span>
            <span class="text-fg-3" title="Мониторинг цен «Гибрид»: цены сайтов поставщиков и КП по почте. Позиции «К актуализации» с OEM, синхронизация раз в час">Мониторинг: <span class="text-fg-1 mono font-semibold">{{ $stats['mon_active'] }}</span>, с ценами <span class="text-emerald-700 mono font-semibold">{{ $stats['mon_priced'] }}</span>@if($stats['mon_kp'] > 0), с КП <span class="mono font-semibold">{{ $stats['mon_kp'] }}</span>@endif @if($stats['mon_synced'])<span class="text-fg-4">· {{ \Illuminate\Support\Carbon::parse($stats['mon_synced'])->format('d.m H:i') }}</span>@endif</span>
            <span class="flex-1"></span>
            <button type="button" wire:click="refreshPool" class="btn btn-sm" wire:loading.attr="disabled">Обновить пул</button>
            <button type="button" wire:click="runPoll" class="btn btn-sm" wire:loading.attr="disabled">Обновить статусы</button>
            @if(($stats['stuck_analyzing'] ?? 0) > 0)
                <button type="button" wire:click="resendAllStuck"
                        wire:confirm="Вернуть в очередь все {{ $stats['stuck_analyzing'] }} зависших в анализе позиций (старше {{ $stats['stuck_days'] }} дн)? Отправятся партиями по дневному лимиту."
                        class="btn btn-sm" wire:loading.attr="disabled"
                        title="Позиции застряли в «Анализируется» — вернуть на отправку">
                    Переотправить зависшие ({{ $stats['stuck_analyzing'] }})
                </button>
            @endif
            @if($stats['mode'] === 'monitoring')
                <button type="button" wire:click="runMonitoring" class="btn btn-sm btn-primary" wire:loading.attr="disabled"
                        @if(! $stats['enabled'] || ! $stats['configured']) disabled title="Включите IQOT и задайте ключ в Настройках" @else title="«К актуализации» с OEM — на мониторинг, цены — сюда (автоматически раз в час)" @endif>
                    Синхронизировать мониторинг
                </button>
            @else
                <button type="button" wire:click="runDispatch" class="btn btn-sm btn-primary" wire:loading.attr="disabled"
                        @if(! $stats['enabled'] || ! $stats['configured']) disabled title="Включите IQOT и задайте ключ в Настройках" @endif>
                    Запустить анализ
                </button>
            @endif
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
                @foreach(['' => 'Все источники', 'monitoring' => 'Мониторинг', 'auto' => 'Заявки: из КП', 'manual' => 'Заявки: ручные'] as $k => $label)
                    @php $on = $sourceFilter === $k; @endphp
                    <button type="button" wire:click="$set('sourceFilter', '{{ $k }}')"
                            class="h-[26px] px-2.5 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $on ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <button type="button" wire:click="$toggle('attentionOnly')"
                    class="h-[26px] px-2.5 inline-flex items-center gap-1.5 whitespace-nowrap font-medium rounded-md border
                           {{ $attentionOnly ? 'bg-red-600 text-white border-red-600' : 'bg-surface text-fg-2 border-border hover:text-fg-1' }}"
                    title="Только позиции, где наша цена на {{ \App\Models\IqotPosition::attentionMinRank() }}-м месте или ниже и дороже лучшей цены IQOT больше чем на {{ rtrim(rtrim(number_format(\App\Models\IqotPosition::attentionMinDeviationPct(), 1, '.', ''), '0'), '.') }}%">
                ⚠ Требуют внимания
                @if($stats['attention'] > 0)
                    <span class="px-1 rounded text-[10px] font-bold {{ $attentionOnly ? 'bg-white/25 text-white' : 'bg-red-100 text-red-700' }}">{{ $stats['attention'] }}</span>
                @endif
            </button>

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
                    Пусто. Позиции «К актуализации» с OEM уходят на мониторинг раз в час («Синхронизировать мониторинг» — сразу);
                    позицию можно добавить и из карточки каталога.
                </div>
            @else
                @include('livewire.iqot._rows')
                <div class="mt-3">{{ $this->positions->links() }}</div>
            @endif
        </div>
    </div>
</div>
