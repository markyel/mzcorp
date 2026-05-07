@php
    use App\Enums\RequestStatus;

    $statusFilters = [
        ''                             => ['label' => 'Все',      'count' => null],
        RequestStatus::New->value      => ['label' => 'Новые',    'count' => $statusCounts['new']],
        RequestStatus::Assigned->value => ['label' => 'В работе', 'count' => $statusCounts['assigned']],
    ];
    if ($this->canSeeAll && isset($statusCounts['pending'])) {
        $statusFilters[RequestStatus::Pending->value] = [
            'label' => 'В обработке',
            'count' => $statusCounts['pending'],
        ];
    }
@endphp

<div>
    {{-- Filter bar --}}
    <div class="flex flex-wrap items-center gap-3 mb-4">

        {{-- Scope: «Мои» / «Все» — только для привилегированных ролей --}}
        @if($this->canSeeAll)
            <div class="inline-flex border border-border rounded-md bg-surface overflow-hidden text-sm">
                <button type="button" wire:click="$set('scope', 'mine')"
                        class="px-3 py-1.5 transition-colors
                               {{ $effectiveScope === 'mine'
                                  ? 'bg-accent text-fg-on-accent'
                                  : 'text-fg-2 hover:text-fg-1 hover:bg-hover' }}">
                    Мои <span class="opacity-75 mono">({{ $totals['mine'] }})</span>
                </button>
                <button type="button" wire:click="$set('scope', 'all')"
                        class="px-3 py-1.5 transition-colors border-l border-border
                               {{ $effectiveScope === 'all'
                                  ? 'bg-accent text-fg-on-accent'
                                  : 'text-fg-2 hover:text-fg-1 hover:bg-hover' }}">
                    Все <span class="opacity-75 mono">({{ $totals['all'] }})</span>
                </button>
            </div>
        @endif

        {{-- Status filter chips --}}
        <div class="inline-flex border border-border rounded-md bg-surface overflow-hidden text-sm">
            @foreach($statusFilters as $value => $meta)
                @php $active = $status === $value; @endphp
                <button type="button" wire:click="$set('status', '{{ $value }}')"
                        class="px-3 py-1.5 transition-colors {{ !$loop->first ? 'border-l border-border' : '' }}
                               {{ $active
                                  ? 'bg-fg-1 text-fg-on-accent'
                                  : 'text-fg-2 hover:text-fg-1 hover:bg-hover' }}">
                    {{ $meta['label'] }}
                    @if($meta['count'] !== null)
                        <span class="opacity-75 mono">({{ $meta['count'] }})</span>
                    @endif
                </button>
            @endforeach
        </div>

        {{-- Search --}}
        <div class="relative flex-1 min-w-[260px] max-w-[480px]">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-fg-3 select-none">⌕</span>
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Код, тема, клиент..."
                   class="w-full h-[30px] pl-8 pr-3 border-border rounded-md bg-app text-fg-1 text-sm focus:border-sky-500 focus:ring-2 focus:ring-[var(--ring)]">
        </div>

        <div class="flex-1"></div>

        <div class="text-sm text-fg-3 mono">
            {{ $requests->total() }} {{ \Illuminate\Support\Str::plural('запис', $requests->total()) }}
        </div>
    </div>

    @if($requests->isEmpty())
        <div class="ds-card p-8 text-center text-fg-3">
            @if($effectiveScope === 'mine' && $search === '' && $status === '')
                Все заявки разобраны. Хорошая работа.
            @else
                Под фильтр ничего не попало.
            @endif
        </div>
    @else
        <div class="ds-card overflow-hidden">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="bg-surface-2 text-fg-3 text-[11px] uppercase tracking-wider">
                        <th class="text-left font-semibold px-3" style="height: 32px">код</th>
                        <th class="text-left font-semibold px-3">заявка</th>
                        <th class="text-left font-semibold px-3">клиент</th>
                        <th class="text-left font-semibold px-3 whitespace-nowrap">статус</th>
                        <th class="text-left font-semibold px-3 whitespace-nowrap">менеджер</th>
                        <th class="text-right font-semibold px-3 whitespace-nowrap">позиций</th>
                        <th class="text-right font-semibold px-3 whitespace-nowrap">возраст</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($requests as $req)
                        @php
                            $href = route('requests.show', $req);
                            $chipCls = match ($req->status) {
                                RequestStatus::Pending  => 'chip-paused',
                                RequestStatus::New      => 'chip-attn',
                                RequestStatus::Assigned => 'chip-info',
                            };
                            $age = $req->created_at?->diffForHumans(['short' => true, 'parts' => 1]);
                        @endphp
                        <tr wire:key="req-{{ $req->id }}"
                            class="border-t border-border-subtle hover:bg-hover transition-colors cursor-pointer"
                            onclick="if (event.target.tagName !== 'A') window.location='{{ $href }}'">
                            <td class="px-3" style="height: var(--row-h)">
                                <a href="{{ $href }}" class="mono text-accent hover:underline whitespace-nowrap">{{ $req->internal_code }}</a>
                            </td>
                            <td class="px-3 max-w-[480px]">
                                <div class="truncate text-fg-1">{{ $req->subject ?: '(без темы)' }}</div>
                            </td>
                            <td class="px-3">
                                <div class="text-fg-1 truncate max-w-[220px]">{{ $req->client_name ?: $req->client_email }}</div>
                                @if($req->client_name)
                                    <div class="text-[11.5px] text-fg-3 truncate max-w-[220px]">{{ $req->client_email }}</div>
                                @endif
                            </td>
                            <td class="px-3">
                                <span class="chip {{ $chipCls }}"><span class="dot"></span>{{ $req->status->label() }}</span>
                            </td>
                            <td class="px-3 text-fg-2 whitespace-nowrap">{{ $req->assignedUser?->name ?? '—' }}</td>
                            <td class="px-3 text-right mono whitespace-nowrap">
                                @if($req->items_count > 0)
                                    <span class="text-fg-1">{{ $req->items_count }}</span>
                                @else
                                    <span class="text-fg-4">—</span>
                                @endif
                            </td>
                            <td class="px-3 text-right mono text-fg-3 whitespace-nowrap">{{ $age ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $requests->links() }}</div>
    @endif
</div>
