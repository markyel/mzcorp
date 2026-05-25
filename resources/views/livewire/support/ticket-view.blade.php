<div class="max-w-[920px] mx-auto px-6 py-6">
    @php $t = $this->ticket; $ctx = $t->context ?? []; @endphp

    <div class="flex items-center gap-2 mb-2 text-[12.5px] text-fg-3">
        <a href="{{ $this->isAdmin ? route('support.inbox') : route('support.my') }}"
           class="hover:underline">{{ $this->isAdmin ? 'Инбокс' : 'Мои обращения' }}</a>
        <span>›</span>
        <span class="font-mono">#{{ $t->id }}</span>
    </div>

    <div class="flex items-start justify-between gap-3 mb-3">
        <h1 class="text-[20px] font-semibold text-fg-1 flex-1">{{ $t->subject }}</h1>
        <span class="chip {{ $t->status->chipClass() }}">{{ $t->status->label() }}</span>
    </div>

    @if(session('support_status'))
        <div class="mb-3 px-3 py-2 rounded-md border border-[var(--emerald-600)] bg-[var(--emerald-50,#ecfdf5)] text-[13px] text-fg-1">
            {{ session('support_status') }}
        </div>
    @endif

    {{-- Контекст-карточка --}}
    @if(!empty($ctx['url']) || !empty($ctx['route_name']))
        <div class="ds-card p-3 mb-4 text-[12px] text-fg-2">
            <div class="text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1.5">Контекст</div>
            <dl class="grid grid-cols-[100px_1fr] gap-y-0.5 font-mono">
                @if(!empty($ctx['url']))
                    <dt class="text-fg-3">URL:</dt><dd class="break-all">{{ $ctx['url'] }}</dd>
                @endif
                @if(!empty($ctx['route_name']))
                    <dt class="text-fg-3">Route:</dt><dd>{{ $ctx['route_name'] }}</dd>
                @endif
                @if(!empty($ctx['viewport']))
                    <dt class="text-fg-3">Viewport:</dt><dd>{{ $ctx['viewport'] }}</dd>
                @endif
                @if(!empty($ctx['roles_snapshot']))
                    <dt class="text-fg-3">Роли:</dt>
                    <dd>{{ collect($ctx['roles_snapshot'])->map(fn ($r) => \App\Enums\Role::tryFrom($r)?->label() ?? $r)->implode(', ') }}</dd>
                @endif
                @if(!empty($ctx['user_agent']))
                    <dt class="text-fg-3">UA:</dt><dd class="break-all">{{ \Illuminate\Support\Str::limit($ctx['user_agent'], 140) }}</dd>
                @endif
            </dl>
        </div>
    @endif

    {{-- Initial body как первая «реплика» автора --}}
    <div class="ds-card p-4 mb-3">
        <div class="flex items-center gap-2 mb-2 text-[12px] text-fg-3">
            <span class="font-semibold text-fg-1">{{ $t->user?->name }}</span>
            <span>·</span>
            <span class="font-mono">{{ $t->created_at?->format('d.m.Y H:i') }}</span>
            <span class="text-fg-4">· создал тикет</span>
        </div>
        <div class="text-[13.5px] text-fg-1 whitespace-pre-wrap">{{ $t->body }}</div>

        @if($t->attachments->isNotEmpty())
            <div class="mt-3 pt-3 border-t border-border-subtle">
                <div class="text-[11px] uppercase tracking-wider text-fg-3 font-semibold mb-1.5">Вложения</div>
                <ul class="space-y-0.5 text-[12.5px]">
                    @foreach($t->attachments as $att)
                        <li>
                            <a href="{{ route('support.attachment.download', $att) }}"
                               class="text-sky-700 hover:underline">{{ $att->original_name }}</a>
                            <span class="text-fg-3">({{ $att->humanSize() }})</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    {{-- Тред --}}
    @foreach($this->visibleMessages as $m)
        @php $own = $m->user_id === auth()->id(); @endphp
        <div wire:key="msg-{{ $m->id }}"
             class="ds-card p-4 mb-3 {{ $m->is_internal ? 'border-[var(--amber-600)]' : '' }}">
            <div class="flex items-center gap-2 mb-2 text-[12px] text-fg-3">
                <span class="font-semibold text-fg-1">{{ $m->author?->name }}</span>
                <span>·</span>
                <span class="font-mono">{{ $m->created_at?->format('d.m.Y H:i') }}</span>
                @if($m->is_internal)
                    <span class="chip chip-attn">внутренняя заметка</span>
                @endif
                @if($own)
                    <span class="text-fg-4">· вы</span>
                @endif
            </div>
            <div class="text-[13.5px] text-fg-1 whitespace-pre-wrap">{{ $m->body }}</div>

            @if($m->attachments->isNotEmpty())
                <div class="mt-3 pt-3 border-t border-border-subtle">
                    <ul class="space-y-0.5 text-[12.5px]">
                        @foreach($m->attachments as $att)
                            <li>
                                <a href="{{ route('support.attachment.download', $att) }}"
                                   class="text-sky-700 hover:underline">{{ $att->original_name }}</a>
                                <span class="text-fg-3">({{ $att->humanSize() }})</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endforeach

    {{-- Форма ответа --}}
    @if(!$t->status->isTerminal())
        <div class="ds-card p-4 mt-4">
            <form wire:submit="sendReply" class="space-y-3">
                <div>
                    <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Ответ</label>
                    <textarea wire:model="reply" rows="5" maxlength="5000"
                              class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] resize-y"></textarea>
                    @error('reply') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>

                <div>
                    <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                        Файлы <span class="normal-case text-fg-4 font-normal">(до 10 МБ × файл)</span>
                    </label>
                    <input type="file" wire:model="replyAttachments" multiple
                           class="block w-full text-[12.5px] text-fg-2
                                  file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border file:border-border
                                  file:bg-surface file:text-fg-1 file:text-[12.5px] file:font-medium
                                  file:hover:bg-[var(--bg-hover)] file:cursor-pointer" />
                    <div wire:loading wire:target="replyAttachments" class="text-[12px] text-fg-3 mt-1">Загружаем файлы…</div>
                    @error('replyAttachments.*') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="flex items-center gap-3 pt-2 border-t border-border-subtle">
                    <button type="submit" class="btn btn-primary"
                            wire:loading.attr="disabled" wire:target="sendReply,replyAttachments">
                        <span wire:loading.remove wire:target="sendReply">Отправить ответ</span>
                        <span wire:loading wire:target="sendReply">Отправляем…</span>
                    </button>

                    @if($this->isAdmin)
                        <label class="flex items-center gap-1.5 text-[12px] text-fg-2 cursor-pointer">
                            <input type="checkbox" wire:model="isInternal" class="rounded">
                            Внутренняя заметка (не уйдёт пользователю)
                        </label>
                    @endif

                    <div class="flex-1"></div>

                    @if($this->isAdmin)
                        <div class="flex items-center gap-1">
                            @if($t->status->value === 'open')
                                <button type="button" wire:click="changeStatus('in_progress')" class="btn">Взять в работу</button>
                            @endif
                            @if(in_array($t->status->value, ['open', 'in_progress']))
                                <button type="button" wire:click="changeStatus('resolved')" class="btn">Отметить решённым</button>
                            @endif
                            <button type="button" wire:click="changeStatus('closed')" class="btn">Закрыть</button>
                        </div>
                    @elseif(auth()->id() === $t->user_id)
                        <button type="button" wire:click="closeAsAuthor" class="btn">Закрыть тикет</button>
                    @endif
                </div>
            </form>
        </div>
    @else
        <div class="ds-card p-3 text-[13px] text-fg-3 text-center">
            Тикет {{ $t->status->label() }} {{ $t->closed_at?->format('d.m.Y H:i') }}.
        </div>
    @endif
</div>
