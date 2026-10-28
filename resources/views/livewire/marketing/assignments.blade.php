@php
    $inp = 'w-full h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500';
    $area = 'w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500';
    $a = $this->open;
    $statusChip = [
        'draft' => 'background:var(--neutral-100);color:var(--fg-3)',
        'handed' => 'background:var(--sky-50);color:var(--sky-700)',
        'in_progress' => 'background:var(--amber-100);color:var(--amber-800)',
        'done' => 'background:var(--emerald-50);color:var(--emerald-700)',
        'cancelled' => 'background:var(--neutral-100);color:var(--fg-4)',
    ];
@endphp

<div class="space-y-4">
    <style>
        .assign-preview h2 { font-size: 14px; font-weight: 600; margin: 14px 0 4px; color: var(--fg-1); }
        .assign-preview h3 { font-size: 13px; font-weight: 600; margin: 10px 0 4px; }
        .assign-preview p { margin: 0 0 6px; }
        .assign-preview ul { list-style: disc; padding-left: 20px; margin: 0 0 6px; }
        .assign-preview ol { list-style: decimal; padding-left: 20px; margin: 0 0 6px; }
        .assign-preview code { font-family: var(--font-mono, monospace); background: var(--neutral-100); padding: 0 3px; border-radius: 3px; }
    </style>

    @if($flash || $error)
        <div class="sticky top-0 z-20">
            @if($flash)
                <div class="ds-card"><div class="ds-card-body text-[13px] text-emerald-700">{{ $flash }}</div></div>
            @endif
            @if($error)
                <div class="ds-card"><div class="ds-card-body text-[13px] text-amber-800">{{ $error }}</div></div>
            @endif
        </div>
    @endif

    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">📋 Поручения</h3>
            <span class="text-[12px] text-fg-3">задачи соседним отделам: инструкция и файлы выгружаются архивом, статус ведём здесь</span>
            <span class="flex-1"></span>
            <label class="text-[12px] text-fg-3 flex items-center gap-1">
                <input type="checkbox" wire:model.live="showClosed"> закрытые
            </label>
            <button type="button" class="btn btn-sm btn-primary" wire:click="startNew">Новое поручение</button>
        </div>

        @if($newForm)
            <div class="ds-card-body border-b border-border-subtle space-y-2">
                <input type="text" wire:model="newTitle" placeholder="Что сделать — коротко" class="{{ $inp }}">
                <div class="grid gap-2 md:grid-cols-[1fr_auto]">
                    <input type="text" wire:model="newExecutor" placeholder="Кому: отдел или человек" class="{{ $inp }}">
                    <input type="date" wire:model="newDue" class="{{ $inp }}" title="Срок">
                </div>
                <textarea wire:model="newBrief" rows="4" class="{{ $area }}"
                          placeholder="Постановка своими словами: что нужно, где, какие адреса и тексты, что уже есть. Из этого пишется подробная инструкция."></textarea>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-sm btn-primary" wire:click="create">Завести</button>
                    <button type="button" class="btn btn-sm" wire:click="cancelNew">Отмена</button>
                </div>
            </div>
        @endif

        <div class="ds-card-body">
            @forelse($this->assignments as $row)
                <div class="flex flex-wrap items-baseline gap-2 py-2 border-b border-border-subtle last:border-b-0 {{ $openId === $row->id ? 'bg-[var(--sky-50)] px-2 rounded' : '' }}"
                     wire:key="as-{{ $row->id }}">
                    <span class="chip text-[10px]" style="{{ $statusChip[$row->status] ?? '' }}">{{ $row->statusLabel() }}</span>
                    @if($row->priority === 1)<span class="text-[11px] text-amber-800">● высокий</span>@endif
                    <button type="button" class="text-[13px] font-medium text-fg-1 hover:underline text-left" wire:click="openAssignment({{ $row->id }})">{{ $row->title }}</button>
                    @if($row->executor)<span class="text-[11.5px] text-fg-3">→ {{ $row->executor }}</span>@endif
                    <span class="flex-1"></span>
                    @if($row->files_count)<span class="text-[11.5px] text-fg-4">📎 {{ $row->files_count }}</span>@endif
                    @if($row->due_on)
                        <span class="text-[11.5px] mono {{ $row->isOverdue() ? 'text-amber-800' : 'text-fg-3' }}"
                              @if($row->isOverdue()) title="Срок прошёл" @endif>до {{ $row->due_on->format('d.m') }}</span>
                    @endif
                </div>
            @empty
                <p class="text-[12.5px] text-fg-3">Поручений нет. Заведите первое: что сделать, кому, срок — и постановку своими словами.</p>
            @endforelse
        </div>
    </div>

    @if($a)
        <div class="ds-card" wire:key="as-card-{{ $a->id }}">
            <div class="ds-card-header flex-wrap">
                <span class="chip text-[10px]" style="{{ $statusChip[$a->status] ?? '' }}">{{ $a->statusLabel() }}</span>
                <h3 class="text-[15px] font-semibold text-fg-1">№ {{ $a->id }} · {{ $a->title }}</h3>
                <span class="flex-1"></span>
                <button type="button" class="btn btn-sm" wire:click="closeAssignment">Закрыть</button>
            </div>

            <div class="ds-card-body space-y-3">
                <div>
                    <div class="text-[11.5px] text-fg-3 mb-0.5">Что сделать</div>
                    <input type="text" wire:model="title" class="{{ $inp }}">
                </div>
                <div class="grid gap-2 md:grid-cols-3">
                    <div>
                        <div class="text-[11.5px] text-fg-3 mb-0.5">Кому</div>
                        <input type="text" wire:model="executor" class="{{ $inp }}" placeholder="отдел / человек">
                    </div>
                    <div>
                        <div class="text-[11.5px] text-fg-3 mb-0.5">Контакт исполнителя</div>
                        <input type="text" wire:model="executorContact" class="{{ $inp }}" placeholder="почта, телефон">
                    </div>
                    <div class="grid gap-2 grid-cols-2">
                        <div>
                            <div class="text-[11.5px] text-fg-3 mb-0.5">Срок</div>
                            <input type="date" wire:model="due" class="{{ $inp }}">
                        </div>
                        <div>
                            <div class="text-[11.5px] text-fg-3 mb-0.5">Приоритет</div>
                            <select wire:model="priority" class="{{ $inp }}">
                                @foreach(\App\Models\MarketingAssignment::PRIORITIES as $p => $label)
                                    <option value="{{ $p }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="text-[11.5px] text-fg-3 mb-0.5">Постановка своими словами (исполнитель её не видит — из неё пишется инструкция)</div>
                    <textarea wire:model="brief" rows="4" class="{{ $area }}"></textarea>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn btn-sm" wire:click="write" wire:loading.attr="disabled" wire:target="write"
                            @if(trim($instructions) !== '') wire:confirm="Переписать инструкцию заново? Ручные правки в ней пропадут." @endif>
                        <span wire:loading.remove wire:target="write">✍ {{ trim($instructions) !== '' ? 'Переписать' : 'Расписать' }} инструкцию по постановке</span>
                        <span wire:loading wire:target="write">Пишу… до минуты</span>
                    </button>
                    <span class="text-[11.5px] text-fg-4">учитывает приложенные файлы; чего нет в постановке — помечает [уточнить]</span>
                </div>

                <div>
                    <div class="text-[11.5px] text-fg-3 mb-0.5">Инструкция исполнителю · markdown: «## заголовок», «- пункт», «1. шаг»</div>
                    <textarea wire:model="instructions" rows="18" class="{{ $area }} mono"></textarea>
                </div>

                @if($this->previewHtml !== '')
                    <details class="border border-border-subtle rounded-md px-3 py-2">
                        <summary class="cursor-pointer text-[12px] text-fg-3">Как увидит исполнитель (по сохранённой версии)</summary>
                        <div class="assign-preview text-[12.5px] text-fg-2 mt-2">{!! $this->previewHtml !!}</div>
                    </details>
                @endif

                {{-- Файлы --}}
                <div class="space-y-1.5">
                    <div class="text-[13px] font-semibold text-fg-1">📎 Материалы · {{ $a->files->count() }}</div>
                    @foreach($a->files as $f)
                        <div class="flex flex-wrap items-baseline gap-2 text-[12px]" wire:key="af-{{ $f->id }}">
                            <button type="button" class="text-sky-700 hover:underline" wire:click="downloadFile({{ $f->id }})">{{ $f->filename }}</button>
                            <span class="text-fg-4">{{ $f->sizeLabel() }}</span>
                            @if($f->note)<span class="text-fg-3">— {{ $f->note }}</span>@endif
                            <button type="button" class="text-[11.5px] text-fg-4 hover:text-red-700" wire:click="deleteFile({{ $f->id }})"
                                    wire:confirm="Убрать файл «{{ $f->filename }}»?">убрать</button>
                        </div>
                    @endforeach
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" wire:model="uploadNote" class="{{ $inp }} max-w-[280px]" placeholder="что это за файл (необязательно)">
                        <input type="file" wire:model="uploads" multiple class="text-[12px]">
                        <span wire:loading wire:target="uploads" class="text-[11.5px] text-fg-3">загружаю…</span>
                    </div>
                    <div class="text-[11px] text-fg-4">до 29 МБ на файл; пометку пишите до выбора файла — она встанет ко всем выбранным</div>
                </div>

                <div>
                    <div class="text-[11.5px] text-fg-3 mb-0.5">Итог: что сделано, ссылки, замечания при приёмке</div>
                    <textarea wire:model="resultNotes" rows="3" class="{{ $area }}"></textarea>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn btn-sm btn-primary" wire:click="save">Сохранить</button>
                    <button type="button" class="btn btn-sm" wire:click="exportZip" wire:loading.attr="disabled" wire:target="exportZip">⭳ Архив: инструкция + файлы</button>
                    <button type="button" class="btn btn-sm" wire:click="exportPdf" wire:loading.attr="disabled" wire:target="exportPdf">⭳ PDF</button>
                    <span class="flex-1"></span>
                    @foreach(['handed' => 'Передано', 'in_progress' => 'В работе', 'done' => 'Сделано', 'cancelled' => 'Снять'] as $st => $label)
                        @if($a->status !== $st)
                            <button type="button" class="btn btn-sm" wire:click="setStatus('{{ $st }}')">{{ $label }}</button>
                        @endif
                    @endforeach
                    <button type="button" class="btn btn-sm text-amber-800" wire:click="delete"
                            wire:confirm="Удалить поручение «{{ $a->title }}» вместе с файлами?">Удалить</button>
                </div>
                <div class="text-[11px] text-fg-4">
                    @if($a->handed_at)Передано {{ $a->handed_at->format('d.m.Y') }}. @endif
                    @if($a->done_at)Сделано {{ $a->done_at->format('d.m.Y') }}. @endif
                    Поставил {{ $a->author?->name ?? '—' }}, {{ $a->created_at->format('d.m.Y') }}.
                </div>
            </div>
        </div>
    @endif
</div>
