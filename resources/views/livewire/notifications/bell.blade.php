<div wire:poll.30s class="relative" x-data="{ open: @entangle('open') }" @click.outside="$wire.close()">
    @php $count = $this->unreadCount; @endphp
    <button type="button" wire:click="toggle"
            class="relative inline-flex items-center justify-center w-8 h-8 rounded-md text-fg-2 hover:text-fg-1 hover:bg-[var(--bg-surface-2)]"
            title="Уведомления ({{ $count }})">
        <span class="text-[16px]">🔔</span>
        @if($count > 0)
            <span class="absolute -top-0.5 -right-0.5 min-w-[16px] h-[16px] px-1 rounded-full bg-[var(--red-600)] text-white text-[10px] font-bold leading-[16px] text-center">
                {{ $count > 99 ? '99+' : $count }}
            </span>
        @endif
    </button>

    @if($open)
        @php $items = $this->recent; @endphp
        <div class="absolute right-0 top-full mt-1 z-50 w-[360px] max-h-[480px] overflow-auto bg-surface border border-border rounded-md shadow-lg text-[12.5px]"
             wire:click.stop>
            <div class="px-3 py-2 border-b border-border-subtle flex items-center gap-2">
                <span class="font-semibold text-fg-1">Уведомления</span>
                <span class="text-fg-3 text-[11.5px]">{{ $count }} непрочитано</span>
                <span class="flex-1"></span>
                @if($count > 0)
                    <button type="button" wire:click="markAllRead" class="text-[11.5px] text-sky-700 hover:underline">Прочитать всё</button>
                @endif
            </div>

            @if($items->isEmpty())
                <div class="p-6 text-center text-fg-3">Пока тут пусто.</div>
            @else
                <ul>
                    @foreach($items as $n)
                        @php
                            $data = $n->data;
                            $isUnread = $n->read_at === null;
                            $kind = $data['kind'] ?? 'unknown';
                            $icon = match($kind) {
                                'request_assigned' => '📥',
                                'attention_overdue' => '⚡',
                                'openai_circuit_opened' => '⛔',
                                default => '🔔',
                            };
                            $title = match($kind) {
                                'request_assigned' => 'Новая заявка ' . ($data['internal_code'] ?? ''),
                                'attention_overdue' => 'Просрочено: ' . ($data['internal_code'] ?? ''),
                                'openai_circuit_opened' => 'OpenAI недоступен — категоризатор на паузе',
                                default => 'Уведомление',
                            };
                            $subtitle = match($kind) {
                                'request_assigned' => ($data['client_name'] ?? '') . ' · ' . ($data['subject'] ?? ''),
                                'attention_overdue' => ($data['status_label'] ?? '') . ' · ' . ($data['attention_reason'] ?? ''),
                                'openai_circuit_opened' => 'Подряд ошибок: ' . ($data['fail_count'] ?? 0) . ' · пауза ' . ($data['cooldown_minutes'] ?? 15) . ' мин',
                                default => '',
                            };
                            $reqId = $data['request_id'] ?? null;
                            $href = $kind === 'openai_circuit_opened'
                                ? 'https://platform.openai.com/account/billing'
                                : ($reqId ? route('requests.show', $reqId) : '#');
                        @endphp
                        <li wire:key="notif-{{ $n->id }}" class="border-b border-border-subtle last:border-b-0 {{ $isUnread ? 'bg-[var(--sky-50)]' : '' }}">
                            <a href="{{ $href }}"
                               @if(str_starts_with($href, 'http')) target="_blank" rel="noopener" @endif
                               wire:click="markRead('{{ $n->id }}')"
                               class="block px-3 py-2 hover:bg-surface-2">
                                <div class="flex items-start gap-2">
                                    <span class="text-[16px] leading-none mt-0.5 shrink-0">{{ $icon }}</span>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-fg-1 font-medium truncate">{{ $title }}</div>
                                        @if($subtitle !== ' · ')
                                            <div class="text-fg-3 text-[11.5px] truncate">{{ $subtitle }}</div>
                                        @endif
                                        <div class="text-fg-4 text-[10.5px] mt-0.5">{{ $n->created_at->diffForHumans() }}</div>
                                    </div>
                                    @if($isUnread)
                                        <span class="w-2 h-2 rounded-full bg-[var(--sky-600)] mt-1.5 shrink-0"></span>
                                    @endif
                                </div>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
