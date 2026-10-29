{{-- Письма на support@quoteparts.net (App\Livewire\QuoteParts\SupportRequests): кто писал, переписка, ответ с support@. --}}
<div class="ds-card mb-4" wire:init="load">
    {{-- Форма ответа: поля адресатов и рамка редактора — в духе композера «Почты» (сам редактор — x-mail.rich-editor). --}}
    <style>
    .qps-reply{position:relative;display:flex;flex-direction:column;gap:6px;margin-top:6px}
    .qps-fields{border:1px solid var(--border);border-radius:var(--r-md);padding:0 10px;background:var(--bg-surface)}
    .qps-row{display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid var(--border-subtle);font-size:12.5px}
    .qps-row:last-child{border-bottom:none}
    .qps-row .k{width:58px;color:var(--fg-3);font-weight:500;flex-shrink:0}
    .qps-row input{flex:1;border:none;outline:none;background:transparent;font:400 13px/1.3 var(--font-sans);color:var(--fg-1);min-width:60px}
    .qps-editor{border:1px solid var(--border);border-radius:var(--r-md);padding:8px 10px;background:var(--bg-surface)}
    .qps-editor .mail-rte{min-height:180px}
    .qps-editor .rte-ed{max-height:50vh}
    .qps-att{display:inline-flex;align-items:center;gap:6px;height:24px;padding:0 8px;border-radius:999px;background:var(--sky-50);color:var(--sky-700);font:500 11px/1 var(--font-sans)}
    .qps-att button{border:none;background:none;color:inherit;cursor:pointer;opacity:.7;font-size:12px}
    .qps-dropveil{position:absolute;inset:0;z-index:12;background:rgba(14,165,233,.08);border:2px dashed var(--sky-500);border-radius:8px;display:flex;align-items:center;justify-content:center;pointer-events:none}
    .qps-dropveil span{padding:8px 14px;border-radius:999px;background:var(--bg-surface);border:1px solid var(--sky-500);font:600 13px/1 var(--font-sans);color:var(--sky-700)}
    </style>
    <div class="ds-card-header">
        <h3>Письма в поддержку</h3>
        <span class="text-[12px] text-fg-3 ml-2">support@quoteparts.net · ответ уйдёт письмом от «QuoteParts Support»</span>
        @if($waitingCount > 0)<span class="chip chip-warn text-[10px] ml-2">ждут ответа: {{ $waitingCount }}</span>@endif
        <label class="ml-auto flex items-center gap-1.5 text-[12px] text-fg-3">
            <input type="checkbox" wire:model.live="withClosed"> и закрытые
        </label>
    </div>
    <div class="ds-card-body space-y-2 text-[13px]">
        @if($error)<div class="text-red-700 text-[12.5px]">{{ $error }}</div>@endif
        @if(! $loaded)
            <div class="text-fg-4 text-[12px]">Загружаю…</div>
        @elseif($rows->isEmpty())
            <div class="text-fg-4 text-[12px]">{{ $withClosed ? 'Писем в поддержку пока не было.' : 'Открытых писем нет.' }}</div>
        @endif

        @foreach($rows as $c)
            @php
                $who = ! empty($c['supplier']) ? ($names[$c['supplier']] ?? $c['supplier']) : null;
                $contact = trim(($c['contact_name'] ?? '').(! empty($c['contact_email']) ? ' <'.$c['contact_email'].'>' : ''));
            @endphp
            {{-- Письмо от поставщика видно сразу: полоса слева и плашка с его названием. --}}
            <div wire:key="qps-{{ $c['id'] }}" class="border border-border rounded-md" @if($who) style="border-left: 3px solid var(--status-info-fg)" @endif>
                {{-- Строка — кнопка «раскрыть»; отметки «Решено» / «Не требует внимания» / «Вернуть» — рядом, не внутри. --}}
                <div class="flex items-center">
                <button type="button" wire:click="open({{ $c['id'] }})" class="flex-1 min-w-0 text-left px-3 py-2 flex flex-wrap items-center gap-2 hover:bg-hover">
                    <span class="font-medium text-fg-1">{{ $c['subject'] }}</span>
                    @if($who)
                        <span class="chip chip-info text-[11px]" title="Адрес отправителя — среди поставщиков QuoteParts">🏭 Поставщик: <b>{{ $who }}</b></span>
                    @else
                        <span class="chip chip-neutral text-[10px]" title="Адреса нет среди поставщиков QuoteParts">не поставщик</span>
                    @endif
                    <span class="text-[12px] text-fg-3">{{ $contact }}</span>
                    @if($c['status'] === 'closed')
                        @php
                            $closedTip = trim(($c['closed_by'] ?? '').(! empty($c['closed_at']) ? ' · '.\Illuminate\Support\Carbon::parse($c['closed_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : ''), ' ·');
                        @endphp
                        @if(($c['resolution'] ?? null) === 'resolved')
                            <span class="chip chip-ok text-[10px]" title="{{ $closedTip }}">✓ решено</span>
                        @elseif(($c['resolution'] ?? null) === 'no_action')
                            <span class="chip chip-neutral text-[10px]" title="{{ $closedTip }}">🔕 не требует внимания</span>
                        @else
                            <span class="chip chip-neutral text-[10px]" title="{{ $closedTip }}">закрыто</span>
                        @endif
                    @endif
                    @if(\App\Livewire\QuoteParts\SupportRequests::waiting($c))<span class="chip chip-warn text-[10px]">ждёт ответа</span>@endif
                    <span class="ml-auto text-[11px] text-fg-4">{{ $c['last_message_at'] ? \Illuminate\Support\Carbon::parse($c['last_message_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : '' }}</span>
                </button>
                @if($canAnswer)
                    <div class="flex items-center gap-1 pr-2 shrink-0">
                        @if($c['status'] === 'open')
                            <button type="button" wire:click="close({{ $c['id'] }}, 'resolved')" wire:loading.attr="disabled" class="btn btn-sm" title="Вопрос решён — письмо уйдёт из счётчика">✓ Решено</button>
                            <button type="button" wire:click="close({{ $c['id'] }}, 'no_action')" wire:loading.attr="disabled" class="btn btn-sm" title="Рассылка, уведомление, робот — отвечать не нужно">🔕 Не требует внимания</button>
                        @else
                            <button type="button" wire:click="reopen({{ $c['id'] }})" wire:loading.attr="disabled" class="btn btn-sm" title="Отметили по ошибке — снова в работу">↩ Вернуть</button>
                        @endif
                    </div>
                @endif
                </div>
                @if($activeId === $c['id'])
                    <div class="px-3 pb-3 space-y-1.5">
                        @if($who && str_starts_with((string) $c['supplier'], 'sup:'))
                            <div class="text-[12px]"><a href="{{ route('suppliers.registry-edit', (int) substr($c['supplier'], 4)) }}" wire:navigate class="text-sky-700 hover:underline">Карточка поставщика «{{ $who }}» →</a></div>
                        @endif
                        @foreach($c['messages'] as $m)
                            <div wire:key="qpsm-{{ $m['id'] }}" class="rounded-md px-2.5 py-1.5 text-[12.5px] {{ $m['direction'] === 'out' ? 'bg-sky-50 border border-sky-200 ml-8' : 'bg-surface-2 border border-border-subtle mr-8' }}">
                                <div class="text-[11px] text-fg-3 mb-0.5">
                                    {{ $m['direction'] === 'out' ? 'Поддержка' : ($who ?? 'Отправитель') }}{{ $m['author'] ? ' · '.$m['author'] : '' }}
                                    · {{ $m['at'] ? \Illuminate\Support\Carbon::parse($m['at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : '' }}
                                    @if($m['send_error'])<span class="text-red-700">· не отправлено</span>@endif
                                    @if(! empty($m['cc']))<span title="Копия">· копия: {{ implode(', ', $m['cc']) }}</span>@endif
                                    @if(! empty($m['bcc']))<span title="Скрытая копия">· скрытая: {{ implode(', ', $m['bcc']) }}</span>@endif
                                </div>
                                <div class="whitespace-pre-wrap">{{ $m['text'] }}</div>
                                @foreach($m['attachments'] as $a)
                                    @include('partials.file-link', ['url' => route('quoteparts.conversation-file', [$c['id'], $m['id'], $a['index']]), 'name' => $a['name'], 'mime' => $a['mime'] ?? null, 'fileClass' => 'mr-3 text-sky-700 hover:underline text-[12px]'])
                                @endforeach
                            </div>
                        @endforeach
                        @if($c['status'] === 'open' && $canAnswer && $draftKey !== '')
                            {{-- Ответ — тем же редактором, что в «Почте» (x-mail.rich-editor + шаблоны + адресная книга).
                                 Файлы можно перетащить в форму: кладём их в скрытый input (wire:model newFiles) и стреляем change. --}}
                            <div class="qps-reply" wire:key="qps-reply-{{ $draftKey }}"
                                 x-data="{
                                    dragging: false,
                                    onDrop(ev) {
                                        this.dragging = false;
                                        const dropped = ev.dataTransfer?.files;
                                        if (! dropped || dropped.length === 0) return;
                                        const input = this.$refs.files;
                                        const dt = new DataTransfer();
                                        for (const f of dropped) dt.items.add(f);
                                        input.files = dt.files;
                                        input.dispatchEvent(new Event('change', { bubbles: true }));
                                    }
                                 }"
                                 @dragenter.prevent="dragging = true" @dragover.prevent="dragging = true"
                                 @dragleave.prevent="if (! $el.contains($event.relatedTarget)) dragging = false"
                                 @drop.prevent="onDrop($event)"
                                 @qps-files-added.window="$refs.files.value = ''">
                                <div class="qps-dropveil" x-show="dragging" x-cloak><span>📎 Отпустите, чтобы прикрепить</span></div>
                                <div class="qps-fields" data-rcpt-group>
                                    <div class="qps-row">
                                        <span class="k">Кому</span>
                                        <span class="text-fg-1">{{ $contact !== '' ? $contact : ($who ?? '—') }}</span>
                                        <span class="ml-auto text-[11px] text-fg-4">от «QuoteParts Support» · support@quoteparts.net</span>
                                    </div>
                                    <div class="qps-row">
                                        <span class="k">Копия</span>
                                        <x-mail.recipient-input field="cc" model="ccRaw" debounce="600ms" placeholder="—" />
                                    </div>
                                    <div class="qps-row">
                                        <span class="k" title="Адресаты скрытой копии не видны остальным получателям">Скрытая</span>
                                        <x-mail.recipient-input field="bcc" model="bccRaw" debounce="600ms" placeholder="—" />
                                    </div>
                                </div>
                                @error('ccRaw')<div class="text-red-700 text-[12px]">{{ $message }}</div>@enderror
                                @error('bccRaw')<div class="text-red-700 text-[12px]">{{ $message }}</div>@enderror

                                @if($english)
                                    <div class="flex items-center gap-2">
                                        <button type="button" x-on:click="window.dispatchEvent(new CustomEvent('mail-editor-flush'))" wire:click="translate"
                                                wire:loading.attr="disabled" wire:target="translate" class="btn btn-xs">
                                            <span wire:loading.remove wire:target="translate">🌐 Перевести на английский</span>
                                            <span wire:loading wire:target="translate">Перевожу…</span>
                                        </button>
                                        <span class="text-[11px] text-fg-4">писали не по-русски — напишите по-русски и переведите, перевод можно поправить (Ctrl+Z вернёт русский)</span>
                                    </div>
                                @endif

                                <div class="qps-editor">
                                    <x-mail.rich-editor :wire-id="$this->getId()" :html="$bodyHtml"
                                                        :upload-url="route('quoteparts.support.images.store', $draftKey)"
                                                        :editor-key="'qps-rte-'.$draftKey"
                                                        :placeholder="$english ? 'Ответ — по-русски, затем «Перевести», или сразу по-английски…' : 'Ваш ответ…'">
                                        <x-slot:tools>@include('livewire.mail._composer-templates-menu')</x-slot:tools>
                                    </x-mail.rich-editor>
                                </div>
                                @error('bodyText')<div class="text-red-700 text-[12px]">{{ $message }}</div>@enderror
                                @error('newFiles.*')<div class="text-red-700 text-[12px]">{{ $message }}</div>@enderror
                                @error('files.*')<div class="text-red-700 text-[12px]">{{ $message }}</div>@enderror

                                @if($files !== [])
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach($files as $i => $f)
                                            <span class="qps-att" wire:key="qpsf-{{ $draftKey }}-{{ $i }}">
                                                {{ \Illuminate\Support\Str::limit($f->getClientOriginalName(), 32) }}
                                                <button type="button" wire:click="removeFile({{ $i }})" title="Убрать">×</button>
                                            </span>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="flex items-center gap-2">
                                    <button type="button" x-on:click="window.dispatchEvent(new CustomEvent('mail-editor-flush'))" wire:click="reply"
                                            wire:loading.attr="disabled" wire:target="reply,newFiles" class="btn btn-sm btn-primary">
                                        <span wire:loading.remove wire:target="reply">Ответить</span>
                                        <span wire:loading wire:target="reply">Отправка…</span>
                                    </button>
                                    <label class="btn btn-sm cursor-pointer" title="Прикрепить файл — или перетащите файлы в форму">📎 Файл
                                        <input type="file" multiple wire:model="newFiles" x-ref="files" class="hidden"></label>
                                    <span wire:loading wire:target="newFiles" class="text-[11px] text-fg-4">Загружаю файл…</span>
                                    <span class="text-[11px] text-emerald-700" x-data="{ t: '' }" x-show="t" x-cloak x-text="t"
                                          @mail-composer-note.window="t = $event.detail.text; setTimeout(() => t = '', 4000)"></span>
                                    <span class="ml-auto text-[11px] text-fg-4">без ответа — «Решено» или «Не требует внимания» в строке письма</span>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
