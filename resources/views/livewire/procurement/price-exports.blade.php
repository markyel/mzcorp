<div class="space-y-3">
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Выгрузка новых цен поставщиков</h3>
            <span class="text-[12px] text-fg-3 ml-2">Excel по шаблону для 1С: M-артикул, по каждому поставщику — цена, дата предложения, валюта; в конце — выбранный поставщик</span>
        </div>
        <div class="ds-card-body text-[13px] space-y-3">
            {{-- Новое — с конца прошлой выгрузки (история общая со «Снабжением»): цены не повторяются и не теряются. --}}
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" wire:click="exportNew" class="btn btn-sm btn-primary" wire:loading.attr="disabled" wire:target="exportNew,export">
                    <span wire:loading.remove wire:target="exportNew">⤓ Выгрузить новое</span><span wire:loading wire:target="exportNew">Формирую…</span>
                </button>
                <span class="text-fg-3 text-[12px]">полученное с {{ $lastEnd->format('d.m.Y H:i') }} (конец прошлой выгрузки) по сейчас</span>
            </div>
            <form wire:submit="export" class="flex flex-wrap items-end gap-3">
                <span class="text-fg-2 text-[12.5px] self-center">или за период:</span>
                <label class="block"><span class="text-fg-3 text-[12px]">С</span>
                    <input type="datetime-local" wire:model="from" class="h-[30px] px-2 border border-border rounded-md bg-surface text-[13px]"></label>
                <label class="block"><span class="text-fg-3 text-[12px]">По</span>
                    <input type="datetime-local" wire:model="to" class="h-[30px] px-2 border border-border rounded-md bg-surface text-[13px]"></label>
                <button type="submit" class="btn btn-sm" wire:loading.attr="disabled" wire:target="exportNew,export">
                    <span wire:loading.remove wire:target="export">Выгрузить за период</span><span wire:loading wire:target="export">Формирую…</span>
                </button>
                @error('from')<span class="text-red-700 text-[12px]">{{ $message }}</span>@enderror
                @error('to')<span class="text-red-700 text-[12px]">{{ $message }}</span>@enderror
            </form>
            @if($error)<div class="text-red-700 text-[12.5px]">{{ $error }}</div>@endif
            <p class="text-[12px] text-fg-3">
                Берутся все поставщики, приславшие цену за период — ответами на рассылку и через QuoteParts; по поставщику и позиции — последняя цена.
                Аналог, копия, восстановленное попадают, только если приняты к закупке, — как цена самой позиции.
                По умолчанию период начинается там, где закончилась прошлая выгрузка.
            </p>
        </div>
    </div>

    <div class="ds-card">
        <div class="ds-card-header"><h3>Выгрузки</h3>@if($compact)<span class="text-[12px] text-fg-3 ml-2">последние · все — в «Снабжении» → «Выгрузки цен»</span>@endif</div>
        <div class="overflow-x-auto">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                    <tr>
                        <th class="text-left px-3 py-2">Сформирована</th>
                        <th class="text-left px-3 py-2">Период</th>
                        <th class="text-right px-3 py-2">Артикулов</th>
                        <th class="text-right px-3 py-2">Поставщиков</th>
                        <th class="text-right px-3 py-2" title="Ответы рассылки с аналогом / копией в тексте — разобрать по письму">Не выгружено</th>
                        <th class="text-left px-3 py-2">Кто</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($exports as $e)
                        <tr wire:key="pe-{{ $e->id }}" class="border-b border-border-subtle">
                            <td class="px-3 py-1.5 mono">{{ $e->created_at->format('d.m.Y H:i') }}</td>
                            <td class="px-3 py-1.5 mono text-fg-2">{{ $e->from_at->format('d.m.Y H:i') }} – {{ $e->to_at->format('d.m.Y H:i') }}</td>
                            <td class="px-3 py-1.5 text-right mono">{{ $e->rows_count }}</td>
                            <td class="px-3 py-1.5 text-right mono">{{ $e->suppliers_count }}</td>
                            <td class="px-3 py-1.5 text-right mono text-fg-3">{{ $e->skipped_count ?: '—' }}</td>
                            <td class="px-3 py-1.5 text-fg-3">{{ $e->createdBy?->name ?? '—' }}</td>
                            <td class="px-3 py-1.5 text-right">
                                @if($e->file_path)<a href="{{ route('procurement.price-exports.download', $e->id) }}" class="btn btn-sm">Скачать .xlsx</a>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-fg-3">Выгрузок ещё не было.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
