<div class="max-w-[1200px] mx-auto px-6 py-6">
    <div class="flex items-end justify-between mb-4 gap-4">
        <div>
            <h1 class="text-[20px] font-semibold text-fg-1">Мои обращения</h1>
            <p class="text-[12.5px] text-fg-3 mt-0.5">Ваши тикеты к создателю системы. Новый тикет — иконка в шапке.</p>
        </div>
    </div>

    @if(session('support_status'))
        <div class="mb-3 px-3 py-2 rounded-md border border-[var(--emerald-600)] bg-[var(--emerald-50,#ecfdf5)] text-[13px] text-fg-1">
            {{ session('support_status') }}
        </div>
    @endif

    <div class="flex items-center gap-1 mb-3">
        <button wire:click="setStatus('all')"
                class="chip {{ $statusFilter === 'all' ? 'chip-info' : 'chip-neutral' }}">Все</button>
        @foreach($statuses as $s)
            <button wire:click="setStatus('{{ $s->value }}')"
                    class="chip {{ $statusFilter === $s->value ? $s->chipClass() : 'chip-neutral' }}">{{ $s->label() }}</button>
        @endforeach
    </div>

    <div class="ds-card overflow-hidden">
        @if($tickets->isEmpty())
            <div class="p-8 text-center text-fg-3 text-[13px]">
                Тут пусто. Вы ещё не отправляли тикетов.
            </div>
        @else
            <table class="w-full text-[13px]">
                <thead class="bg-[var(--bg-app)] border-b border-border text-[11.5px] uppercase tracking-wider text-fg-3">
                    <tr>
                        <th class="px-2 py-2 w-[36px]" title="Флажок «вернуться позже»"></th>
                        <th class="text-left px-3 py-2 w-[70px]">#</th>
                        <th class="text-left px-3 py-2">Тема</th>
                        <th class="text-left px-3 py-2 w-[140px]">Статус</th>
                        <th class="text-left px-3 py-2 w-[140px]">Создан</th>
                        <th class="text-left px-3 py-2 w-[140px]">Обновлён</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tickets as $t)
                        @php
                            $isUnread = isset($unreadIds[$t->id]);
                            $isFlagged = $t->flagged_at !== null;
                        @endphp
                        <tr wire:key="ticket-{{ $t->id }}"
                            class="border-b border-border-subtle hover:bg-[var(--bg-hover)]">
                            <td class="px-2 py-2 text-center">
                                <button type="button" wire:click="toggleFlag({{ $t->id }})"
                                        class="text-[15px] leading-none"
                                        style="{{ $isFlagged ? '' : 'opacity:.22; filter:grayscale(1);' }}"
                                        title="{{ $isFlagged ? 'Снять флажок «вернуться позже»' : 'Пометить «вернуться позже» — обращение поднимется наверх списка' }}">🚩</button>
                            </td>
                            <td class="px-3 py-2 font-mono text-fg-3">#{{ $t->id }}</td>
                            <td class="px-3 py-2">
                                <a href="{{ route('support.show', $t) }}"
                                   class="text-fg-1 hover:underline {{ ($isUnread || $isFlagged) ? 'font-bold' : 'font-medium' }}">
                                    {{ $t->subject }}
                                </a>
                                @if($isUnread)
                                    <span class="chip chip-info text-[10px] ml-1.5" title="Есть непрочитанный ответ создателя">новый ответ</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <span class="chip {{ $t->status->chipClass() }}">{{ $t->status->label() }}</span>
                            </td>
                            <td class="px-3 py-2 font-mono text-[12px] {{ $isUnread ? 'font-bold text-fg-1' : 'text-fg-2' }}">{{ $t->created_at?->format('d.m.Y H:i') }}</td>
                            <td class="px-3 py-2 font-mono text-[12px] {{ $isUnread ? 'font-bold text-fg-1' : 'text-fg-2' }}">{{ $t->updated_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="mt-3">{{ $tickets->links() }}</div>
</div>
