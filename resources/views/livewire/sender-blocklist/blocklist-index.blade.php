<div>
    <div class="mb-4 flex items-start justify-between gap-4">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Письма от адресов и доменов в этом списке полностью игнорируются: <span class="font-medium">не создают заявок</span>,
            не запускают AI-классификатор и не уходят в подпапки менеджеров.
            Записи типа <span class="font-mono text-xs">domain</span> ловят отправителя <em>и</em> все его поддомены
            (<span class="font-mono text-xs">paulschaab.de</span> → <span class="font-mono text-xs">mail.paulschaab.de</span>).
        </p>
        <button type="button" wire:click="toggleAddForm"
                class="inline-flex items-center px-4 py-2 bg-[#D32027] hover:bg-[#A8181E] text-white text-sm font-medium rounded shadow-sm whitespace-nowrap">
            {{ $showAddForm ? 'Отмена' : '+ Добавить' }}
        </button>
    </div>

    @if($flashMessage)
        <div class="mb-3 p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
            {{ $flashMessage }}
        </div>
    @endif
    @if($flashError)
        <div class="mb-3 p-3 rounded bg-amber-50 border border-amber-200 text-amber-800 text-sm">
            {{ $flashError }}
        </div>
    @endif

    {{-- Add form --}}
    @if($showAddForm)
        <div class="mb-4 rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <div class="mb-3 flex items-center gap-4 text-sm">
                <label class="inline-flex items-center gap-2">
                    <input type="radio" wire:model.live="bulkMode" value="0" name="mode">
                    Одну запись
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="radio" wire:model.live="bulkMode" value="1" name="mode">
                    Массово (по одной в строке)
                </label>
            </div>

            <form wire:submit.prevent="add" class="space-y-3">
                @if(! $bulkMode)
                    <div class="grid grid-cols-12 gap-3">
                        <div class="col-span-3">
                            <label class="block text-xs text-gray-600 mb-1">Тип</label>
                            <select wire:model="singleType" class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                                <option value="email">Адрес (email)</option>
                                <option value="domain">Домен</option>
                            </select>
                        </div>
                        <div class="col-span-9">
                            <label class="block text-xs text-gray-600 mb-1">
                                {{ $singleType === 'email' ? 'Email-адрес' : 'Домен' }}
                            </label>
                            <input type="text" wire:model="singleValue"
                                   placeholder="{{ $singleType === 'email' ? 'spam@example.com' : 'example.com' }}"
                                   class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono">
                            @error('singleValue') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @else
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">
                            Список (одна запись в строке; тип определяется автоматически — есть «@», значит email)
                        </label>
                        <textarea wire:model="bulkValues" rows="6"
                                  placeholder="spam@example.com&#10;example.com&#10;mail.evil.net"
                                  class="w-full text-sm font-mono rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600"></textarea>
                        @error('bulkValues') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                    </div>
                @endif

                <div>
                    <label class="block text-xs text-gray-600 mb-1">Комментарий <span class="text-gray-400">(необязательно)</span></label>
                    <input type="text" wire:model="comment" placeholder="Почему блокируем"
                           class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                </div>

                <div class="flex justify-end">
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-[#D32027] hover:bg-[#A8181E] text-white text-sm font-medium rounded shadow-sm">
                        Добавить в стоп-лист
                    </button>
                </div>
            </form>
        </div>
    @endif

    {{-- Filters --}}
    <div class="mb-3 flex items-center gap-3 text-sm">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Поиск по адресу/домену…"
               class="flex-1 rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600">
        <select wire:model.live="typeFilter" class="rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600">
            <option value="">Все типы</option>
            <option value="email">Только email</option>
            <option value="domain">Только домены</option>
        </select>
        <select wire:model.live="sourceFilter" class="rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600">
            <option value="">Все источники</option>
            <option value="manual">Вручную</option>
            <option value="from_request">Из заявки</option>
        </select>
        <span class="text-xs text-gray-500 whitespace-nowrap">всего: {{ $this->totalCount }}</span>
    </div>

    {{-- Table --}}
    @if($this->entries->isEmpty())
        <div class="rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-8 text-center text-gray-500">
            Стоп-лист пуст. Добавьте первую запись или закройте заявку как «спам» — отправитель попадёт сюда автоматически.
        </div>
    @else
        <div class="overflow-x-auto rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-400 text-xs uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">Тип</th>
                        <th class="px-3 py-2 text-left">Значение</th>
                        <th class="px-3 py-2 text-left">Комментарий</th>
                        <th class="px-3 py-2 text-left">Источник</th>
                        <th class="px-3 py-2 text-left">Кто</th>
                        <th class="px-3 py-2 text-right">Срабатываний</th>
                        <th class="px-3 py-2 text-left">Добавлено</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($this->entries as $entry)
                        <tr wire:key="entry-{{ $entry->id }}">
                            <td class="px-3 py-2">
                                <span class="inline-block px-2 py-0.5 rounded text-xs {{ $entry->type->value === 'email' ? 'bg-blue-100 text-blue-800' : 'bg-purple-100 text-purple-800' }}">
                                    {{ $entry->type->label() }}
                                </span>
                            </td>
                            <td class="px-3 py-2 font-mono text-xs">
                                {{ $entry->value }}
                                @if($entry->value !== $entry->normalized_value)
                                    <div class="text-[10px] text-gray-400">→ {{ $entry->normalized_value }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-600 max-w-xs truncate" title="{{ $entry->comment }}">
                                {{ $entry->comment ?: '—' }}
                            </td>
                            <td class="px-3 py-2 text-xs">
                                @if($entry->source->value === 'from_request' && $entry->addedFromRequest)
                                    <a href="{{ route('requests.show', $entry->addedFromRequest) }}"
                                       class="text-[#D32027] hover:underline">
                                        Из {{ $entry->addedFromRequest->internal_code }}
                                    </a>
                                @else
                                    <span class="text-gray-500">{{ $entry->source->label() }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-gray-600 text-xs">
                                {{ $entry->addedBy?->name ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right text-gray-600">
                                {{ $entry->hit_count }}
                                @if($entry->last_hit_at)
                                    <div class="text-[10px] text-gray-400">{{ $entry->last_hit_at->diffForHumans() }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-500">
                                {{ $entry->created_at->format('d.m.Y H:i') }}
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button type="button"
                                        wire:click="delete({{ $entry->id }})"
                                        wire:confirm="Снять блок с «{{ $entry->value }}»?"
                                        class="text-xs text-gray-500 hover:text-red-600">
                                    Удалить
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
