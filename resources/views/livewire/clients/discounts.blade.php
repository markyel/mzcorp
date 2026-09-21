@php
    $inp = 'h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500';
    $stats = $this->stats;
@endphp

<div class="space-y-4">

    @if($notice)
        <div class="ds-card"><div class="ds-card-body flex items-center gap-2 text-[13px] text-emerald-700">
            <span>{{ $notice }}</span><span class="flex-1"></span>
            <button type="button" class="btn btn-sm" wire:click="$set('notice', null)">Скрыть</button>
        </div></div>
    @endif
    @if($error)
        <div class="ds-card"><div class="ds-card-body flex items-center gap-2 text-[13px] text-amber-800">
            <span>{{ $error }}</span><span class="flex-1"></span>
            <button type="button" class="btn btn-sm" wire:click="$set('error', null)">Скрыть</button>
        </div></div>
    @endif

    {{-- Загрузка --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">💾 Загрузка скидок</h3>
            <span class="text-[12px] text-fg-3">выгрузка из корпоративной базы: Контрагент · ИНН · ГруппаКомпаний · СкидкаПроцент</span>
            <span class="flex-1"></span>
            @if($stats['last'])
                <span class="text-[11.5px] text-fg-4">
                    последняя: {{ $stats['last']->source_file ?: 'без имени' }} ·
                    {{ $stats['last']->updated_at?->format('d.m.Y H:i') }}
                </span>
            @endif
        </div>
        <div class="ds-card-body space-y-3">
            @if(! $preview)
                <form wire:submit.prevent="analyze" class="flex flex-wrap items-center gap-3">
                    <input type="file" wire:model="file" accept=".xlsx,.xls" class="text-[12.5px]">
                    <button type="submit" class="btn btn-sm btn-primary" wire:loading.attr="disabled" wire:target="file,analyze">
                        <span wire:loading.remove wire:target="analyze">Разобрать файл</span>
                        <span wire:loading wire:target="analyze">Разбираю…</span>
                    </button>
                    <span wire:loading wire:target="file" class="text-[12px] text-fg-3">Загружаю…</span>
                    @error('file')<span class="text-[12px] text-red-700">{{ $message }}</span>@enderror
                </form>
                <div class="text-[11.5px] text-fg-4">
                    Сопоставление идёт по ИНН — названия в выгрузке живые, по ним сверяться нельзя.
                    Ведущий ноль, который Excel съедает у ИНН, восстанавливается автоматически.
                    Контрагенты, которых у нас ещё нет, сохраняются тоже: появится организация — скидка применится.
                </div>
            @else
                {{-- Предпросмотр: что нашли в файле --}}
                <div class="flex flex-wrap items-center gap-4 text-[12.5px]">
                    <span>строк в файле: <b class="mono">{{ $preview['stats']['total'] ?? 0 }}</b></span>
                    <span class="text-emerald-700">пригодных: <b class="mono">{{ $preview['stats']['ok'] ?? 0 }}</b></span>
                    @if(($preview['stats']['padded_inn'] ?? 0) > 0)
                        <span class="text-fg-3">восстановлен ноль в ИНН: <b class="mono">{{ $preview['stats']['padded_inn'] }}</b></span>
                    @endif
                    @if(($preview['stats']['no_inn'] ?? 0) > 0)
                        <span class="text-amber-800">без ИНН: <b class="mono">{{ $preview['stats']['no_inn'] }}</b></span>
                    @endif
                    @if(($preview['stats']['bad_discount'] ?? 0) > 0)
                        <span class="text-red-700">странная скидка: <b class="mono">{{ $preview['stats']['bad_discount'] }}</b></span>
                    @endif
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach($preview['by_discount'] as $percent => $count)
                        <span class="chip text-[11px]" style="background:var(--neutral-100);color:var(--fg-2)">
                            {{ rtrim(rtrim(number_format((float) $percent, 2, ',', ' '), '0'), ',') }}% — {{ $count }}
                        </span>
                    @endforeach
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-[12.5px]" style="border-collapse:collapse">
                        <thead>
                            <tr class="text-fg-3 text-[10.5px] uppercase tracking-wide">
                                <th class="text-left py-1.5 pr-2">ИНН</th>
                                <th class="text-left py-1.5 pr-2">Контрагент</th>
                                <th class="text-left py-1.5 pr-2">Группа</th>
                                <th class="text-right py-1.5">Скидка</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($preview['sample'] as $row)
                                <tr class="border-t border-border-subtle">
                                    <td class="py-1.5 pr-2 mono">{{ $row['inn'] }}</td>
                                    <td class="py-1.5 pr-2">{{ \Illuminate\Support\Str::limit($row['name'], 52) }}</td>
                                    <td class="py-1.5 pr-2 text-fg-3">{{ \Illuminate\Support\Str::limit($row['group_name'] ?? '—', 28) }}</td>
                                    <td class="py-1.5 text-right mono">{{ rtrim(rtrim(number_format($row['discount_percent'], 2, ',', ' '), '0'), ',') }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="text-[11px] text-fg-4 pt-1">показаны первые {{ count($preview['sample']) }} строк</div>
                </div>

                @if($preview['errors'])
                    <details>
                        <summary class="text-[12px] text-amber-800 cursor-pointer">Пропущенные строки ({{ count($preview['errors']) }})</summary>
                        <div class="mt-1 space-y-0.5">
                            @foreach($preview['errors'] as $line)
                                <div class="text-[11.5px] text-fg-3">{{ $line }}</div>
                            @endforeach
                        </div>
                    </details>
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn btn-sm btn-primary" wire:click="apply"
                            wire:loading.attr="disabled" wire:target="apply">
                        <span wire:loading.remove wire:target="apply">Загрузить {{ $preview['stats']['ok'] ?? 0 }} скидок</span>
                        <span wire:loading wire:target="apply">Загружаю…</span>
                    </button>
                    <button type="button" class="btn btn-sm" wire:click="cancel">Отмена</button>
                    <span class="text-[11.5px] text-fg-4">
                        Скидка проставится в карточку организации и будет применяться в КП — в том числе автоматическом.
                    </span>
                </div>
            @endif
        </div>
    </div>

    {{-- Что загружено --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">Скидки контрагентов</h3>
            <span class="text-[12px] text-fg-3">
                всего <b class="mono text-fg-2">{{ $stats['total'] }}</b>,
                сопоставлено с организациями <b class="mono text-fg-2">{{ $stats['matched'] }}</b>
            </span>
            <span class="flex-1"></span>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="ИНН, название, группа"
                   class="{{ $inp }} w-[240px]">
        </div>
        <div class="ds-card-body">
            @if($stats['by_discount'])
                <div class="flex flex-wrap gap-2 mb-2">
                    @foreach($stats['by_discount'] as $percent => $count)
                        <span class="chip text-[11px]" style="background:var(--neutral-100);color:var(--fg-2)">
                            {{ rtrim(rtrim(number_format((float) $percent, 2, ',', ' '), '0'), ',') }}% — {{ $count }}
                        </span>
                    @endforeach
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-[12.5px]" style="border-collapse:collapse">
                    <thead>
                        <tr class="text-fg-3 text-[10.5px] uppercase tracking-wide">
                            <th class="text-left py-1.5 pr-2">ИНН</th>
                            <th class="text-left py-1.5 pr-2">Контрагент</th>
                            <th class="text-left py-1.5 pr-2">Группа</th>
                            <th class="text-left py-1.5 pr-2">Организация в системе</th>
                            <th class="text-right py-1.5">Скидка</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($this->discounts as $d)
                            <tr wire:key="cd-{{ $d->id }}" class="border-t border-border-subtle">
                                <td class="py-1.5 pr-2 mono">{{ $d->inn }}</td>
                                <td class="py-1.5 pr-2">{{ \Illuminate\Support\Str::limit($d->name, 50) }}</td>
                                <td class="py-1.5 pr-2 text-fg-3">{{ \Illuminate\Support\Str::limit($d->group_name ?: '—', 26) }}</td>
                                <td class="py-1.5 pr-2">
                                    @if($d->organization)
                                        <span class="text-fg-2">{{ \Illuminate\Support\Str::limit($d->organization->name, 34) }}</span>
                                    @else
                                        <span class="text-fg-4">нет у нас</span>
                                    @endif
                                </td>
                                <td class="py-1.5 text-right mono">{{ rtrim(rtrim(number_format((float) $d->discount_percent, 2, ',', ' '), '0'), ',') }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-3 text-[13px] text-fg-3">Пока ничего не загружено.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="pt-2">{{ $this->discounts->links() }}</div>
        </div>
    </div>
</div>
