{{-- Compose / Reply / Reply-all — ПЛАВАЮЩЕЕ окно (2026-07-09).
     Раньше форма была inline внизу таба «Переписка» — не очевидно, что надо
     листать вниз, и нельзя смотреть другие вкладки пока пишешь. Теперь окно
     фиксировано поверх страницы: перетаскивается за заголовок, растягивается
     за уголок, сворачивается в титульную строку. Компонент зарегистрирован в
     detail.blade ВНЕ @switch табов — переживает переключение вкладок.

     Геометрия (x/y/w/h) — в Alpine-данных и применяется через :style, а не
     через нативный CSS resize: инлайновый style, который меняет браузер,
     Livewire-morph затирал бы при каждом autosave. Alpine-состояние morph
     переживает. Все новые стили — inline (прод не пересобирает Tailwind). --}}
<div class="compose-form">
    @if($open)
        {{-- x-teleport="body" ОБЯЗАТЕЛЕН: у предков в layout есть transform,
             и position:fixed позиционируется относительно него, а не viewport
             — окно «уезжало в подвал» страницы (баг-репорт 2026-07-09).
             Телепорт в body делает fixed честным оверлеем. --}}
        <template x-teleport="body">
        <div x-data="{
                min: false,
                x: null, y: null,
                w: Math.min(720, window.innerWidth - 32),
                h: Math.min(640, window.innerHeight - 110),
                // ВАЖНО: возвращаем ОБЪЕКТ, не строку. Alpine :style со строкой
                // ЗАМЕНЯЕТ весь атрибут style (стирая position:fixed из статичного
                // style → окно падало в поток документа, «в подвал»); объектный
                // синтаксис мержит по-свойственно. Неиспользуемые якоря сбрасываем
                // в 'auto' явно — иначе после перетаскивания останутся оба.
                styleStr() {
                    const s = { width: this.w + 'px', height: this.min ? 'auto' : this.h + 'px' };
                    if (this.x === null) {
                        s.right = '16px'; s.bottom = '16px'; s.left = 'auto'; s.top = 'auto';
                    } else {
                        s.left = this.x + 'px'; s.top = this.y + 'px'; s.right = 'auto'; s.bottom = 'auto';
                    }
                    return s;
                },
                startDrag(e) {
                    if (e.button !== undefined && e.button !== 0) return;
                    const r = this.$refs.win.getBoundingClientRect();
                    this.x = r.left; this.y = r.top;
                    const ox = e.clientX - r.left, oy = e.clientY - r.top;
                    const move = ev => {
                        this.x = Math.min(Math.max(ev.clientX - ox, 60 - this.w), window.innerWidth - 100);
                        this.y = Math.min(Math.max(ev.clientY - oy, 0), window.innerHeight - 44);
                    };
                    const up = () => {
                        window.removeEventListener('pointermove', move);
                        window.removeEventListener('pointerup', up);
                    };
                    window.addEventListener('pointermove', move);
                    window.addEventListener('pointerup', up);
                },
                startResize(e) {
                    e.preventDefault();
                    const r = this.$refs.win.getBoundingClientRect();
                    if (this.x === null) { this.x = r.left; this.y = r.top; }
                    const sw = this.w, sh = this.h, sx = e.clientX, sy = e.clientY;
                    const move = ev => {
                        this.w = Math.min(Math.max(360, sw + (ev.clientX - sx)), window.innerWidth - 8);
                        this.h = Math.min(Math.max(300, sh + (ev.clientY - sy)), window.innerHeight - 8);
                    };
                    const up = () => {
                        window.removeEventListener('pointermove', move);
                        window.removeEventListener('pointerup', up);
                    };
                    window.addEventListener('pointermove', move);
                    window.addEventListener('pointerup', up);
                }
             }"
             x-ref="win"
             :style="styleStr()"
             style="position: fixed; z-index: 60; display: flex; flex-direction: column;
                    max-width: calc(100vw - 8px); max-height: calc(100vh - 8px);
                    background: var(--bg-surface); border: 1px solid var(--border-strong);
                    border-radius: 10px; box-shadow: 0 18px 50px rgba(15, 23, 42, 0.3); overflow: hidden;">

            {{-- Титульная строка: drag-handle + свернуть/закрыть. --}}
            <div @pointerdown="startDrag($event)"
                 style="cursor: move; user-select: none; touch-action: none; flex: 0 0 auto;
                        display: flex; align-items: center; gap: 8px; padding: 8px 12px;
                        background: var(--bg-surface-2); border-bottom: 1px solid var(--border-subtle);">
                <span class="text-[13px] font-semibold text-fg-1" style="pointer-events: none;">
                    ✉
                    @switch($mode)
                        @case('reply') Ответ @break
                        @case('reply_all') Ответ всем @break
                        @default Новое сообщение
                    @endswitch
                </span>
                <span class="text-[11px] text-fg-3 truncate" style="pointer-events: none;">
                    · перетащите за заголовок, растяните за угол
                </span>
                <span style="flex: 1;"></span>
                <button type="button" @pointerdown.stop x-on:click="min = !min"
                        class="text-fg-3 hover:text-fg-1 text-[13px]"
                        style="padding: 2px 7px; line-height: 1;"
                        :title="min ? 'Развернуть окно' : 'Свернуть в строку'">
                    <span x-show="!min">▁</span><span x-show="min" x-cloak>▢</span>
                </button>
                <button type="button" @pointerdown.stop wire:click="close"
                        class="text-fg-3 hover:text-fg-1 text-[13px]"
                        style="padding: 2px 7px; line-height: 1;"
                        title="Закрыть — черновик сохранится (бейдж в переписке)">✕</button>
            </div>

            {{-- Содержимое (скрывается при сворачивании). --}}
            <div x-show="!min"
                 style="flex: 1 1 auto; overflow: auto; display: flex; flex-direction: column;
                        gap: 10px; padding: 12px 14px; background: var(--bg-surface-2);">

                {{-- От: --}}
                <div class="flex items-center gap-2 text-[12px]" style="flex: 0 0 auto;">
                    <span class="text-fg-3 uppercase tracking-wider font-semibold w-[60px]">От:</span>
                    <span class="text-fg-1 mono">{{ $this->mailboxLabel ?? '—' }}</span>
                </div>

                {{-- Кому --}}
                <div style="flex: 0 0 auto;">
                    <div class="flex items-start gap-2">
                        <label class="text-[12px] text-fg-3 uppercase tracking-wider font-semibold w-[60px] pt-1.5">Кому</label>
                        <input type="text" wire:model.live.debounce.1500ms="toRaw"
                               class="flex-1 h-[32px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]"
                               placeholder="email@клиента; ещё@клиент.ru" />
                    </div>
                    @error('toRaw') <div class="text-red-700 text-[12px] ml-[68px]">{{ $message }}</div> @enderror
                </div>

                {{-- Cc --}}
                <div class="flex items-start gap-2" style="flex: 0 0 auto;">
                    <label class="text-[12px] text-fg-3 uppercase tracking-wider font-semibold w-[60px] pt-1.5">Cc</label>
                    <input type="text" wire:model.live.debounce.1500ms="ccRaw"
                           class="flex-1 h-[32px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]"
                           placeholder="(опционально)" />
                </div>

                {{-- Тема --}}
                <div style="flex: 0 0 auto;">
                    <div class="flex items-start gap-2">
                        <label class="text-[12px] text-fg-3 uppercase tracking-wider font-semibold w-[60px] pt-1.5">Тема</label>
                        <input type="text" wire:model.live.debounce.1500ms="subject"
                               class="flex-1 h-[32px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]" />
                    </div>
                    @error('subject') <div class="text-red-700 text-[12px] ml-[68px]">{{ $message }}</div> @enderror
                </div>

                {{-- Body: тянется на всю свободную высоту окна (подпись и цитата
                     автоматически добавятся при отправке, см.
                     OutgoingMailMimeBuilder::composeFinalBody). Кнопка «Шаблоны» —
                     плавающее меню в правом верхнем углу поля (в стиле Gmail). --}}
                <div style="position: relative; flex: 1 1 auto; display: flex; flex-direction: column; min-height: 150px;">
                    {{-- Меню «Шаблоны»: вставить / сохранить как / управление. --}}
                    <div x-data="{ open: false, saving: false, tplName: '', tplParent: '' }"
                         @click.outside="open = false; saving = false"
                         style="position: absolute; top: 6px; right: 6px; z-index: 6;">
                        <button type="button"
                                x-on:click="open = !open; saving = false"
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md border border-border bg-surface text-[12px] text-fg-2 hover:text-fg-1 hover:border-border-strong shadow-sm"
                                title="Шаблоны писем">
                            📄 Шаблоны <span class="text-[10px]">▾</span>
                        </button>

                        <div x-show="open" x-cloak x-transition
                             style="position: absolute; top: calc(100% + 4px); right: 0; z-index: 8;
                                    width: 300px; background: var(--bg-surface); border: 1px solid var(--border-strong);
                                    border-radius: 8px; box-shadow: 0 12px 32px rgba(15,23,42,0.25); padding: 6px;">
                            {{-- Основное меню. --}}
                            <div x-show="!saving">
                                <button type="button"
                                        wire:click="$dispatch('open-template-picker', { requestId: {{ $requestId }} })"
                                        x-on:click="open = false"
                                        class="w-full text-left flex items-center gap-2 px-2.5 py-2 rounded hover:bg-surface-2 text-[13px] text-fg-1">
                                    <span>↧</span> Вставить шаблон
                                </button>
                                <button type="button"
                                        x-on:click="saving = true"
                                        class="w-full text-left flex items-center gap-2 px-2.5 py-2 rounded hover:bg-surface-2 text-[13px] text-fg-1">
                                    <span>☆</span> Сохранить как шаблон
                                </button>
                                <div class="my-1 border-t border-border-subtle"></div>
                                <a href="{{ route('letter-templates.index') }}" target="_blank" rel="noopener"
                                   class="w-full text-left flex items-center gap-2 px-2.5 py-2 rounded hover:bg-surface-2 text-[13px] text-[var(--sky-700)]"
                                   title="Открыть управление библиотекой шаблонов в новой вкладке">
                                    <span>⚙</span> Управление шаблонами →
                                </a>
                            </div>

                            {{-- Подпанель «Сохранить как шаблон». --}}
                            <div x-show="saving" x-cloak>
                                <div class="flex items-center gap-2 px-1 pb-2">
                                    <button type="button" x-on:click="saving = false" class="text-fg-3 hover:text-fg-1 text-[15px] leading-none" title="Назад">‹</button>
                                    <span class="text-[12px] text-fg-3 uppercase tracking-wider font-semibold">Сохранить как шаблон</span>
                                </div>
                                <input type="text" x-model="tplName"
                                       placeholder="Название, напр.: Отказ по гарантии"
                                       class="w-full h-[32px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mb-2" />
                                <select x-model="tplParent"
                                        class="w-full h-[32px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mb-2">
                                    <option value="">— в корень —</option>
                                    @foreach($this->templateFolders as $folder)
                                        <option value="{{ $folder->id }}">{{ $folder->name }}</option>
                                    @endforeach
                                </select>
                                <div class="flex items-center gap-2">
                                    <button type="button" class="btn btn-primary btn-sm"
                                            x-on:click="$wire.saveAsTemplate(tplName, tplParent === '' ? null : parseInt(tplParent)); open = false; saving = false; tplName = ''; tplParent = '';">Сохранить</button>
                                    <button type="button" class="btn btn-sm" x-on:click="saving = false">Отмена</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <textarea wire:model.live.debounce.1500ms="bodyText"
                              placeholder="Напишите ответ клиенту обычным текстом…"
                              class="w-full px-3 py-2 border border-border-strong rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]"
                              style="font-family: var(--font-sans); line-height: 1.55; flex: 1 1 auto; resize: none; min-height: 140px;"></textarea>
                    @error('bodyText') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>

                {{-- Preview: подпись + цитата исходного письма. Цитата — тот же
                     HTML, что уйдёт в письмо (MailQuoteBuilder), в sandbox-iframe:
                     выглядит как во вкладке «Переписка», стили письма изолированы. --}}
                @php
                    $sig = $this->signaturePreview;
                    $quoteHtml = $this->quotePreviewHtml;
                @endphp
                @if($sig || $quoteHtml)
                    <details class="border border-border rounded-md bg-surface" style="flex: 0 0 auto;">
                        <summary class="cursor-pointer px-3 py-2 text-[12px] text-fg-3 select-none hover:bg-surface-2">
                            При отправке к письму добавятся:
                            @if($sig) <span class="text-fg-1 font-medium">подпись</span> @endif
                            @if($sig && $quoteHtml) <span>+</span> @endif
                            @if($quoteHtml) <span class="text-fg-1 font-medium">цитата исходного письма</span> @endif
                            <span> · нажмите чтобы посмотреть</span>
                        </summary>
                        <div class="border-t border-border-subtle">
                            @if($sig)
                                <div class="px-3 py-2 text-[12px] text-fg-2 border-b border-border-subtle bg-surface-2">
                                    <div class="text-[11px] text-fg-3 uppercase tracking-wider mb-1 font-semibold">Подпись</div>
                                    <pre class="whitespace-pre-wrap font-sans m-0 text-fg-1">{{ $sig }}</pre>
                                </div>
                            @endif
                            @if($quoteHtml)
                                <div class="px-3 py-2">
                                    <div class="text-[11px] text-fg-3 uppercase tracking-wider mb-1.5 font-semibold">Цитата исходного письма</div>
                                    <iframe sandbox="" srcdoc="{{ $quoteHtml }}"
                                            style="width: 100%; height: 240px; border: 1px solid var(--border-subtle);
                                                   border-radius: 6px; background: #fff; display: block;"></iframe>
                                </div>
                            @endif
                        </div>
                    </details>
                @endif

                {{-- Attachments --}}
                <div style="flex: 0 0 auto;">
                    <div class="text-[12px] text-fg-3 uppercase tracking-wider font-semibold mb-1.5">
                        Вложения
                        @php $atts = $this->attachments; @endphp
                        @if($atts->isNotEmpty())
                            <span class="text-fg-1 ml-1">({{ $atts->count() }})</span>
                        @endif
                    </div>

                    {{-- Список уже прикреплённых файлов. --}}
                    @if($atts->isNotEmpty())
                        <div class="flex flex-wrap gap-2 mb-2">
                            @foreach($atts as $att)
                                <span class="att inline-flex items-center gap-2 px-2.5 py-1.5 border border-border rounded-md bg-surface text-[12px]"
                                      wire:key="att-{{ $att->id }}">
                                    <span class="inline-block w-4 h-5 bg-red-50 border border-red-300 rounded-sm text-red-700 text-[7px] font-bold text-center leading-5">
                                        {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::afterLast($att->filename, '.')) ?: 'FILE' }}
                                    </span>
                                    <span class="text-fg-1 max-w-[260px] truncate" title="{{ $att->filename }}">{{ $att->filename }}</span>
                                    <span class="text-fg-3">· {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>
                                    <button type="button"
                                            wire:click="removeAttachment({{ $att->id }})"
                                            wire:confirm="Удалить вложение {{ $att->filename }}?"
                                            class="text-red-700 hover:text-red-900 ml-1 text-[14px] leading-none"
                                            title="Удалить">×</button>
                                </span>
                            @endforeach
                        </div>
                    @endif

                    {{-- Drop-zone: добавить файлы кликом или drag&drop.
                         Прикрепляется автоматически после выбора
                         (см. ComposeForm::updatedNewFiles). --}}
                    <div x-data="{
                            isDragging: false,
                            handleDrop(event) {
                                this.isDragging = false;
                                const dropped = event.dataTransfer?.files;
                                if (!dropped || dropped.length === 0) return;
                                const input = $refs.fileInput;
                                // НЕ мерджим с input.files. Каждый drop —
                                // самостоятельная операция: backend сохранит
                                // эти файлы как EmailAttachment, потом сбросит
                                // $newFiles. Если мерджить — старые файлы
                                // (которые DOM не очистил после прошлого
                                // upload'а) уйдут повторно и создадут дубли.
                                const dt = new DataTransfer();
                                for (const f of dropped) dt.items.add(f);
                                input.files = dt.files;
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                        }"
                        @dragenter.prevent="isDragging = true"
                        @dragover.prevent="isDragging = true"
                        @dragleave.prevent="isDragging = false"
                        @drop.prevent="handleDrop($event)"
                        @attachments-uploaded.window="if ($refs.fileInput) { $refs.fileInput.value = ''; }"
                        :class="isDragging ? 'border-[var(--sky-500)] bg-[var(--sky-50)]' : 'border-border'"
                        class="border border-dashed rounded-md px-3 py-3 transition-colors cursor-pointer"
                        @click="$refs.fileInput.click()">
                        <input type="file" wire:model="newFiles" multiple
                               x-ref="fileInput"
                               class="hidden" />
                        <div class="flex items-center gap-2 text-[12px] pointer-events-none">
                            <span class="text-fg-2">📎</span>
                            <span class="text-fg-1" x-show="!isDragging">
                                Перетащите файлы сюда или
                                <span class="underline text-fg-1">нажмите для выбора</span>
                            </span>
                            <span class="text-[var(--sky-700)] font-medium" x-show="isDragging" x-cloak>
                                Отпустите, чтобы прикрепить
                            </span>
                            <span class="flex-1"></span>
                            <span wire:loading wire:target="newFiles,uploadAttachments" class="text-amber-700">📎 Загружаем…</span>
                            @if($atts->isEmpty())
                                <span wire:loading.remove wire:target="newFiles,uploadAttachments" class="text-fg-3">до 25 МБ/файл</span>
                            @endif
                        </div>
                    </div>
                    @error('newFiles.*') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>
            </div>

            {{-- Footer --}}
            <div x-show="!min"
                 style="flex: 0 0 auto; display: flex; align-items: center; flex-wrap: wrap; gap: 8px;
                        padding: 10px 14px; background: var(--bg-surface-2);
                        border-top: 1px solid var(--border-subtle);">
                <button type="button" wire:click="send" class="btn btn-primary"
                        wire:loading.attr="disabled" wire:target="send">
                    <span wire:loading.remove wire:target="send">Отправить</span>
                    <span wire:loading wire:target="send">Отправляем…</span>
                </button>
                <button type="button" wire:click="discard" class="btn"
                        wire:confirm="Удалить черновик?">Удалить черновик</button>

                <span class="text-fg-3 text-[12px] ml-auto">
                    Автосохранение
                    <span wire:loading wire:target="updatedSubject,updatedToRaw,updatedCcRaw,updatedBodyText" class="text-amber-700">…</span>
                </span>
            </div>

            {{-- Уголок-ручка для растягивания. --}}
            <div x-show="!min" @pointerdown="startResize($event)"
                 style="position: absolute; right: 0; bottom: 0; width: 20px; height: 20px;
                        cursor: nwse-resize; touch-action: none; display: flex;
                        align-items: flex-end; justify-content: flex-end;
                        padding: 0 3px 1px 0; color: var(--fg-3); font-size: 11px;
                        user-select: none; line-height: 1;"
                 title="Растянуть окно">◢</div>
        </div>
        </template>
    @endif
</div>
