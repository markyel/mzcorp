<div>
    <div class="mb-4 flex flex-wrap items-center gap-3">
        @if($this->canSeeAll)
            <div class="inline-flex rounded border border-gray-200 dark:border-gray-700 overflow-hidden text-sm">
                <button type="button" wire:click="$set('scope', 'mine')"
                        class="px-3 py-1.5 {{ $effectiveScope === 'mine' ? 'bg-[#D32027] text-white' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300' }}">
                    Мои <span class="opacity-75">({{ $totals['mine'] }})</span>
                </button>
                <button type="button" wire:click="$set('scope', 'all')"
                        class="px-3 py-1.5 {{ $effectiveScope === 'all' ? 'bg-[#D32027] text-white' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300' }}">
                    Все <span class="opacity-75">({{ $totals['all'] }})</span>
                </button>
            </div>
        @endif

        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Код, тема, email клиента..."
               class="flex-1 min-w-[220px] border-gray-300 dark:border-gray-700 dark:bg-gray-900 rounded shadow-sm text-sm">
    </div>

    @if($requests->isEmpty())
        <div class="rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-8 text-center text-gray-500">
            @if($effectiveScope === 'mine')
                Все заявки разобраны. Хорошая работа.
            @else
                Заявок не найдено.
            @endif
        </div>
    @else
        <div class="overflow-x-auto rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-400 text-xs uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">Код</th>
                        <th class="px-3 py-2 text-left">Клиент</th>
                        <th class="px-3 py-2 text-left">Тема</th>
                        <th class="px-3 py-2 text-left">Менеджер</th>
                        <th class="px-3 py-2 text-left">Статус</th>
                        <th class="px-3 py-2 text-right whitespace-nowrap">Создана</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($requests as $req)
                        <tr wire:key="req-{{ $req->id }}" class="hover:bg-gray-50 dark:hover:bg-gray-900/40 cursor-pointer"
                            onclick="window.location='{{ route('requests.show', $req) }}'">
                            <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                                <a href="{{ route('requests.show', $req) }}" class="text-[#D32027] hover:underline">
                                    {{ $req->internal_code }}
                                </a>
                            </td>
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ $req->client_name ?: $req->client_email }}</div>
                                @if($req->client_name)
                                    <div class="text-xs text-gray-500">{{ $req->client_email }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 max-w-md truncate">
                                {{ \Illuminate\Support\Str::limit($req->subject, 80) }}
                            </td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-400">
                                {{ $req->assignedUser?->name ?? '—' }}
                            </td>
                            <td class="px-3 py-2">
                                <span class="inline-block px-2 py-0.5 rounded text-xs
                                    {{ $req->status->value === 'new' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                                    {{ $req->status->label() }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right text-xs text-gray-500 whitespace-nowrap">
                                {{ $req->created_at?->format('d.m.Y H:i') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-3">{{ $requests->links() }}</div>
    @endif
</div>
