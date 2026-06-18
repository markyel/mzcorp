<div class="space-y-4">
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Поставщики</h3>
            <span class="text-[12px] text-fg-3 ml-2">запросы расценки поставщикам (переписка, не клиентские заявки)</span>
            <span class="flex-1"></span>
            <span class="text-[11.5px] text-fg-3 mono">{{ $this->inquiries->total() }}</span>
        </div>

        <div class="px-4 pb-3">
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Поиск: e-mail / название / тема"
                   class="h-[30px] w-full max-w-[440px] px-2.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500">
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-[12.5px]">
                <thead class="text-fg-3 text-[10.5px] uppercase tracking-wider border-y border-border">
                    <tr>
                        <th class="text-left px-3 py-2">Поставщик</th>
                        <th class="text-left px-3 py-2">Тема запроса</th>
                        <th class="text-right px-3 py-2">Писем</th>
                        <th class="text-left px-3 py-2">Статус</th>
                        <th class="text-left px-3 py-2">Кто пометил</th>
                        <th class="text-left px-3 py-2">Дата</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->inquiries as $i)
                        <tr wire:key="inq-{{ $i->id }}" class="border-b border-border-subtle hover:bg-hover">
                            <td class="px-3 py-2">
                                <a href="{{ route('suppliers.show', $i->id) }}" wire:navigate class="text-sky-700 hover:underline font-medium">{{ $i->supplier_name ?: $i->supplier_email }}</a>
                                @if($i->supplier_name)<div class="text-[11px] text-fg-4 mono">{{ $i->supplier_email }}</div>@endif
                            </td>
                            <td class="px-3 py-2 text-fg-2"><span class="truncate inline-block max-w-[300px] align-bottom">{{ $i->subject ?: '—' }}</span></td>
                            <td class="px-3 py-2 text-right mono text-fg-2">{{ $i->messages_count }}</td>
                            <td class="px-3 py-2">
                                <span class="chip {{ $i->status === 'closed' ? 'chip-neutral' : 'chip-sky' }} text-[10.5px]">{{ $i->status === 'closed' ? 'закрыт' : 'открыт' }}</span>
                            </td>
                            <td class="px-3 py-2 text-fg-3 whitespace-nowrap">{{ $i->createdBy?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-fg-3 mono whitespace-nowrap">{{ $i->created_at?->format('d.m.Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-10 text-center text-fg-3 text-[13px]">{{ trim($search) !== '' ? 'Ничего не найдено.' : 'Пока нет запросов поставщикам. Пометьте тред на карточке заявки кнопкой «📦 Это запрос поставщику».' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="px-4 py-3">{{ $this->inquiries->links() }}</div>
        </div>
    </div>
</div>
