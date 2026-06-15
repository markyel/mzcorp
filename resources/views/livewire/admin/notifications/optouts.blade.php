@php
    $short = [
        'order_received' => 'Принята',
        'clarification_reminder' => 'Уточнение',
        'quote_followup_reminder' => 'После КП',
        'invoice_expiring_soon' => 'Счёт истекает',
        'invoice_expired' => 'Счёт истёк',
        'order_closed_lost' => 'Закрыта',
    ];
@endphp
<div>
    <div class="mb-4 flex items-start justify-between gap-4">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Для адресов из этого списка авто-уведомления <span class="font-medium">не отправляются</span>.
            Чекбоксом отмечаются типы, которые нужно <span class="font-medium">оставить</span> (продолжать слать) —
            всё неотмеченное заглушается. Касается только авто-уведомлений; ручные письма менеджера ходят как обычно.
        </p>
        <button type="button" wire:click="toggleAddForm"
                class="inline-flex items-center px-4 py-2 bg-[#D32027] hover:bg-[#A8181E] text-white text-sm font-medium rounded shadow-sm whitespace-nowrap">
            {{ $showAddForm ? 'Отмена' : '+ Добавить' }}
        </button>
    </div>

    @if($flashMessage)
        <div class="mb-3 p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">{{ $flashMessage }}</div>
    @endif
    @if($flashError)
        <div class="mb-3 p-3 rounded bg-amber-50 border border-amber-200 text-amber-800 text-sm">{{ $flashError }}</div>
    @endif

    {{-- Add form --}}
    @if($showAddForm)
        <div class="mb-4 rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <form wire:submit.prevent="add" class="space-y-3">
                <div>
                    <label class="block text-xs text-gray-600 mb-1">E-mail клиента</label>
                    <input type="text" wire:model="newEmail" placeholder="client@example.com"
                           class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 font-mono">
                    @error('newEmail') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs text-gray-600 mb-1">Оставить (отмеченные типы продолжат приходить)</label>
                    <div class="flex flex-wrap gap-x-5 gap-y-2">
                        @foreach($this->types as $type)
                            <label class="inline-flex items-center gap-2 text-sm" title="{{ $type->description() }}">
                                <input type="checkbox" wire:model="newKeep" value="{{ $type->value }}"
                                       class="rounded border-gray-300 text-[#D32027] focus:ring-[#D32027]">
                                {{ $type->label() }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-gray-600 mb-1">Комментарий <span class="text-gray-400">(необязательно)</span></label>
                    <input type="text" wire:model="newComment" placeholder="Почему не слать"
                           class="w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600">
                </div>

                <div class="flex justify-end">
                    <button type="submit"
                            class="inline-flex items-center px-4 py-2 bg-[#D32027] hover:bg-[#A8181E] text-white text-sm font-medium rounded shadow-sm">
                        Сохранить
                    </button>
                </div>
            </form>
        </div>
    @endif

    {{-- Filter --}}
    <div class="mb-3 flex items-center gap-3 text-sm">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Поиск по адресу…"
               class="flex-1 rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600">
        <span class="text-xs text-gray-500 whitespace-nowrap">всего: {{ $this->entries->count() }}</span>
    </div>

    {{-- Table --}}
    @if($this->entries->isEmpty())
        <div class="rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-8 text-center text-gray-500">
            Стоп-лист пуст. Добавьте e-mail клиента, который просил не слать авто-уведомления.
        </div>
    @else
        <div class="overflow-x-auto rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-400 text-xs uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">E-mail</th>
                        <th class="px-3 py-2 text-left">Оставленные типы (отмечено = слать)</th>
                        <th class="px-3 py-2 text-left">Комментарий</th>
                        <th class="px-3 py-2 text-left">Кто</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($this->entries as $entry)
                        <tr wire:key="optout-{{ $entry->id }}">
                            <td class="px-3 py-2 font-mono text-xs align-top">{{ $entry->email }}</td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                                    @foreach($this->types as $type)
                                        <label class="inline-flex items-center gap-1.5 text-xs whitespace-nowrap"
                                               title="{{ $type->label() }}">
                                            <input type="checkbox"
                                                   @checked(! $entry->suppresses($type))
                                                   wire:click="toggleType({{ $entry->id }}, '{{ $type->value }}')"
                                                   class="rounded border-gray-300 text-[#D32027] focus:ring-[#D32027]">
                                            {{ $short[$type->value] ?? $type->label() }}
                                        </label>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-3 py-2 text-gray-600 max-w-xs truncate align-top" title="{{ $entry->comment }}">
                                {{ $entry->comment ?: '—' }}
                            </td>
                            <td class="px-3 py-2 text-gray-600 text-xs align-top">{{ $entry->createdBy?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-right align-top">
                                <button type="button"
                                        wire:click="delete({{ $entry->id }})"
                                        wire:confirm="Убрать «{{ $entry->email }}» из стоп-листа?"
                                        class="text-xs text-gray-500 hover:text-red-600">Удалить</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
