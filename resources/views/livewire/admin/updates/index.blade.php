<div>
    @if($this->entries->isEmpty())
        <div class="ds-card">
            <div class="ds-card-body">
                <div class="text-center text-fg-3 py-10 text-[13px]">Записей пока нет. Создайте первую.</div>
            </div>
        </div>
    @else
        <div class="ds-card">
            <div class="ds-card-body !p-0">
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="text-left text-fg-3 text-[11.5px] uppercase tracking-wider border-b border-border">
                            <th class="px-4 py-2.5 font-medium">Заголовок</th>
                            <th class="px-3 py-2.5 font-medium w-[120px]">Статус</th>
                            <th class="px-3 py-2.5 font-medium w-[140px]">Опубликовано</th>
                            <th class="px-3 py-2.5 font-medium w-[220px] text-right">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->entries as $entry)
                            <tr class="border-b border-border-subtle last:border-0" wire:key="mupd-{{ $entry->id }}">
                                <td class="px-4 py-2.5 text-fg-1">{{ $entry->title }}</td>
                                <td class="px-3 py-2.5">
                                    @if($entry->is_published)
                                        <span class="inline-flex items-center gap-1 text-emerald-700">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> опубл.
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-fg-3">
                                            <span class="w-1.5 h-1.5 rounded-full bg-neutral-400"></span> черновик
                                        </span>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-fg-3">
                                    {{ $entry->published_at ? $entry->published_at->translatedFormat('d.m.Y') : '—' }}
                                </td>
                                <td class="px-3 py-2.5">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('updates.edit', $entry) }}"
                                           class="text-sky-700 hover:underline">Редактировать</a>
                                        <button type="button" wire:click="togglePublish({{ $entry->id }})"
                                                class="text-fg-2 hover:text-fg-1">
                                            {{ $entry->is_published ? 'Снять' : 'Опубликовать' }}
                                        </button>
                                        <button type="button" wire:click="delete({{ $entry->id }})"
                                                wire:confirm="Удалить запись «{{ $entry->title }}»?"
                                                class="text-red-700 hover:underline">Удалить</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
