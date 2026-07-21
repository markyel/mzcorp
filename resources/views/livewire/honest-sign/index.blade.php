@php
    $r = $result ?? [];
@endphp

<div class="max-w-[1100px] mx-auto px-4 py-5">

    <div class="flex items-center gap-3 mb-4">
        <h1 class="text-[18px] font-semibold text-fg-1">Честный знак</h1>
        <span class="text-[12px] text-fg-3">коды маркировки из PDF → файл поставки</span>
    </div>

    {{-- Вкладки --}}
    <div class="flex items-center gap-1.5 mb-4 border-b border-border-subtle">
        <button type="button" wire:click="setTab('parse')"
                class="px-3 py-1.5 text-[13px] {{ $tab === 'parse' ? 'text-fg-1 font-semibold border-b-2 border-[var(--sky-500)]' : 'text-fg-3' }}">
            Разбор
        </button>
        <button type="button" wire:click="setTab('journal')"
                class="px-3 py-1.5 text-[13px] {{ $tab === 'journal' ? 'text-fg-1 font-semibold border-b-2 border-[var(--sky-500)]' : 'text-fg-3' }}">
            Журнал и поиск
        </button>
    </div>

    @if($tab === 'parse')
        <div class="ds-card p-4 mb-4">
            <div class="text-[12.5px] text-fg-2 mb-3">
                Загрузите PDF с кодами маркировки — <b>одна страница = один DataMatrix</b>.
                Если приложить файл поставки (.xlsx), коды будут разложены по строкам
                автоматически: строка ищется по <b>MZ-ID</b> — либо по явной колонке,
                либо по артикулу в начале «Наименование&nbsp;ТОРГ-12» (до первой запятой).
                Заполняются только колонки <b>GTIN</b> и <b>КИЗ</b>, остальное не трогается.
                Без файла поставки — просто покажем коды для копирования.
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[11px] uppercase tracking-wider font-semibold text-fg-3 mb-1">
                        PDF с кодами <span class="text-red-700">*</span>
                    </label>
                    <input type="file" wire:model="pdfs" multiple accept="application/pdf"
                           class="w-full text-[12.5px] border border-border rounded-md p-1.5">
                    @error('pdfs') <div class="text-red-700 text-[11.5px] mt-1">{{ $message }}</div> @enderror
                    @error('pdfs.*') <div class="text-red-700 text-[11.5px] mt-1">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="block text-[11px] uppercase tracking-wider font-semibold text-fg-3 mb-1">
                        Файл поставки (.xlsx) — необязательно
                    </label>
                    <input type="file" wire:model="excel" accept=".xlsx,.xls"
                           class="w-full text-[12.5px] border border-border rounded-md p-1.5">
                    @error('excel') <div class="text-red-700 text-[11.5px] mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            <div class="flex items-center gap-2 mt-3">
                <button type="button" wire:click="process" wire:loading.attr="disabled" class="btn btn-primary">
                    <span wire:loading.remove wire:target="process">Разобрать</span>
                    <span wire:loading wire:target="process">Обрабатываю…</span>
                </button>
                @if($filledPath)
                    <button type="button" wire:click="download" class="btn">
                        ↓ Скачать заполненный файл
                    </button>
                    <span class="text-[11.5px] text-fg-3">{{ $filledName }}</span>
                @endif
            </div>
        </div>

        @if(!empty($r))
            {{-- Сводка --}}
            <div class="ds-card p-3 mb-3 text-[12.5px]">
                <span class="text-fg-2">Разобрано:</span>
                <b class="text-fg-1">{{ $r['total'] }}</b> код(ов) из
                <b class="text-fg-1">{{ $r['pdfs'] }}</b> файл(ов)
                @if(!empty($r['filled']))
                    · заполнено строк: <b class="text-emerald-700">{{ count($r['filled']) }}</b>
                @endif
            </div>

            @if(!empty($r['duplicates']))
                <div class="p-2.5 rounded bg-amber-50 mb-3 text-[11.5px] text-amber-800">
                    ⚠ <b>Повторная подача:</b> {{ count($r['duplicates']) }} код(ов) уже
                    проходили через систему раньше. Найти их можно во вкладке «Журнал и поиск».
                </div>
            @endif

            @if(!empty($r['unmatched']))
                <div class="p-2.5 rounded bg-amber-50 mb-3 text-[11.5px] text-amber-800">
                    ⚠ <b>Не нашлись в файле поставки</b> (нет строки с таким MZ-ID):
                    <span class="mono">{{ implode(', ', $r['unmatched']) }}</span>.
                    Коды разобраны — можно скопировать вручную ниже.
                </div>
            @endif

            @if(!empty($r['warnings']))
                <div class="p-2.5 rounded bg-amber-50 mb-3 text-[11.5px] text-amber-800 space-y-0.5">
                    @foreach($r['warnings'] as $w)
                        <div>⚠ {{ $w }}</div>
                    @endforeach
                </div>
            @endif

            {{-- Результат по артикулам --}}
            @foreach($r['groups'] as $g)
                <div class="ds-card p-3 mb-2">
                    <div class="flex items-center gap-2 flex-wrap mb-2">
                        <span class="mono font-semibold text-fg-1">{{ $g['article'] }}</span>
                        <span class="chip chip-ok text-[10.5px]">{{ count($g['codes']) }} код(ов)</span>
                        <span class="text-[11.5px] text-fg-3">GTIN:</span>
                        <span class="mono text-[12px] text-fg-1">{{ $g['gtin'] }}</span>
                        <span class="flex-1"></span>
                        <button type="button" class="btn btn-sm"
                                x-data
                                x-on:click="navigator.clipboard.writeText($refs.kiz{{ $loop->index }}.value);
                                            $el.textContent='✓ скопировано';
                                            setTimeout(()=>$el.textContent='⧉ Копировать КИЗ',1500)">
                            ⧉ Копировать КИЗ
                        </button>
                        <button type="button" class="btn btn-sm"
                                x-data
                                x-on:click="navigator.clipboard.writeText('{{ $g['gtin'] }}');
                                            $el.textContent='✓';
                                            setTimeout(()=>$el.textContent='⧉ GTIN',1500)">
                            ⧉ GTIN
                        </button>
                    </div>
                    <textarea readonly x-ref="kiz{{ $loop->index }}"
                              class="w-full mono text-[11.5px] border border-border rounded p-2 bg-[var(--neutral-50)]"
                              rows="{{ min(count($g['codes']) + 1, 8) }}">{{ $g['kiz'] }}</textarea>
                </div>
            @endforeach
        @endif
    @else
        {{-- ЖУРНАЛ + ПОИСК --}}
        <div class="ds-card p-3 mb-4">
            <label class="block text-[11px] uppercase tracking-wider font-semibold text-fg-3 mb-1">
                Поиск по коду / GTIN / артикулу / названию
            </label>
            <input type="search" wire:model.live.debounce.400ms="search"
                   placeholder="например 0104681008402919215f8… или M05143"
                   class="w-full h-[32px] px-2.5 border border-border rounded-md text-[12.5px] outline-none focus:border-[var(--sky-500)]">
            <div class="text-[11px] text-fg-4 mt-1">минимум 3 символа</div>

            @if(mb_strlen(trim($search)) >= 3)
                @php $found = $this->foundCodes; @endphp
                <div class="mt-3">
                    <div class="text-[12px] text-fg-2 mb-1.5">Найдено: <b>{{ $found->count() }}</b>@if($found->count() >= 100) (показаны первые 100)@endif</div>
                    @forelse($found as $c)
                        <div class="flex items-baseline gap-2 py-1 border-b border-border-subtle text-[11.5px] flex-wrap">
                            <span class="mono text-fg-1">{{ $c->code }}</span>
                            <span class="mono text-fg-3">{{ $c->article }}</span>
                            <span class="flex-1"></span>
                            <span class="text-fg-4">{{ $c->batch?->title }}</span>
                            <span class="text-fg-4">{{ $c->batch?->user?->name }}</span>
                            <span class="text-fg-4">{{ $c->batch?->created_at?->format('d.m.Y H:i') }}</span>
                        </div>
                    @empty
                        <div class="text-fg-3 text-[12px] py-2">Ничего не найдено.</div>
                    @endforelse
                </div>
            @endif
        </div>

        <div class="ds-card overflow-hidden">
            <div class="ds-card-header"><h3>История разборов</h3></div>
            <table class="w-full text-[12.5px]">
                <thead>
                    <tr class="text-[11px] uppercase tracking-wider text-fg-3">
                        <th class="text-left px-3 py-2">Когда</th>
                        <th class="text-left px-3 py-2">Кто</th>
                        <th class="text-left px-3 py-2">Файл</th>
                        <th class="text-right px-3 py-2">PDF</th>
                        <th class="text-right px-3 py-2">Кодов</th>
                        <th class="text-right px-3 py-2">Строк</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->batches as $b)
                        <tr class="border-t border-border-subtle">
                            <td class="px-3 py-1.5 text-fg-3">{{ $b->created_at?->format('d.m.Y H:i') }}</td>
                            <td class="px-3 py-1.5">{{ $b->user?->name }}</td>
                            <td class="px-3 py-1.5 text-fg-2">{{ $b->title }}</td>
                            <td class="px-3 py-1.5 text-right mono">{{ $b->pdf_count }}</td>
                            <td class="px-3 py-1.5 text-right mono font-medium">{{ $b->codes_count }}</td>
                            <td class="px-3 py-1.5 text-right mono">{{ $b->rows_filled ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-fg-3">Разборов пока не было.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="p-2">{{ $this->batches->links() }}</div>
        </div>
    @endif
</div>
