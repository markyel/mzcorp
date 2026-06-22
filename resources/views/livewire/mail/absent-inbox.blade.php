<div class="space-y-4">
    @php $priv = $this->canAssign; @endphp

    <div class="ds-card">
        <div class="ds-card-header">
            <h2 class="text-[16px] font-semibold text-fg-1">✉ {{ $priv ? 'Почта выбывших' : 'Почта' }}</h2>
            <span class="text-[12px] text-fg-3 ml-2">переписка недоступных менеджеров, не привязанная к заявкам</span>
            <span class="flex-1"></span>
            @if($this->assignedToMeCount > 0)
                <span class="chip chip-sky text-[11px]">назначено мне: {{ $this->assignedToMeCount }}</span>
            @endif
        </div>
        <div class="ds-card-body">
            {{-- Фильтры --}}
            <div class="flex flex-wrap items-center gap-2 text-[12.5px]">
                {{-- по выбывшему менеджеру --}}
                <select wire:model.live="managerId" class="h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px]">
                    <option value="">Все выбывшие</option>
                    @foreach($this->absentManagers as $m)
                        <option value="{{ $m->id }}">{{ $m->name }}</option>
                    @endforeach
                </select>

                {{-- назначение --}}
                <div class="inline-flex rounded-md border border-border overflow-hidden">
                    @php $afOpts = ['' => 'Все', 'mine' => 'Назначенные мне', 'unassigned' => 'Без ответственного']; @endphp
                    @foreach($afOpts as $k => $label)
                        <button type="button" wire:click="$set('assignmentFilter', '{{ $k }}')"
                                class="h-[30px] px-2.5 whitespace-nowrap border-r border-border last:border-r-0 {{ $assignmentFilter === $k ? 'bg-[var(--accent)] text-fg-on-accent font-medium' : 'bg-surface text-fg-2 hover:text-fg-1' }}">{{ $label }}</button>
                    @endforeach
                </div>

                {{-- прочитанность --}}
                <div class="inline-flex rounded-md border border-border overflow-hidden">
                    @php $rfOpts = ['' => 'Все', 'unread' => 'Непрочит.', 'read' => 'Прочит.']; @endphp
                    @foreach($rfOpts as $k => $label)
                        <button type="button" wire:click="$set('readFilter', '{{ $k }}')"
                                class="h-[30px] px-2.5 whitespace-nowrap border-r border-border last:border-r-0 {{ $readFilter === $k ? 'bg-[var(--accent)] text-fg-on-accent font-medium' : 'bg-surface text-fg-2 hover:text-fg-1' }}">{{ $label }}</button>
                    @endforeach
                </div>

                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Поиск: тема / отправитель"
                       class="h-[30px] flex-1 min-w-[180px] px-2.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
            </div>
        </div>
    </div>

    {{-- Лента --}}
    <div class="ds-card">
        <div class="ds-card-body overflow-x-auto p-0">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-b border-border">
                    <tr>
                        <th class="text-left px-3 py-2" style="width:30px"></th>
                        <th class="text-left px-3 py-2">Отправитель</th>
                        <th class="text-left px-3 py-2">Тема</th>
                        <th class="text-left px-3 py-2" style="width:150px">Ящик (выбывший)</th>
                        <th class="text-left px-3 py-2" style="width:160px">Ответственный</th>
                        <th class="text-right px-3 py-2" style="width:120px">Дата</th>
                    </tr>
                </thead>
                @forelse($this->rows as $em)
                    @php
                        $sa = $em->sharedAssignment;
                        $isRead = $sa && $sa->read_at !== null;
                        $assignedTo = $sa?->assignedUser;
                        $iAmAssigned = $assignedTo && (int) $assignedTo->id === (int) auth()->id();
                        $isExpanded = $expandedId === $em->id;
                    @endphp
                    <tbody wire:key="sm-{{ $em->id }}">
                        <tr class="border-b border-border-subtle hover:bg-hover align-top {{ $isRead ? '' : 'font-semibold' }} {{ $iAmAssigned ? 'bg-sky-50/40' : '' }}">
                            <td class="px-3 py-2 text-center">
                                <button type="button" wire:click="toggleExpand({{ $em->id }})" class="text-fg-4 hover:text-fg-2" title="Показать письмо">{{ $isExpanded ? '▾' : '▸' }}</button>
                            </td>
                            <td class="px-3 py-2">
                                <div class="text-fg-1">{{ $em->from_name ?: '—' }}</div>
                                <div class="text-[11px] text-fg-3 mono">{{ $em->from_email }}</div>
                            </td>
                            <td class="px-3 py-2 text-fg-1">
                                {{ \Illuminate\Support\Str::limit($em->subject ?: '(без темы)', 70) }}
                                @if(! $isRead)<span class="chip chip-warn text-[10px] ml-1">новое</span>@endif
                                @if($em->attachments_count > 0)<span class="text-fg-4 text-[11px] ml-1">📎{{ $em->attachments_count }}</span>@endif
                            </td>
                            <td class="px-3 py-2 text-fg-3">{{ $em->mailbox?->owner?->name ?: ($em->mailbox?->email ?? '—') }}</td>
                            <td class="px-3 py-2">
                                @if($priv)
                                    <select wire:change="assign({{ $em->id }}, $event.target.value)"
                                            class="h-[26px] w-full px-1.5 border border-border rounded bg-surface text-[11.5px]">
                                        <option value="">— не назначен —</option>
                                        @foreach($this->assignableManagers as $am)
                                            <option value="{{ $am->id }}" @selected($assignedTo && (int)$assignedTo->id === (int)$am->id)>{{ $am->name }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    @if($assignedTo)
                                        <span class="chip {{ $iAmAssigned ? 'chip-sky' : 'chip-neutral' }} text-[11px]">{{ $assignedTo->name }}{{ $iAmAssigned ? ' (мне)' : '' }}</span>
                                    @else
                                        <span class="text-fg-4 text-[11px]">не назначен</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right mono text-fg-3 whitespace-nowrap">{{ $em->sent_at?->format('d.m.y H:i') ?: '—' }}</td>
                        </tr>

                        @if($isExpanded)
                            <tr class="border-b border-border-subtle bg-surface-2">
                                <td colspan="6" class="px-4 py-3">
                                    <div class="flex items-center gap-3 mb-2 text-[11.5px]">
                                        <span class="text-fg-3">От: <span class="mono text-fg-2">{{ $em->from_email }}</span></span>
                                        @if($isRead)
                                            <button type="button" wire:click="markUnread({{ $em->id }})" class="text-sky-700 hover:underline">↺ снять прочитанность</button>
                                        @endif
                                        @if($iAmAssigned && $replyingId !== $em->id)
                                            <button type="button" wire:click="startReply({{ $em->id }})" class="btn btn-sm btn-primary ml-auto">✉ Ответить</button>
                                        @endif
                                    </div>

                                    @if($expandedBody !== null)
                                        @if($expandedIsHtml)
                                            <iframe srcdoc="{{ $expandedBody }}" sandbox="" class="w-full bg-white border border-border rounded" style="height:320px;"></iframe>
                                        @else
                                            <pre class="text-[12px] text-fg-2 whitespace-pre-wrap bg-surface border border-border rounded-md p-2 max-h-[320px] overflow-y-auto">{{ $expandedBody }}</pre>
                                        @endif
                                    @endif

                                    {{-- Форма ответа (только назначенный менеджер) --}}
                                    @if($iAmAssigned && $replyingId === $em->id)
                                        <div class="mt-3 border-t border-border pt-3">
                                            <div class="text-[11.5px] text-fg-3 mb-1">Ответ уйдёт с вашего ящика на <span class="mono">{{ $em->from_email }}</span></div>
                                            <textarea wire:model="replyBody" rows="5" placeholder="Текст ответа…"
                                                      class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                                            <div class="flex items-center gap-2 mt-2">
                                                <button type="button" wire:click="sendReply" wire:loading.attr="disabled" wire:target="sendReply" class="btn btn-sm btn-primary">
                                                    <span wire:loading.remove wire:target="sendReply">Отправить</span>
                                                    <span wire:loading wire:target="sendReply">Отправляю…</span>
                                                </button>
                                                <button type="button" wire:click="cancelReply" class="btn btn-sm">Отмена</button>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    </tbody>
                @empty
                    <tbody>
                        <tr><td colspan="6" class="px-3 py-10 text-center text-fg-3 text-[13px]">
                            @if($this->absentManagers->isEmpty())
                                Сейчас нет недоступных менеджеров.
                            @else
                                Нет писем по выбранным фильтрам.
                            @endif
                        </td></tr>
                    </tbody>
                @endforelse
            </table>
        </div>
        <div class="px-4 py-3">{{ $this->rows->links() }}</div>
    </div>
</div>
