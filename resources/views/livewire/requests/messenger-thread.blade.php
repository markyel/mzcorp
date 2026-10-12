@php
    $req = $this->request;
    $chat = $req->messengerChat;
    $channel = $chat?->channel?->label() ?? 'мессенджер';
@endphp
<div class="ds-card mb-4" wire:poll.30s>
    <div class="ds-card-header">
        <h3>Переписка в {{ $channel }}</h3>
        <span class="text-[10.5px] font-semibold text-fg-2 bg-neutral-100 px-1.5 py-0.5 rounded-full">{{ $this->messages->count() }}</span>
        <span class="flex-1"></span>
        <span class="text-[11.5px] text-fg-3">
            {{ $chat?->displayName() }}@if($chat?->username) · {{ '@'.$chat->username }}@endif · e-mail подтверждён: {{ $chat?->email }}
        </span>
    </div>

    <div class="px-[18px] py-3 flex flex-col gap-2 max-h-[420px] overflow-y-auto">
        @forelse($this->messages as $m)
            @php
                $in = $m->direction === 'in';
                $who = match ($m->kind) {
                    'request' => 'Клиент · заявка',
                    'client' => 'Клиент',
                    'manager' => 'Менеджер'.($m->author ? ' · '.$m->author->name : ''),
                    'notify' => 'Бот · уведомление',
                    default => $in ? 'Клиент' : 'Бот',
                };
                $files = (array) (($m->meta ?? [])['files'] ?? []);
                $failed = ! empty(($m->meta ?? [])['send_failed']);
            @endphp
            <div class="flex {{ $in ? 'justify-start' : 'justify-end' }}" wire:key="mm-{{ $m->id }}">
                <div class="max-w-[78%] rounded-lg px-3 py-2 text-[13px] leading-snug
                            {{ $in ? 'bg-surface-2 text-fg-1' : ($m->kind === 'manager' ? 'bg-[var(--sky-50)] text-fg-1' : 'bg-neutral-50 text-fg-2') }}">
                    <div class="text-[10.5px] text-fg-3 mb-0.5">{{ $who }} · {{ $m->created_at?->format('d.m H:i') }}
                        @if($failed)<span class="text-amber-800"> · не доставлено</span>@endif
                    </div>
                    {{-- Текст бота хранится HTML-разметкой мессенджера: показываем без тегов. --}}
                    <div class="whitespace-pre-line">{{ $m->kind === 'notify' ? html_entity_decode(strip_tags((string) $m->text)) : $m->text }}</div>
                    @if($files)
                        <div class="text-[11px] text-fg-3 mt-1">📎 {{ implode(', ', $files) }}</div>
                    @endif
                </div>
            </div>
        @empty
            <div class="text-[12.5px] text-fg-3">Сообщений по этой заявке в {{ $channel }} пока нет.</div>
        @endforelse
    </div>

    @if($this->canReply())
        <div class="px-[18px] py-3 border-t border-[var(--border-subtle)]">
            <textarea wire:model="reply" rows="2" maxlength="3500"
                      placeholder="Ответ клиенту в {{ $channel }}. КП и счета по-прежнему отправляйте письмом — бот сам сообщит о них клиенту и приложит PDF."
                      class="w-full px-2 py-1.5 rounded-md border border-[var(--border)] bg-surface text-[13px]"></textarea>
            <div class="flex items-center gap-2 mt-1.5">
                <button type="button" class="btn btn-sm btn-primary" wire:click="send" wire:loading.attr="disabled" wire:target="send">Отправить в {{ $channel }}</button>
                @if($flash)<span class="text-[12px] text-emerald-700">{{ $flash }}</span>@endif
                @if($error)<span class="text-[12px] text-amber-800">{{ $error }}</span>@endif
            </div>
        </div>
    @endif
</div>
