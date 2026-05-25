<div class="max-w-[1440px] mx-auto px-6 py-6">
    <div class="flex items-end justify-between mb-4 gap-4">
        <div>
            <h1 class="text-[20px] font-semibold text-fg-1">Обращения · инбокс</h1>
            <p class="text-[12.5px] text-fg-3 mt-0.5">Все тикеты пользователей. Открытые — сверху.</p>
        </div>
        <a href="{{ route('support.my') }}" class="text-[12.5px] text-sky-700 hover:underline">Мои обращения →</a>
    </div>

    @if(session('support_status'))
        <div class="mb-3 px-3 py-2 rounded-md border border-[var(--emerald-600)] bg-[var(--emerald-50,#ecfdf5)] text-[13px] text-fg-1">
            {{ session('support_status') }}
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-1 mb-3">
        <button wire:click="setStatus('open_any')"
                class="chip {{ $statusFilter === 'open_any' ? 'chip-attn' : 'chip-neutral' }}">Открытые</button>
        @foreach($statuses as $s)
            <button wire:click="setStatus('{{ $s->value }}')"
                    class="chip {{ $statusFilter === $s->value ? $s->chipClass() : 'chip-neutral' }}">{{ $s->label() }}</button>
        @endforeach
        <button wire:click="setStatus('all')"
                class="chip {{ $statusFilter === 'all' ? 'chip-info' : 'chip-neutral' }}">Все</button>

        <div class="flex-1"></div>
        <input type="search" wire:model.live.debounce.400ms="search"
               placeholder="Поиск по теме, тексту, имени, e-mail"
               class="h-[30px] w-[280px] px-3 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-[var(--sky-500)]" />
    </div>

    <div class="ds-card overflow-hidden">
        @if($tickets->isEmpty())
            <div class="p-8 text-center text-fg-3 text-[13px]">
                Ничего не найдено.
            </div>
        @else
            <table class="w-full text-[13px]">
                <thead class="bg-[var(--bg-app)] border-b border-border text-[11.5px] uppercase tracking-wider text-fg-3">
                    <tr>
                        <th class="text-left px-3 py-2 w-[70px]">#</th>
                        <th class="text-left px-3 py-2">Тема · автор</th>
                        <th class="text-left px-3 py-2 w-[160px]">Контекст</th>
                        <th class="text-left px-3 py-2 w-[140px]">Статус</th>
                        <th class="text-left px-3 py-2 w-[140px]">Создан</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tickets as $t)
                        @php
                            $ctx = $t->context ?? [];
                            $roleLabels = collect($ctx['roles_snapshot'] ?? [])
                                ->map(fn ($r) => \App\Enums\Role::tryFrom($r)?->label() ?? $r)
                                ->implode(', ');
                        @endphp
                        <tr wire:key="ti-{{ $t->id }}" class="border-b border-border-subtle hover:bg-[var(--bg-hover)] align-top">
                            <td class="px-3 py-2 font-mono text-fg-3">#{{ $t->id }}</td>
                            <td class="px-3 py-2">
                                <a href="{{ route('support.show', $t) }}" class="text-fg-1 hover:underline font-medium block">
                                    {{ $t->subject }}
                                </a>
                                <div class="text-[11.5px] text-fg-3 mt-0.5">
                                    {{ $t->user?->name }} ({{ $t->user?->email }})
                                    @if($roleLabels) · <span class="text-fg-3">{{ $roleLabels }}</span> @endif
                                </div>
                            </td>
                            <td class="px-3 py-2 text-[11.5px] text-fg-3 font-mono">
                                @if(!empty($ctx['route_name']))
                                    {{ $ctx['route_name'] }}
                                @elseif(!empty($ctx['url']))
                                    {{ \Illuminate\Support\Str::limit($ctx['url'], 28) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <span class="chip {{ $t->status->chipClass() }}">{{ $t->status->label() }}</span>
                            </td>
                            <td class="px-3 py-2 text-fg-2 font-mono text-[12px]">{{ $t->created_at?->format('d.m.Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="mt-3">{{ $tickets->links() }}</div>
</div>
