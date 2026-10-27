{{-- Письма на support@quoteparts.net (App\Livewire\QuoteParts\SupportRequests): кто писал, переписка, ответ с support@. --}}
<div class="ds-card mb-4" wire:init="load">
    @php $inputCls = 'w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500'; @endphp
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
            <div wire:key="qps-{{ $c['id'] }}" class="border border-border rounded-md">
                <button type="button" wire:click="open({{ $c['id'] }})" class="w-full text-left px-3 py-2 flex flex-wrap items-center gap-2 hover:bg-hover">
                    <span class="font-medium text-fg-1">{{ $c['subject'] }}</span>
                    @if($who)
                        <span class="chip chip-sky text-[10px]">{{ $who }}</span>
                    @else
                        <span class="chip chip-neutral text-[10px]" title="Адреса нет среди поставщиков QuoteParts">не поставщик</span>
                    @endif
                    <span class="text-[12px] text-fg-3">{{ $contact }}</span>
                    @if($c['status'] === 'closed')<span class="chip chip-neutral text-[10px]">закрыто</span>@endif
                    @if(\App\Livewire\QuoteParts\SupportRequests::waiting($c))<span class="chip chip-warn text-[10px]">ждёт ответа</span>@endif
                    <span class="ml-auto text-[11px] text-fg-4">{{ $c['last_message_at'] ? \Illuminate\Support\Carbon::parse($c['last_message_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : '' }}</span>
                </button>
                @if($activeId === $c['id'])
                    <div class="px-3 pb-3 space-y-1.5">
                        @foreach($c['messages'] as $m)
                            <div wire:key="qpsm-{{ $m['id'] }}" class="rounded-md px-2.5 py-1.5 text-[12.5px] {{ $m['direction'] === 'out' ? 'bg-sky-50 border border-sky-200 ml-8' : 'bg-surface-2 border border-border-subtle mr-8' }}">
                                <div class="text-[11px] text-fg-3 mb-0.5">
                                    {{ $m['direction'] === 'out' ? 'Поддержка' : ($who ?? 'Отправитель') }}{{ $m['author'] ? ' · '.$m['author'] : '' }}
                                    · {{ $m['at'] ? \Illuminate\Support\Carbon::parse($m['at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : '' }}
                                    @if($m['send_error'])<span class="text-red-700">· не отправлено</span>@endif
                                </div>
                                <div class="whitespace-pre-wrap">{{ $m['text'] }}</div>
                                @foreach($m['attachments'] as $a)
                                    @include('partials.file-link', ['url' => route('quoteparts.conversation-file', [$c['id'], $m['id'], $a['index']]), 'name' => $a['name'], 'mime' => $a['mime'] ?? null, 'fileClass' => 'mr-3 text-sky-700 hover:underline text-[12px]'])
                                @endforeach
                            </div>
                        @endforeach
                        @if($c['status'] === 'open' && $canAnswer)
                            <textarea wire:model="text" rows="3" placeholder="Ответ по-русски" class="{{ $inputCls }}"></textarea>
                            @error('text')<div class="text-red-700 text-[12px]">{{ $message }}</div>@enderror
                            @if($english)
                                <div class="flex items-center gap-2">
                                    <button type="button" wire:click="translate" wire:loading.attr="disabled" wire:target="translate" class="btn btn-xs">🌐 Перевести на английский</button>
                                    <span class="text-[11px] text-fg-4">писали не по-русски — уйдёт английский вариант, его можно поправить</span>
                                </div>
                                <textarea wire:model="textEn" rows="3" placeholder="Answer in English" class="{{ $inputCls }}"></textarea>
                                @error('textEn')<div class="text-red-700 text-[12px]">{{ $message }}</div>@enderror
                            @endif
                            <input type="file" wire:model="files" multiple class="block w-full text-[12px]">
                            <div class="flex gap-2">
                                <button type="button" wire:click="reply" wire:loading.attr="disabled" wire:target="reply,files" class="btn btn-sm btn-primary">Ответить</button>
                                <button type="button" wire:click="close({{ $c['id'] }})" wire:confirm="Закрыть письмо? Если человек ответит, оно откроется снова." class="btn btn-sm">Закрыть</button>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
