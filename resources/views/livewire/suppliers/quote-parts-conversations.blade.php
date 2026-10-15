<div class="ds-card" wire:init="load">
    @php $inputCls = 'w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500'; @endphp
    <div class="ds-card-header">
        <h3>Обращения</h3>
        <span class="text-[12px] text-fg-3 ml-2">переписка через QuoteParts вне запросов цен · пилот</span>
        @if(! $composing)
            <button type="button" wire:click="$set('composing', true)" class="btn btn-sm btn-primary ml-auto">Новое обращение</button>
        @endif
    </div>
    <div class="ds-card-body space-y-3 text-[13px]">
        @if($composing)
            <div class="border border-sky-200 bg-sky-50/40 rounded-md p-3 space-y-2">
                <div class="text-[12px] text-fg-3">Письмо уйдёт людям поставщика по выбранной теме — от MyZip, в отдельную переписку. Поставщик ответит письмом или в своём кабинете QuoteParts; ответ придёт сюда и в «колокольчик».</div>
                <div class="flex flex-wrap gap-2">
                    <select wire:model="topic" class="h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px]">
                        @foreach(\App\Livewire\Suppliers\QuotePartsConversations::TOPICS as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    </select>
                    <input type="text" wire:model="subject" placeholder="Тема по-русски" class="{{ $inputCls }} flex-1 min-w-[240px]">
                </div>
                @error('subject')<div class="text-red-700 text-[12px]">{{ $message }}</div>@enderror
                <textarea wire:model="text" rows="4" placeholder="Текст по-русски" class="{{ $inputCls }}"></textarea>
                @error('text')<div class="text-red-700 text-[12px]">{{ $message }}</div>@enderror
                @if($english)
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="translate" wire:loading.attr="disabled" wire:target="translate" class="btn btn-xs">🌐 Перевести на английский</button>
                        <span class="text-[11px] text-fg-4">у поставщика язык общения English — уйдёт английский вариант, его можно поправить</span>
                    </div>
                    <input type="text" wire:model="subjectEn" placeholder="Subject in English" class="{{ $inputCls }}">
                    @error('subjectEn')<div class="text-red-700 text-[12px]">{{ $message }}</div>@enderror
                    <textarea wire:model="textEn" rows="4" placeholder="Text in English" class="{{ $inputCls }}"></textarea>
                    @error('textEn')<div class="text-red-700 text-[12px]">{{ $message }}</div>@enderror
                @endif
                <input type="file" wire:model="files" multiple class="block w-full text-[12px]">
                <div wire:loading wire:target="files" class="text-[11px] text-fg-3">Загрузка…</div>
                <div class="flex gap-2">
                    <button type="button" wire:click="start" wire:loading.attr="disabled" wire:target="start,files" class="btn btn-sm btn-primary">Отправить поставщику</button>
                    <button type="button" wire:click="$set('composing', false)" class="btn btn-sm">Отмена</button>
                </div>
            </div>
        @endif

        @if($error)<div class="text-red-700 text-[12.5px]">{{ $error }}</div>@endif
        @if(! $loaded)
            <div class="text-fg-4 text-[12px]">Загружаю…</div>
        @elseif($conversations === [])
            <div class="text-fg-4 text-[12px]">Обращений пока нет.</div>
        @endif

        @foreach($conversations as $c)
            <div wire:key="qpc-{{ $c['id'] }}" class="border border-border rounded-md">
                <button type="button" wire:click="open({{ $c['id'] }})" class="w-full text-left px-3 py-2 flex flex-wrap items-center gap-2 hover:bg-hover">
                    <span class="font-medium text-fg-1">{{ $c['subject'] }}</span>
                    <span class="chip chip-neutral text-[10px]">{{ \App\Livewire\Suppliers\QuotePartsConversations::TOPICS[$c['topic']] ?? $c['topic'] }}</span>
                    @if($c['started_by'] === 'supplier')<span class="chip chip-sky text-[10px]">начал поставщик</span>@endif
                    @if($c['status'] === 'closed')<span class="chip chip-neutral text-[10px]">закрыто</span>@endif
                    @php $last = collect($c['messages'])->last(); @endphp
                    @if($last && $last['direction'] === 'in' && $c['status'] === 'open')<span class="chip chip-warn text-[10px]">ждёт ответа</span>@endif
                    <span class="ml-auto text-[11px] text-fg-4">{{ $c['last_message_at'] ? \Illuminate\Support\Carbon::parse($c['last_message_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : '' }}</span>
                </button>
                @if($activeId === $c['id'])
                    <div class="px-3 pb-3 space-y-1.5">
                        @foreach($c['messages'] as $m)
                            <div wire:key="qpcm-{{ $m['id'] }}" class="rounded-md px-2.5 py-1.5 text-[12.5px] {{ $m['direction'] === 'out' ? 'bg-sky-50 border border-sky-200 ml-8' : 'bg-surface-2 border border-border-subtle mr-8' }}">
                                <div class="text-[11px] text-fg-3 mb-0.5">
                                    {{ $m['direction'] === 'out' ? 'Мы' : 'Поставщик' }}{{ $m['author'] ? ' · '.$m['author'] : '' }}
                                    · {{ $m['at'] ? \Illuminate\Support\Carbon::parse($m['at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : '' }}
                                    @if($m['send_error'])<span class="text-red-700">· не отправлено</span>@endif
                                </div>
                                <div class="whitespace-pre-wrap">{{ $m['text'] }}</div>
                                @foreach($m['attachments'] as $a)
                                    <a href="{{ route('quoteparts.conversation-file', [$c['id'], $m['id'], $a['index']]) }}" target="_blank" rel="noopener" class="mr-3 text-sky-700 hover:underline text-[12px]">📎 {{ $a['name'] }}</a>
                                @endforeach
                            </div>
                        @endforeach
                        @if($c['status'] === 'open')
                            <textarea wire:model="text" rows="3" placeholder="Ответ по-русски" class="{{ $inputCls }}"></textarea>
                            @error('text')<div class="text-red-700 text-[12px]">{{ $message }}</div>@enderror
                            @if($english)
                                <div class="flex items-center gap-2">
                                    <button type="button" wire:click="translate" wire:loading.attr="disabled" wire:target="translate" class="btn btn-xs">🌐 Перевести на английский</button>
                                </div>
                                <textarea wire:model="textEn" rows="3" placeholder="Answer in English" class="{{ $inputCls }}"></textarea>
                                @error('textEn')<div class="text-red-700 text-[12px]">{{ $message }}</div>@enderror
                            @endif
                            <input type="file" wire:model="files" multiple class="block w-full text-[12px]">
                            <div class="flex gap-2">
                                <button type="button" wire:click="reply" wire:loading.attr="disabled" wire:target="reply,files" class="btn btn-sm btn-primary">Отправить</button>
                                <button type="button" wire:click="close({{ $c['id'] }})" wire:confirm="Закрыть обращение? Поставщик сможет написать снова." class="btn btn-sm">Закрыть обращение</button>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
