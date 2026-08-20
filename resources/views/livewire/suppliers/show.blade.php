<div class="space-y-4">
    @php $inputCls = 'h-[30px] w-full px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500'; @endphp

    {{-- Заголовок --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('suppliers.index') }}" wire:navigate class="text-[12px] text-sky-700 hover:underline">← Поставщики</a>
        <h2 class="text-[16px] font-semibold text-fg-1">{{ $inquiry->supplier_name ?: $inquiry->supplier_email }}</h2>
        <span class="chip {{ $inquiry->status === 'closed' ? 'chip-neutral' : 'chip-sky' }} text-[11px]">{{ $inquiry->status === 'closed' ? 'закрыт' : 'открыт' }}</span>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Реквизиты запроса --}}
        <div class="lg:col-span-2 ds-card">
            <div class="ds-card-header"><h3>Запрос поставщику</h3></div>
            <div class="ds-card-body space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Название поставщика</label>
                        <input type="text" wire:model="supplier_name" class="{{ $inputCls }}">
                        @error('supplier_name') <div class="text-[11px] text-red-600 mt-0.5">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">E-mail</label>
                        <input type="text" value="{{ $inquiry->supplier_email }}" class="{{ $inputCls }} mono" disabled>
                    </div>
                </div>
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Тема запроса</label>
                    <div class="text-[13px] text-fg-2">{{ $inquiry->subject ?: '—' }}</div>
                </div>
                @if($inquiry->relatedRequest)
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Клиентская заявка</label>
                        <a href="{{ route('requests.show', $inquiry->relatedRequest->id) }}" wire:navigate class="mono text-sky-700 hover:underline">{{ $inquiry->relatedRequest->internal_code }}</a>
                    </div>
                @endif
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Заметки</label>
                    <textarea wire:model="notes" rows="2" class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                </div>
                <div class="flex gap-2 pt-1 flex-wrap">
                    <button type="button" wire:click="save" class="btn btn-sm btn-primary">Сохранить</button>
                    <button type="button" wire:click="toggleStatus" class="btn btn-sm">{{ $inquiry->status === 'closed' ? 'Открыть' : 'Закрыть запрос' }}</button>
                    @if($inquiry->status !== 'closed' && $this->inquiryItems->isNotEmpty())
                        <button type="button" wire:click="remindNow" wire:loading.attr="disabled" wire:target="remindNow" class="btn btn-sm"
                                title="Отправить поставщику напоминание в этом треде">
                            <span wire:loading.remove wire:target="remindNow">📨 Напомнить</span>
                            <span wire:loading wire:target="remindNow">Отправляю…</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>

        {{-- Кто пометил --}}
        <div class="ds-card">
            <div class="ds-card-header"><h3>Информация</h3></div>
            <div class="ds-card-body space-y-2 text-[12.5px]">
                <div class="flex justify-between gap-2"><span class="text-fg-3">Пометил</span><span class="text-fg-1">{{ $inquiry->createdBy?->name ?? '—' }}</span></div>
                <div class="flex justify-between gap-2"><span class="text-fg-3">Создан</span><span class="text-fg-2 mono">{{ $inquiry->created_at?->format('d.m.Y H:i') }}</span></div>
                <div class="flex justify-between gap-2"><span class="text-fg-3">Писем в треде</span><span class="text-fg-2 mono">{{ $this->threadMessages->count() }}</span></div>
                @php $rs = $inquiry->responseState(); @endphp
                <div class="flex justify-between gap-2"><span class="text-fg-3">Ответ поставщика</span>
                    <span>
                        @if($rs === 'answered')<span class="chip chip-ok text-[10.5px]">ответил</span>
                        @elseif($rs === 'awaiting')<span class="chip chip-warn text-[10.5px]">ждём</span>
                        @else<span class="text-fg-4">—</span>@endif
                    </span>
                </div>
                @if($inquiry->reminders_sent > 0)
                    <div class="flex justify-between gap-2"><span class="text-fg-3">Напоминаний</span><span class="text-fg-2 mono">{{ $inquiry->reminders_sent }}{{ $inquiry->last_reminder_at ? ' · ' . $inquiry->last_reminder_at->format('d.m.Y') : '' }}</span></div>
                @endif
            </div>
        </div>
    </div>

    {{-- Позиции и предложения (Фаза 3.3) --}}
    @if($this->inquiryItems->isNotEmpty())
        <div class="ds-card">
            <div class="ds-card-header"><h3>Позиции и предложения</h3><span class="text-[12px] text-fg-3 ml-2">{{ $this->inquiryItems->count() }}</span></div>
            <div class="ds-card-body overflow-x-auto">
                <table class="w-full text-[12.5px]">
                    <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                        <tr>
                            <th class="text-left px-3 py-2">Позиция</th>
                            <th class="text-left px-3 py-2">Статус</th>
                            <th class="text-left px-3 py-2">Предложение</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->inquiryItems as $it)
                            @php $offer = $it->offers->first(); @endphp
                            <tr class="border-b border-border-subtle align-top">
                                <td class="px-3 py-2">
                                    <div class="text-fg-1">{{ $it->item_name ?: $it->requestItem?->parsed_name ?: '—' }}</div>
                                    @if($it->requestItem?->parsed_article)<div class="text-[11px] text-fg-4 mono">{{ $it->requestItem->parsed_article }}</div>@endif
                                </td>
                                <td class="px-3 py-2">
                                    @switch($it->status)
                                        @case('quoted') <span class="chip chip-ok text-[10.5px]">есть цена</span> @break
                                        @case('refused') <span class="chip chip-danger text-[10.5px]">отказ</span> @break
                                        @case('cancelled') <span class="chip chip-neutral text-[10.5px]">отменено</span> @break
                                        @default <span class="chip chip-sky text-[10.5px]">ждём</span>
                                    @endswitch
                                </td>
                                <td class="px-3 py-2">
                                    @if($offer && $offer->outcome === 'quoted')
                                        <span class="text-fg-1 font-medium">{{ number_format((float) $offer->price, 2, '.', ' ') }} {{ $offer->currency ?: '' }}</span>
                                        @if($offer->valid_until_text)<span class="text-[11px] text-fg-3"> · {{ $offer->valid_until_text }}</span>@endif
                                        @if($offer->raw_quote)<div class="text-[11px] text-fg-4 italic mt-0.5">«{{ \Illuminate\Support\Str::limit($offer->raw_quote, 120) }}»</div>@endif
                                    @elseif($offer && $offer->outcome === 'refused')
                                        <span class="text-red-700 text-[12px]">{{ $offer->refusal_reason ?: 'отказ' }}</span>
                                    @else
                                        <span class="text-fg-4 text-[11px]">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Переписка --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Переписка с поставщиком</h3>
            <span class="flex-1"></span>
            <button type="button" wire:click="toggleSort" class="btn btn-sm" title="Порядок сообщений">
                {{ $threadSort === 'desc' ? '↓ сначала новые' : '↑ сначала старые' }}
            </button>
        </div>
        <div class="ds-card-body space-y-3">
            {{-- Ответ поставщику --}}
            @if($this->canReply)
                @if($inquiry->status === 'closed')
                    <div class="rounded-md border border-border-subtle bg-surface-2 px-3 py-2.5 text-[12px] text-fg-3">
                        Запрос закрыт. Чтобы ответить поставщику — откройте его.
                    </div>
                @else
                    <div class="rounded-md border border-border-subtle bg-surface-2 p-3">
                        @if($inquiry->reply_state === 'question_to_us')
                            <div class="text-[12px] mb-2" style="color:var(--red-700)">Поставщик задал вопрос — ответьте, чтобы он продолжил расчёт.</div>
                        @else
                            <div class="text-[12px] text-fg-3 mb-2">Ответ уйдёт поставщику в этом же треде, от того же ящика, что и запрос.</div>
                        @endif
                        <textarea wire:model="replyBody" rows="3" placeholder="Текст ответа поставщику…"
                            class="w-full px-2.5 py-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500 resize-y"></textarea>
                        @error('replyBody')<div class="text-[11.5px] mt-1" style="color:var(--red-700)">{{ $message }}</div>@enderror
                        <div class="mt-2">
                            <label class="block text-[11px] text-fg-3 mb-1">Копия <span class="text-fg-4">(необязательно, через запятую)</span></label>
                            <input type="text" wire:model.blur="replyCc" placeholder="petrov@firma.ru, Иванов &lt;ivanov@firma.ru&gt;"
                                   class="w-full px-2.5 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
                            @error('replyCc')<div class="text-[11.5px] mt-1" style="color:var(--red-700)">{{ $message }}</div>@enderror
                        </div>
                        <div class="mt-2">
                            <label class="block text-[11px] text-fg-3 mb-1">Фото / файлы <span class="text-fg-4">(необязательно)</span></label>
                            <input type="file" wire:model="replyFiles" multiple accept="image/*,.pdf,.xlsx,.xls,.doc,.docx"
                                   class="w-full text-[12px] border border-border rounded-md p-1.5 bg-surface">
                            @error('replyFiles.*')<div class="text-[11.5px] mt-1" style="color:var(--red-700)">{{ $message }}</div>@enderror
                            <div wire:loading wire:target="replyFiles" class="text-[11px] text-fg-3 mt-1">Загрузка…</div>
                            @if(count($replyFiles ?? []))
                                <div class="text-[11px] text-emerald-700 mt-1">Прикреплено: {{ count($replyFiles) }}</div>
                            @endif
                        </div>
                        <div class="flex items-center gap-2 mt-2">
                            <button type="button" wire:click="sendReply" wire:loading.attr="disabled" wire:target="sendReply,replyFiles"
                                class="btn btn-sm btn-primary">
                                <span wire:loading.remove wire:target="sendReply">Отправить</span>
                                <span wire:loading wire:target="sendReply">Отправка…</span>
                            </button>
                        </div>
                    </div>
                @endif
            @endif
            @forelse($this->threadMessages as $m)
                @php
                    $isInbound = $m->direction === \App\Enums\MailDirection::Inbound;
                    $html = $this->bodyHtmlFor($m);
                @endphp
                <div wire:key="msg-{{ $m->id }}" class="rounded-md border border-border-subtle overflow-hidden {{ $isInbound ? 'bg-surface' : 'bg-surface-2' }}">
                    <div class="flex items-center gap-2 text-[11.5px] text-fg-3 px-3 pt-2.5 flex-wrap">
                        <span class="chip {{ $isInbound ? 'chip-info' : 'chip-neutral' }} text-[10px]">{{ $isInbound ? '← от поставщика' : '→ наше' }}</span>
                        <span class="mono">{{ $m->from_name ?: $m->from_email }}</span>
                        <span class="flex-1"></span>
                        <span class="mono">{{ $m->sent_at?->format('d.m.Y H:i') ?? '—' }}</span>
                    </div>
                    @if($m->subject)<div class="text-[12.5px] text-fg-1 font-medium mt-1 px-3">{{ $m->subject }}</div>@endif
                    @if($html)
                        <iframe
                            wire:key="if-{{ $m->id }}"
                            wire:ignore.self
                            sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                            srcdoc="{{ $html }}"
                            loading="lazy"
                            class="w-full block border-0 bg-surface mt-2"
                            style="height: 0"
                            x-data
                            x-init="
                                const fit = () => {
                                    try {
                                        const doc = $el.contentDocument;
                                        if (!doc || !doc.documentElement) return;
                                        $el.style.height = '8px';
                                        const h = doc.documentElement.scrollHeight;
                                        $el.style.height = (h + 4) + 'px';
                                    } catch (e) {}
                                };
                                $el.addEventListener('load', () => {
                                    try {
                                        const doc = $el.contentDocument;
                                        if (!doc) return;
                                        doc.querySelectorAll('a[href]').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
                                        const s = doc.createElement('style');
                                        s.textContent = 'html,body{margin:0;padding:0}body{padding:8px 12px;font:13px/1.55 system-ui,-apple-system,Segoe UI,Inter,sans-serif;color:#0a0a0a;word-break:break-word}img{max-width:100%;height:auto}';
                                        (doc.head || doc.documentElement).appendChild(s);
                                        try { new ResizeObserver(fit).observe(doc.documentElement); } catch (e) {}
                                        doc.addEventListener('toggle', fit, true);
                                        fit();
                                    } catch (e) {}
                                });
                            "
                        ></iframe>
                    @elseif($m->body_plain)
                        <pre class="whitespace-pre-wrap font-sans text-[12.5px] text-fg-2 px-3 pt-2 pb-3 m-0">{{ trim((string) $m->body_plain) }}</pre>
                    @else
                        <div class="px-3 pt-2 pb-3 text-[12px] text-fg-4">(пустое письмо)</div>
                    @endif

                    {{-- Вложения (фото деталей, прайсы). Пропускаем встроенные
                         inline-логотипы (рендерятся в теле письма). Фото —
                         превью с лайтбоксом (как в клиентском треде). --}}
                    @php
                        // Прячем только инлайн-КАРТИНКИ (подписи/логотипы). Инлайн
                        // НЕ-картинки (PDF-прайс с Content-ID) — реальные документы,
                        // показываем. Кейс inq 3380: прайс-PDF был inline → пропадал.
                        $files = $m->attachments->reject(fn ($a) => (bool) $a->is_inline
                            && \Illuminate\Support\Str::startsWith(strtolower((string) $a->mime_type), 'image/'));
                        $galleryImgs = $files->filter(fn ($a) => $a->mime_type && \Illuminate\Support\Str::startsWith(strtolower($a->mime_type), 'image/'))->values();
                        $gallery = $galleryImgs->map(fn ($a) => [
                            'src' => route('attachments.preview', $a),
                            'name' => $a->filename,
                            'dl' => route('attachments.download', $a),
                        ])->all();
                        $imgIdx = 0;
                    @endphp
                    @if($files->isNotEmpty())
                        <div class="px-3 pb-3 pt-2 flex flex-wrap gap-2 border-t border-border-subtle" x-data="{ items: @js($gallery) }">
                            @foreach($files as $att)
                                @php
                                    $isImg = $att->mime_type && \Illuminate\Support\Str::startsWith(strtolower($att->mime_type), 'image/');
                                    $isPdf = ($att->mime_type && \Illuminate\Support\Str::contains(strtolower($att->mime_type), 'pdf'))
                                        || strtolower(\Illuminate\Support\Str::afterLast($att->filename, '.')) === 'pdf';
                                @endphp
                                @if($isImg)
                                    <button type="button"
                                            x-on:click="$dispatch('open-image', { items: items, index: {{ $imgIdx }} })"
                                            class="block border border-border rounded-md overflow-hidden bg-surface hover:border-border-strong transition-colors text-left" title="{{ $att->filename }}">
                                        <img src="{{ route('attachments.preview', $att) }}" alt="{{ $att->filename }}" loading="lazy"
                                             class="w-[120px] h-[90px] object-cover block bg-app">
                                        <div class="px-2 py-1 max-w-[120px] text-[10.5px] text-fg-3">
                                            <span class="block truncate text-fg-1">{{ $att->filename }}</span>
                                            @if($att->size_bytes)<span>{{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>@endif
                                        </div>
                                    </button>
                                    @php $imgIdx++; @endphp
                                @elseif($isPdf)
                                    {{-- PDF → предпросмотр в модалке (iframe), как фото в лайтбоксе. --}}
                                    <button type="button"
                                            x-on:click="$dispatch('open-pdf', { src: @js(route('attachments.preview', $att)), name: @js($att->filename), dl: @js(route('attachments.download', $att)) })"
                                            class="inline-flex items-center gap-1.5 px-2.5 py-1.5 border border-border rounded-md bg-surface text-[12px] text-fg-1 hover:bg-hover self-start text-left"
                                            title="Предпросмотр — {{ $att->filename }}">
                                        <span class="inline-block w-4 h-5 bg-red-50 border border-red-300 rounded-sm text-red-700 text-[7px] font-bold text-center leading-5">PDF</span>
                                        <span class="truncate max-w-[210px]">{{ $att->filename }}</span>
                                        @if($att->size_bytes)<span class="text-fg-3 text-[11px]">· {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>@endif
                                        <span class="text-sky-700 text-[11px]">👁 просмотр</span>
                                    </button>
                                @else
                                    <a href="{{ route('attachments.download', $att) }}"
                                       class="inline-flex items-center gap-1.5 px-2.5 py-1.5 border border-border rounded-md bg-surface text-[12px] text-fg-1 hover:bg-hover self-start">
                                        <span class="inline-block w-4 h-5 bg-red-50 border border-red-300 rounded-sm text-red-700 text-[7px] font-bold text-center leading-5">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::afterLast($att->filename, '.')) ?: 'BIN' }}</span>
                                        <span class="truncate max-w-[240px]">{{ $att->filename }}</span>
                                        @if($att->size_bytes)<span class="text-fg-3 text-[11px]">· {{ number_format($att->size_bytes / 1024, 0, '.', ' ') }} KB</span>@endif
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="text-sm text-fg-3 px-1 py-2">Писем нет.</div>
            @endforelse
        </div>
    </div>

    {{-- Удаление --}}
    <div class="ds-card">
        <div class="ds-card-body flex items-center justify-between gap-3 flex-wrap">
            <div class="text-[12px] text-fg-3">Удалить запрос поставщику. Письма не удаляются — только открепляются от запроса.</div>
            <button type="button" wire:click="deleteInquiry" wire:confirm="Удалить запрос поставщику? Письма останутся, но открепятся." class="btn btn-sm text-red-600">Удалить запрос</button>
        </div>
    </div>

    @include('partials.image-lightbox')
    @include('partials.pdf-preview')
</div>
