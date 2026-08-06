<div class="max-w-[1400px] mx-auto px-4 py-5">
    <div class="flex items-center gap-3 mb-1 flex-wrap">
        <h1 class="text-[18px] font-semibold text-fg-1">🎯 Аудит матчинга каталога</h1>
        <span class="chip chip-warn text-[11px] mono">{{ $this->total }}</span>
    </div>
    <p class="text-[12.5px] text-fg-3 mb-4 max-w-[900px]">
        Позиции, где система сматчила один каталог, а менеджер в отправленном КП указал другой
        (SKU из КП — «истина»). Кандидаты в ложные матчи для контроля качества.
        «Исправить» переставит каталог позиции на тот, что в КП.
    </p>

    {{-- Фильтры --}}
    <div class="flex items-center gap-3 mb-3 flex-wrap">
        <input type="search" wire:model.live.debounce.400ms="search"
               placeholder="Поиск: заявка / название / SKU"
               class="h-[32px] px-3 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500" style="width:320px">
        <label class="inline-flex items-center gap-1.5 text-[12px] text-fg-2 cursor-pointer select-none">
            <input type="checkbox" wire:model.live="hideSubstitutions">
            Скрывать замены/аналоги (легитимные)
        </label>
    </div>

    <div class="ds-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-[12px]" style="border-collapse: collapse;">
                <thead>
                    <tr class="bg-surface-2 text-[10.5px] uppercase tracking-wider text-fg-3">
                        <th class="text-left px-3 py-2" style="width:120px">Заявка</th>
                        <th class="text-left px-3 py-2">Позиция заявки</th>
                        <th class="text-left px-3 py-2">🖥 Система сматчила</th>
                        <th class="text-left px-3 py-2">📄 В КП (истина)</th>
                        <th class="px-3 py-2" style="width:110px"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->rows as $row)
                        <tr wire:key="mismatch-{{ $row->oqi_id }}" class="border-t border-border-subtle align-top">
                            <td class="px-3 py-2">
                                <a href="{{ route('requests.show', $row->request_id) }}" target="_blank" rel="noopener"
                                   class="text-sky-700 hover:underline font-medium mono">{{ $row->internal_code }}</a>
                                <div class="text-[10px] text-fg-3 mt-0.5">поз. {{ $row->position }}@if($row->kp_no) · КП {{ $row->kp_no }}@endif</div>
                            </td>
                            <td class="px-3 py-2">
                                <div class="text-fg-1">{{ \Illuminate\Support\Str::limit($row->parsed_name, 60) }}</div>
                                @if($row->parsed_article)<div class="text-[11px] text-fg-3 mono mt-0.5">{{ $row->parsed_article }}</div>@endif
                            </td>
                            <td class="px-3 py-2" style="background:#fef2f2;">
                                <div class="mono font-semibold" style="color:#991b1b;">{{ $row->sys_sku ?: '—' }}</div>
                                <div class="text-fg-2 text-[11.5px]">{{ \Illuminate\Support\Str::limit($row->sys_name, 60) }}</div>
                            </td>
                            <td class="px-3 py-2" style="background:#ecfdf5;">
                                <div class="mono font-semibold" style="color:#065f46;">{{ $row->kp_sku ?: '—' }}</div>
                                <div class="text-fg-2 text-[11.5px]">{{ \Illuminate\Support\Str::limit($row->kp_name, 60) }}</div>
                                @if($row->is_analog)<span class="chip chip-neutral text-[9.5px] mt-1">аналог</span>@endif
                            </td>
                            <td class="px-3 py-2 text-center">
                                <button type="button"
                                        wire:click="applyKpCatalog({{ $row->request_item_id }}, {{ $row->kp_catalog_id }})"
                                        wire:confirm="Переставить каталог позиции «{{ \Illuminate\Support\Str::limit($row->parsed_name, 30) }}» на {{ $row->kp_sku }} (как в КП)?"
                                        class="btn btn-sm btn-primary whitespace-nowrap"
                                        title="Привязать позицию к каталогу из КП">✓ Исправить</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center py-8 text-fg-3">Расхождений не найдено {{ trim($search) !== '' ? 'по фильтру' : '' }} 🎉</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $this->rows->links() }}
    </div>
</div>
