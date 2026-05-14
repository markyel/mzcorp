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
                    Вопросы по позициям задаются прямо в карточках (раскройте позицию ▸). Здесь — общий вопрос и предпросмотр готового письма.
                </div>

                {{-- Общий вопрос (нет inline-эквивалента в карточке) --}}
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold">
                            Общий вопрос
                        </label>
                        @if(trim($generalQuestion) !== '')
                            <button type="button"
                                    wire:click="clearGeneralQuestion"
                                    class="text-[10.5px] text-red-600 hover:underline">очистить</button>
                        @endif
                    </div>
                    <textarea wire:model.live.debounce.500ms="generalQuestion"
                              rows="2" maxlength="2000"
                              placeholder="Например: уточните, пожалуйста, бренд оборудования и серию лифта"
                              class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-[var(--sky-500)] resize-y"></textarea>
                </div>

                {{-- Список добавленных per-item вопросов (read-only, с ✕ для очистки) --}}
                @php
                    $perItemNonEmpty = collect($perItem)
                        ->filter(fn ($q) => trim((string) $q) !== '')
                        ->mapWithKeys(fn ($q, $id) => [(int) $id => $q]);
                @endphp
                @if($perItemNonEmpty->isNotEmpty())
                    <div>
                        <div class="text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1.5">
                            Вопросы по позициям ({{ $perItemNonEmpty->count() }})
                        </div>
                        <div class="space-y-1">
                            @foreach($items as $item)
                                @continue(! $perItemNonEmpty->has($item->id))
                                <div class="flex items-start gap-2 p-2 rounded-md bg-amber-50 border border-amber-200">
                                    <span class="mono text-fg-3 text-[11px] shrink-0 mt-0.5">#{{ $item->position }}</span>
                                    <div class="flex-1 min-w-0 text-[12.5px]">
                                        <div class="font-medium text-fg-1 mb-0.5">{{ $item->parsed_name ?: '(без названия)' }}</div>
                                        <div class="text-fg-2 whitespace-pre-line">{{ $perItemNonEmpty[$item->id] }}</div>
                                    </div>
                                    <button type="button"
                                            wire:click="clearItemQuestion({{ $item->id }})"
                                            class="text-red-600 hover:text-red-800 text-[14px] leading-none px-1 shrink-0"
                                            title="Убрать этот вопрос из черновика">✕</button>
                                </div>
                            @endforeach
                        </div>
                        <div class="text-[11px] text-fg-3 mt-1.5">
                            Чтобы изменить — закройте предпросмотр и отредактируйте текст в карточке позиции.
                        </div>
                    </div>
                @endif

                @error('generalQuestion')
                    <div class="text-red-700 text-[12px]">{{ $message }}</div>
                @enderror

                {{-- Read-only preview готового текста письма --}}
                <div>
                    <div class="text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1.5">
                        Так увидит клиент
                    </div>
                    <pre class="text-[12.5px] font-mono text-fg-1 bg-surface-2 border border-border rounded-md p-3 whitespace-pre-wrap leading-snug max-h-[280px] overflow-auto">{{ $this->previewBody }}</pre>
                </div>

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
