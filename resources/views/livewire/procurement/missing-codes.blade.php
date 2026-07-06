<div class="space-y-4">
    <div class="ds-card">
        <div class="ds-card-header">
            <h2 class="text-[16px] font-semibold text-fg-1">🔍 Не найдено в каталоге</h2>
            <span class="text-[12px] text-fg-3 ml-2">повторяющиеся OEM-коды из заявок без совпадения в каталоге</span>
            <span class="flex-1"></span>
            <button type="button" wire:click="exportExcel" wire:loading.attr="disabled" wire:target="exportExcel" class="btn btn-sm mr-3">
                <span wire:loading.remove wire:target="exportExcel">📥 Excel</span>
                <span wire:loading wire:target="exportExcel">Формирую…</span>
            </button>
            <a href="{{ route('procurement.index') }}" wire:navigate class="btn btn-sm">← Снабжение</a>
        </div>
        <div class="ds-card-body">
            <div class="text-[11.5px] text-fg-4">
                Автоматика распознала артикул производителя, но в каталоге совпадения не нашлось — заявки с такими позициями
                выигрываются на <b>11,7%</b> против <b>40%</b> у полностью распознанных. Это пробелы базы кодов-синонимов
                (код есть у товара, но не занесён) и кандидаты на расширение ассортимента. Чем чаще код повторяется — тем выше приоритет.
                Метка <span class="chip chip-ok text-[10px]">уже в каталоге</span> — код появился в каталоге позже (проверьте привязку в заявках).
            </div>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Поиск: код / название / марка"
               class="h-[32px] w-full max-w-[340px] px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
        <select wire:model.live="periodDays"
                class="h-[32px] pl-2 pr-8 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
            <option value="30">запросы за 30 дн.</option>
            <option value="60">запросы за 60 дн.</option>
            <option value="90">запросы за 90 дн.</option>
            <option value="0">за всё время</option>
        </select>
        <span class="text-[11.5px] text-fg-3 mono">{{ $this->codes->total() }} кодов</span>
    </div>

    <div class="ds-card">
        <div class="ds-card-body overflow-x-auto p-0">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                    <tr>
                        <th class="text-right px-2 py-2" style="width:40px">#</th>
                        <th class="text-left px-2 py-2" style="width:190px">OEM-код</th>
                        <th class="text-left px-2 py-2">Как назвал клиент</th>
                        <th class="text-left px-2 py-2" style="width:150px">Марка</th>
                        <th class="text-right px-2 py-2" style="width:70px">Заявок</th>
                        <th class="text-right px-2 py-2" style="width:110px">Исход (✓/✕)</th>
                        <th class="text-left px-2 py-2" style="width:95px">Последний</th>
                        <th class="text-left px-2 py-2">Заявки</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->codes as $i => $row)
                        <tr class="border-b border-border-subtle hover:bg-hover align-top" wire:key="mc-{{ md5($row->code) }}">
                            <td class="px-2 py-2 text-right mono text-fg-4">{{ $this->codes->firstItem() + $i }}</td>
                            <td class="px-2 py-2 mono text-fg-1">
                                {{ $row->code }}
                                @if($row->in_catalog_now)
                                    <span class="chip chip-ok text-[10px] ml-1" title="Код уже встречается в каталоге — вероятно, добавлен после этих заявок">уже в каталоге</span>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-fg-2">{{ \Illuminate\Support\Str::limit($row->sample_name, 70) }}</td>
                            <td class="px-2 py-2 text-fg-3">{{ $row->brand ?: '—' }}</td>
                            <td class="px-2 py-2 text-right"><span class="chip chip-warn text-[11px] mono">{{ $row->reqs }}</span></td>
                            <td class="px-2 py-2 text-right mono">
                                <span class="text-emerald-700">{{ $row->won }}</span> / <span class="text-red-700">{{ $row->lost }}</span>
                            </td>
                            <td class="px-2 py-2 mono text-fg-3">{{ $row->last_seen }}</td>
                            <td class="px-2 py-2">
                                <span class="flex flex-wrap gap-1">
                                    @foreach($row->request_codes as $code)
                                        @php $rid = null; @endphp
                                        <a href="{{ url('/dashboard/requests') }}?q={{ urlencode($code) }}"
                                           class="text-sky-700 hover:underline mono text-[11px]">{{ $code }}</a>
                                    @endforeach
                                    @if($row->reqs > count($row->request_codes))<span class="text-fg-4 text-[11px]">+{{ $row->reqs - count($row->request_codes) }}</span>@endif
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-10 text-center text-fg-3 text-[13px]">Ничего не найдено.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3">{{ $this->codes->links() }}</div>
    </div>
</div>
