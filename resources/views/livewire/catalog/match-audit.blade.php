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
                            <td class="px-3 py-2 text-center whitespace-nowrap">
                                <button type="button"
                                        wire:click="openCompare({{ $row->request_item_id }}, {{ $row->sys_catalog_id }}, {{ $row->kp_catalog_id }})"
                                        class="btn btn-sm mb-1"
                                        title="Полное сравнение: система vs КП">⚖️ Сравнить</button>
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

    {{-- ───────── Полное сравнение: система vs КП (тот же сервис, что «Похожее из каталога») ───────── --}}
    @php $cmp = $this->comparisonData; $subject = $this->compareSubject; @endphp
    @if($comparing && $cmp && $subject)
        @php
            $candidates = $cmp['candidates'] ?? [];
            $sections = $cmp['sections'] ?? [];
            $subjQty = $cmp['subjectQty'] ?? 0;
            $gridCols = '240px ' . str_repeat('260px ', count($candidates));
        @endphp
        <div style="position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.55); display: flex; align-items: flex-start; justify-content: center; padding: 24px 16px;"
             wire:click.self="closeCompare">
            <div class="ds-card w-full flex flex-col" style="max-width: 1000px; max-height: calc(100vh - 48px);" wire:click.stop>
                <div class="ds-card-header shrink-0">
                    <h3 class="text-[15px] font-semibold text-fg-1">⚖️ Сравнение: система vs КП</h3>
                    <span class="text-[12px] text-fg-3 ml-2">{{ \Illuminate\Support\Str::limit($subject->parsed_name, 50) }}</span>
                    <span class="flex-1"></span>
                    <button type="button" wire:click="closeCompare" class="btn btn-sm">✕ Закрыть</button>
                </div>
                <div class="ds-card-body overflow-auto flex-1" style="min-height:0;">
                    <div class="border border-border rounded-md overflow-auto">
                        <div class="grid" style="grid-template-columns: {{ $gridCols }}; width: max-content; min-width: 100%;">
                            {{-- subject header --}}
                            <div class="border-b bg-sky-50 p-3" style="position: sticky; left:0; top:0; z-index:5; border-right:2px solid var(--sky-500);">
                                <div class="text-[10.5px] uppercase tracking-wider text-sky-700 font-semibold mb-1.5">Позиция заявки</div>
                                <div class="font-semibold text-[13px] text-sky-700 leading-tight mb-1">{{ $subject->parsed_name ?: '(без названия)' }}</div>
                                <div class="flex items-center gap-2 text-[11px] text-fg-3 flex-wrap">
                                    @if($subject->brand?->name ?? $subject->parsed_brand)<span class="font-semibold text-[10px] bg-neutral-100 text-neutral-700 px-1.5 py-0.5 rounded uppercase">{{ $subject->brand?->name ?? $subject->parsed_brand }}</span>@endif
                                    @if($subject->parsed_article)<span class="mono text-fg-2">{{ $subject->parsed_article }}</span>@endif
                                </div>
                                @if($subjQty > 0)<div class="mt-1.5 text-[11px] text-sky-700">{{ $subjQty }} шт.</div>@endif
                            </div>
                            {{-- candidate headers --}}
                            @foreach($candidates as $idx => $cm)
                                @php $c = $cm['catalog']; $isSys = $c->id === $compareSysCatId; @endphp
                                <div class="border-b border-r border-border p-3" style="position: sticky; top:0; z-index:3; {{ $isSys ? 'background:#fef2f2;' : 'background:#ecfdf5;' }}">
                                    <div class="text-[10.5px] uppercase tracking-wider font-semibold mb-1.5" style="{{ $isSys ? 'color:#991b1b;' : 'color:#065f46;' }}">
                                        {{ $isSys ? '🖥 Система сматчила' : '📄 В КП (истина)' }}
                                        <span class="ml-1 mono bg-surface border border-border px-1 rounded normal-case text-fg-2">{{ $c->sku }}</span>
                                    </div>
                                    <div class="aspect-[1.6/1] rounded bg-app border border-border overflow-hidden mb-1.5">
                                        @if($c->photo_url)
                                            <a href="{{ $c->photo_url }}" target="_blank" rel="noopener"><img src="{{ route('catalog.photo', $c->id) }}" class="w-full h-full object-cover" loading="lazy" referrerpolicy="no-referrer"></a>
                                        @else<div class="w-full h-full flex items-center justify-center text-[10px] text-fg-3">нет фото</div>@endif
                                    </div>
                                    <div class="font-semibold text-[12.5px] text-fg-1 leading-tight mb-1">{{ $c->name }}</div>
                                    <div class="flex items-center gap-2 text-[11px] text-fg-3 flex-wrap mb-1.5">
                                        @if($c->brand)<span class="font-semibold text-[10px] bg-neutral-100 text-neutral-700 px-1.5 py-0.5 rounded uppercase">{{ $c->brand }}</span>@endif
                                        @if($c->brand_article)<span class="mono">{{ $c->brand_article }}</span>@endif
                                    </div>
                                    <div class="mono text-[12.5px] text-fg-1 mb-1.5">{{ $c->price !== null ? number_format((float)$c->price, 2, ',', ' ').' ₽' : '—' }}</div>
                                    <button type="button" wire:click="applyKpCatalog({{ $subject->id }}, {{ $c->id }})"
                                            wire:confirm="Привязать позицию к {{ $c->sku }}?"
                                            class="btn btn-sm w-full {{ $isSys ? '' : 'btn-primary' }}">✓ Привязать эту</button>
                                </div>
                            @endforeach

                            {{-- sections --}}
                            @foreach($sections as $section)
                                <div class="px-3 py-1.5 bg-neutral-100 border-b text-[11px] font-bold text-fg-1 uppercase tracking-wider" style="position: sticky; left:0; z-index:2; border-right:2px solid var(--sky-500);">{{ $section['title'] }}</div>
                                @for($i = 0; $i < count($candidates); $i++)<div class="bg-neutral-100 border-b border-r border-border"></div>@endfor
                                @foreach($section['rows'] as $row)
                                    @php $s = $row['subject']; @endphp
                                    <div class="px-3 py-2 border-b bg-sky-50 text-[12px]" style="position: sticky; left:0; z-index:2; border-right:2px solid var(--sky-500);">
                                        <div class="text-[10px] uppercase tracking-wider text-sky-700 font-semibold mb-0.5">{{ $row['label'] }}@if($row['sublabel'])<span class="normal-case font-normal text-fg-3"> {{ $row['sublabel'] }}</span>@endif</div>
                                        @if($s['status'] === 'empty')<span class="text-fg-3 italic">{{ $s['value'] }}</span>
                                        @else<span class="text-fg-1 {{ !empty($s['mono']) ? 'mono' : '' }}">{{ $s['value'] }}</span>@endif
                                        @if(!empty($s['sub']))<small class="block text-[10px] text-fg-3 italic mt-0.5">{{ $s['sub'] }}</small>@endif
                                    </div>
                                    @foreach($row['cells'] as $cell)
                                        @php $cls = match($cell['status'] ?? ''){'diff'=>'text-amber-700','bad'=>'text-red-700','empty'=>'text-fg-3 italic',default=>'text-fg-1'}; $isMatch = ($cell['status'] ?? '')==='match'; @endphp
                                        <div class="px-3 py-2 border-b border-r border-border bg-surface text-[12px] relative">
                                            @if($isMatch)<span class="absolute left-1 top-2 text-emerald-700 font-bold text-[11px]">✓</span>@endif
                                            <span class="{{ $cls }} {{ !empty($cell['mono'])?'mono':'' }} {{ !empty($cell['bold'])?'font-semibold':'' }} {{ $isMatch ? 'ml-3' : '' }}">{{ $cell['value'] }}</span>
                                            @if(!empty($cell['sub']))<small class="block text-[10px] text-fg-3 italic mt-0.5">{{ $cell['sub'] }}</small>@endif
                                        </div>
                                    @endforeach
                                @endforeach
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
