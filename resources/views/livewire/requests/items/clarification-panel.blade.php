<div class="mt-3 sticky bottom-0 z-20"
     wire:key="clarification-panel-{{ $requestId }}">
    @php
        $pending = $this->pendingCount;
    @endphp

    {{-- Thin info-bar: всегда виден внизу страницы. Не expand'ит по
         клику на сам бар — только по кнопке «Предпросмотр».
         Если pending = 0 — мягкий приветственный hint. --}}
    <div class="border border-border bg-surface rounded-md px-3 py-1.5 flex items-center gap-2 text-[12px] shadow-[0_-4px_14px_-6px_rgba(0,0,0,0.1)] {{ $pending > 0 ? 'border-amber-300 bg-amber-50/70' : '' }}">
        <span class="text-[14px] leading-none">{{ $pending > 0 ? '✎' : '❓' }}</span>
        @if($pending > 0)
            <span class="font-semibold text-amber-900">
                В черновике уточнений: {{ $pending }} {{ \Illuminate\Support\Str::plural('вопрос', $pending) }}
            </span>
        @else
            <span class="text-fg-3">
                Чтобы спросить клиента — раскройте позицию (▸) и введите вопрос
            </span>
        @endif
        <span class="flex-1"></span>

        <button type="button"
                wire:click="toggle"
                class="btn btn-sm"
                title="{{ $expanded ? 'Свернуть черновик' : 'Открыть редактор черновика — общий вопрос + правка всех вопросов' }}">
            {{ $expanded ? '▾ Свернуть' : '👁 Предпросмотр' }}
        </button>

        @if($pending > 0)
            <button type="button"
                    wire:click="formLetter"
                    class="btn btn-sm btn-primary"
                    wire:loading.attr="disabled" wire:target="formLetter">
                <span wire:loading.remove wire:target="formLetter">📨 Сформировать ({{ $pending }})</span>
                <span wire:loading wire:target="formLetter">…</span>
            </button>
        @endif
    </div>

    @if($expanded)
        <div class="ds-card mt-2 shadow-[0_-4px_18px_-6px_rgba(0,0,0,0.12)]">
        <div class="ds-card-body space-y-3">
            @if(! $canCompose)
                <div class="text-[12.5px] text-fg-3 p-3 bg-surface-2 rounded-md">
                    Составлять уточнения может только assigned-менеджер или acting на время отсутствия. Если у вас включён head_of_sales / director — кнопка доступна.
                </div>
            @else
                <div class="text-[12px] text-fg-3 mb-2">
                    Введите вопросы по конкретным позициям и/или общий. После «Сформировать письмо» откроется черновик, его можно отредактировать перед отправкой. Заявка автоматически перейдёт в «Жду уточнение клиента».
                </div>

                {{-- Общий вопрос --}}
                <div>
                    <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                        Общий вопрос
                    </label>
                    <textarea wire:model.live.debounce.500ms="generalQuestion"
                              rows="2" maxlength="2000"
                              placeholder="Например: уточните, пожалуйста, бренд оборудования и серию лифта"
                              class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-[var(--sky-500)] resize-y"></textarea>
                </div>

                {{-- Per-item --}}
                @if($items->isEmpty())
                    <div class="text-[12px] text-fg-3 italic">Позиций в заявке нет — введите только общий вопрос.</div>
                @else
                    <div>
                        <div class="text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-2">
                            Вопросы по конкретным позициям
                        </div>
                        <div class="space-y-2">
                            @foreach($items as $item)
                                @php
                                    $key = $item->id;
                                    $cur = (string) ($perItem[$key] ?? '');
                                    $hasQ = trim($cur) !== '';
                                @endphp
                                <div class="p-2 rounded-md {{ $hasQ ? 'bg-amber-50' : 'bg-surface-2' }}">
                                    <div class="flex items-baseline gap-1.5 mb-1.5 text-[12.5px] text-fg-1 leading-snug flex-wrap">
                                        <span class="mono text-fg-3 text-[11px] shrink-0">#{{ $item->position }}</span>
                                        <span class="font-medium">{{ $item->parsed_name ?: '(без названия)' }}</span>
                                        @if($item->parsed_brand)
                                            <span class="inline-flex items-center px-1.5 rounded-sm bg-emerald-50 text-emerald-800 font-semibold text-[10.5px]">{{ $item->parsed_brand }}</span>
                                        @endif
                                        @if($item->parsed_article)
                                            <span class="text-[11px] text-fg-3 mono">арт. {{ $item->parsed_article }}</span>
                                        @endif
                                        @if($item->parsed_qty)
                                            <span class="text-[11px] text-fg-3">· {{ rtrim(rtrim((string) $item->parsed_qty, '0'), '.') }} {{ $item->parsed_unit ?: 'шт.' }}</span>
                                        @endif
                                    </div>
                                    <textarea wire:model.live.debounce.500ms="perItem.{{ $item->id }}"
                                              rows="2" maxlength="1000"
                                              placeholder="Вопрос по этой позиции (например: пришлите фото шильдика)"
                                              class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-[var(--sky-500)] resize-y"></textarea>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @error('generalQuestion')
                    <div class="text-red-700 text-[12px]">{{ $message }}</div>
                @enderror

                <div class="flex items-center gap-2 pt-2 border-t border-border-subtle">
                    <button type="button"
                            wire:click="formLetter"
                            class="btn btn-primary"
                            @disabled($pending === 0)
                            wire:loading.attr="disabled" wire:target="formLetter">
                        <span wire:loading.remove wire:target="formLetter">📨 Сформировать письмо ({{ $pending }})</span>
                        <span wire:loading wire:target="formLetter">Формируем черновик…</span>
                    </button>
                    <span class="text-[11.5px] text-fg-3">
                        Черновик откроется во вкладке «Переписка» — отредактируйте и отправьте.
                    </span>
                </div>
            @endif
        </div>
        </div>
    @endif
</div>
