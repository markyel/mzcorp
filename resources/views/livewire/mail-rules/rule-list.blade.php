<div>
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Правила обрабатываются в порядке возрастания приоритета. Первое совпавшее
            <span class="font-medium">terminal</span>-правило завершает цепочку.
        </p>
        <a href="{{ route('mail-rules.create') }}"
           class="inline-flex items-center px-4 py-2 bg-[#D32027] hover:bg-[#A8181E] text-white text-sm font-medium rounded shadow-sm">
            + Новое правило
        </a>
    </div>

    @if($this->rules->isEmpty())
        <div class="rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-8 text-center text-gray-500">
            Правил пока нет. Создайте первое — оно начнёт применяться к новым входящим письмам.
        </div>
    @else
        <div class="overflow-x-auto rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900 text-gray-600 dark:text-gray-400 text-xs uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">prio</th>
                        <th class="px-3 py-2 text-left">Имя</th>
                        <th class="px-3 py-2 text-left">Mode</th>
                        <th class="px-3 py-2 text-left">Action</th>
                        <th class="px-3 py-2 text-left">Label</th>
                        <th class="px-3 py-2 text-left">Forward</th>
                        <th class="px-3 py-2 text-center">Active</th>
                        <th class="px-3 py-2 text-center">Term.</th>
                        <th class="px-3 py-2 text-right">Matches</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($this->rules as $rule)
                        <tr wire:key="rule-{{ $rule->id }}" class="{{ $rule->is_active ? '' : 'opacity-50' }}">
                            <td class="px-3 py-2 font-mono text-gray-500">{{ $rule->priority }}</td>
                            <td class="px-3 py-2 font-medium">
                                <a href="{{ route('mail-rules.edit', $rule) }}" class="text-[#D32027] hover:underline">
                                    {{ $rule->name }}
                                </a>
                            </td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ $rule->match_mode->value }}</td>
                            <td class="px-3 py-2">
                                <span class="inline-block px-2 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-700">
                                    {{ $rule->action_type->label() }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-gray-600 font-mono text-xs">{{ $rule->label ?: '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $rule->forward_to_email ?: '—' }}</td>
                            <td class="px-3 py-2 text-center">
                                <button type="button" wire:click="toggleActive({{ $rule->id }})"
                                        class="text-xs px-2 py-0.5 rounded {{ $rule->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-200 text-gray-600' }}">
                                    {{ $rule->is_active ? 'on' : 'off' }}
                                </button>
                            </td>
                            <td class="px-3 py-2 text-center text-gray-500">{{ $rule->is_terminal ? '✓' : '—' }}</td>
                            <td class="px-3 py-2 text-right text-gray-600">{{ $rule->match_count }}</td>
                            <td class="px-3 py-2 text-right">
                                <button type="button"
                                        wire:click="delete({{ $rule->id }})"
                                        wire:confirm="Удалить правило «{{ $rule->name }}»?"
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
