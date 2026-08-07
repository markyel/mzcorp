<div class="space-y-4">
    <div class="flex items-center gap-3 flex-wrap">
        <h2 class="text-[16px] font-semibold text-fg-1">Еженедельные отчёты</h2>
        <span class="text-[12px] text-fg-3">персональные итоги менеджеров по неделям</span>
        <span class="flex-1"></span>
        @if($this->managerOptions)
            <select wire:model.live="viewUser"
                    class="h-[32px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
                <option value="0">Все менеджеры</option>
                @foreach($this->managerOptions as $m)
                    <option value="{{ $m['id'] }}">{{ $m['name'] }}</option>
                @endforeach
            </select>
        @endif
    </div>

    <div class="grid grid-cols-1 md:grid-cols-[260px_1fr] gap-4 items-start">
        {{-- Список отчётов --}}
        <div class="ds-card">
            <div class="ds-card-body p-0 max-h-[70vh] overflow-y-auto">
                @forelse($this->reports as $r)
                    @php
                        $on = $this->reportId === $r->id;
                        $ps = \Illuminate\Support\Carbon::parse($r->period_start);
                        $pe = \Illuminate\Support\Carbon::parse($r->period_end);
                        $won = $r->data['result']['won'] ?? 0;
                    @endphp
                    <button type="button" wire:click="$set('reportId', {{ $r->id }})" wire:key="wr-{{ $r->id }}"
                            class="w-full text-left px-3 py-2.5 border-b border-border-subtle transition-colors
                                   {{ $on ? 'bg-[var(--sky-50)]' : 'hover:bg-surface-2' }}"
                            @style(['box-shadow:inset 2px 0 0 var(--accent)' => $on])>
                        <div class="flex items-center gap-2 text-[12.5px]">
                            <span class="font-medium text-fg-1 mono">{{ $ps->format('d.m') }}–{{ $pe->format('d.m') }}</span>
                            <span class="text-fg-4 text-[11px]">нед. {{ $ps->isoWeek() }}</span>
                            <span class="flex-1"></span>
                            <span class="chip chip-ok text-[10px]"><span class="dot"></span>{{ $won }}</span>
                        </div>
                        @if($this->managerOptions)
                            <div class="text-[11.5px] text-fg-3 mt-0.5 truncate">{{ $r->user?->name }}</div>
                        @endif
                    </button>
                @empty
                    <div class="px-3 py-8 text-center text-[12.5px] text-fg-3">Отчётов пока нет.</div>
                @endforelse
            </div>
        </div>

        {{-- Выбранный отчёт --}}
        <div>
            @if($this->report)
                @include('reports.weekly', ['data' => $this->report->data])
            @else
                <div class="ds-card"><div class="ds-card-body text-[13px] text-fg-3 text-center py-10">Выберите отчёт слева.</div></div>
            @endif
        </div>
    </div>
</div>
