{{-- «Письма без запроса»: очередь «Не разобрано» QuoteParts — прочитать, привязать к поставщику запроса или отклонить. --}}
<div class="space-y-3">
    <div class="text-[12.5px] text-fg-3">
        Письма поставщиков, которые QuoteParts не смог сам привязать к запросу: нет метки [QP-…] и ответа на наше письмо, запрос потерян или поставщик ответил с другого адреса.
        Привяжите письмо к поставщику запроса — QuoteParts разберёт его как обычный ответ, цены появятся в заявке и «Актуализации цен».
    </div>
    @if($error)<div class="text-[12.5px] text-red-600">{{ $error }}</div>@endif

    <div class="ds-card">
        @forelse($this->letters as $l)
            @php $isOpen = $openId === $l['id']; @endphp
            <div wire:key="qpl-{{ $l['id'] }}" class="border-b border-border-subtle">
                <button type="button" wire:click="open({{ $l['id'] }})" class="w-full text-left px-4 py-3 hover:bg-hover">
                    <div class="flex flex-wrap items-center gap-2 text-[12.5px]">
                        <span class="font-semibold text-fg-1">{{ $l['from_name'] ?: $l['from_email'] }}</span>
                        @if($l['from_name'])<span class="mono text-[11.5px] text-fg-4">{{ $l['from_email'] }}</span>@endif
                        @if($l['auto_reply'])<span class="chip chip-neutral text-[10.5px]">автоответ</span>@endif
                        @foreach($l['tags'] as $t)<span class="chip chip-sky text-[10.5px]">{{ $t }}</span>@endforeach
                        <span class="flex-1"></span>
                        <span class="text-[11.5px] text-fg-4">на {{ $l['mailbox'] ?? '—' }}</span>
                        <span class="text-[11.5px] text-fg-3 tnum">{{ $l['sent_at'] ? \Illuminate\Support\Carbon::parse($l['sent_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : '' }}</span>
                    </div>
                    <div class="text-[13px] text-fg-1 mt-0.5">{{ $l['subject'] ?: '(без темы)' }}</div>
                    @unless($isOpen)<div class="text-[12px] text-fg-3 truncate">{{ $l['snippet'] }}</div>@endunless
                </button>

                @if($isOpen)
                    <div class="px-4 pb-4 space-y-3">
                        <div class="rounded-md border border-border bg-surface-2 p-3 text-[12.5px] text-fg-1" style="white-space: pre-wrap; max-height: 360px; overflow-y: auto">{{ $this->opened['text'] ?? $l['snippet'] }}</div>
                        @if($l['attachments'] !== [])
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($l['attachments'] as $a)
                                    @if($a['stored'])
                                        @include('partials.file-link', ['url' => route('quoteparts.letter-file', [$l['id'], $a['index']]), 'name' => (string) $a['name'], 'mime' => (string) ($a['mime'] ?? '')])
                                    @else
                                        <span class="chip chip-neutral" title="Файл больше 25 МБ — QuoteParts его не сохранил">📎 {{ $a['name'] }} · не сохранён</span>
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        <div>
                            <div class="flex flex-wrap items-center gap-2 mb-1.5">
                                <span class="text-[11px] uppercase tracking-wider text-fg-3">К какому запросу письмо</span>
                                <input type="search" wire:model.live.debounce.400ms="find" placeholder="Найти запрос: QP-…, номер заявки или 1С"
                                       class="h-[28px] w-full max-w-[320px] px-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
                            </div>
                            <div class="border border-border rounded-md divide-y divide-border-subtle">
                                @forelse($this->options as $o)
                                    <div wire:key="qpl-o-{{ $l['id'] }}-{{ $o['remote_id'] }}" class="flex flex-wrap items-center gap-2 px-3 py-2 text-[12.5px]">
                                        <span class="chip chip-neutral mono text-[10.5px]">{{ $o['tag'] }}</span>
                                        @if($o['request'])
                                            <a href="{{ route('requests.show', $o['request_id']) }}" target="_blank" rel="noopener" class="mono text-sky-700 hover:underline">{{ $o['request'] }}</a>
                                        @else
                                            <span class="text-fg-4">без заявки</span>
                                        @endif
                                        <span class="text-fg-4 text-[11.5px]">{{ $o['at'] }} · {{ $o['why'] }}</span>
                                        <span class="flex-1"></span>
                                        @if(count($o['suppliers']) > 1 || $o['default'] === null)
                                            <select wire:model="supplierFor.{{ $o['remote_id'] }}" class="h-[28px] px-2 border border-border rounded bg-surface text-[12px]">
                                                <option value="">поставщик…</option>
                                                @foreach($o['suppliers'] as $key => $name)
                                                    <option value="{{ $key }}" @selected($o['default'] === $key)>{{ $name }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span class="text-fg-2">{{ $o['suppliers'][$o['default']] ?? '' }}</span>
                                        @endif
                                        <button type="button" wire:click="assign({{ $o['remote_id'] }})" wire:loading.attr="disabled" wire:target="assign" class="btn btn-sm btn-primary">Привязать</button>
                                    </div>
                                @empty
                                    <div class="px-3 py-2 text-[12px] text-fg-4">Подходящих запросов не нашлось. Найдите запрос по метке QP-…, номеру заявки или 1С. Если запрос потерян при сбое баз, привязать письмо не к чему — его можно отклонить.</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="ignore({{ $l['id'] }})" wire:confirm="Отклонить письмо? Оно уйдёт из очереди и разбираться не будет."
                                    class="btn btn-sm">Отклонить</button>
                            <span class="text-[11.5px] text-fg-4">рассылки, тесты, письма не о запросах цен</span>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="px-4 py-8 text-center text-[13px] text-fg-3">Неразобранных писем нет — QuoteParts привязал все ответы сам.</div>
        @endforelse
    </div>
</div>
