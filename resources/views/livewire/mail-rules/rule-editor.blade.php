<div>
    <form wire:submit="save" class="space-y-6">

        <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Имя</label>
                    <input type="text" wire:model="name" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 rounded shadow-sm">
                    @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Приоритет</label>
                    <input type="number" wire:model="priority" min="0" max="10000" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 rounded shadow-sm">
                    <p class="text-xs text-gray-500 mt-1">Меньше = раньше. Стандарт 100.</p>
                </div>
            </div>

            <div class="flex gap-6">
                <label class="inline-flex items-center text-sm">
                    <input type="checkbox" wire:model="isActive" class="rounded border-gray-300 text-[#D32027] shadow-sm">
                    <span class="ml-2">Активно</span>
                </label>
                <label class="inline-flex items-center text-sm">
                    <input type="checkbox" wire:model="isTerminal" class="rounded border-gray-300 text-[#D32027] shadow-sm">
                    <span class="ml-2">Terminal (остановиться после этого правила)</span>
                </label>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Применять к ящикам</label>
                <select wire:model="mailboxScope" multiple class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 rounded shadow-sm h-24">
                    @foreach($mailboxes as $mb)
                        <option value="{{ $mb->id }}">{{ $mb->name }} ({{ $mb->email }})</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 mt-1">Если ничего не выбрано — правило применяется ко всем активным ящикам.</p>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4 space-y-4">
            <h3 class="font-medium text-sm text-gray-800 dark:text-gray-200">Условия срабатывания</h3>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Режим</label>
                <select wire:model.live="matchMode" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 rounded shadow-sm">
                    @foreach($modes as $m)
                        <option value="{{ $m->value }}">
                            @switch($m->value)
                                @case('any_of') Хотя бы одно условие @break
                                @case('all_of') Все условия @break
                            @endswitch
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="space-y-2">
                @foreach($criteria as $i => $c)
                    <div wire:key="crit-{{ $i }}" class="flex gap-2 items-start">
                        <select wire:model="criteria.{{ $i }}.field" class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 rounded shadow-sm text-sm">
                            @foreach($fields as $f)
                                <option value="{{ $f->value }}">{{ $f->label() }}</option>
                            @endforeach
                        </select>
                        <select wire:model="criteria.{{ $i }}.op" class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 rounded shadow-sm text-sm">
                            @foreach($operators as $op)
                                <option value="{{ $op->value }}">{{ $op->label() }}</option>
                            @endforeach
                        </select>
                        <input type="text" wire:model="criteria.{{ $i }}.values"
                               placeholder="значения через запятую: рекламация, претензия, брак"
                               class="flex-1 border-gray-300 dark:border-gray-700 dark:bg-gray-900 rounded shadow-sm text-sm">
                        <button type="button" wire:click="removeCriterion({{ $i }})"
                                class="text-gray-400 hover:text-red-600 px-2">×</button>
                    </div>
                @endforeach
                <button type="button" wire:click="addCriterion"
                        class="text-xs text-[#D32027] hover:underline">+ ещё условие</button>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 p-4 space-y-4">
            <h3 class="font-medium text-sm text-gray-800 dark:text-gray-200">Действие</h3>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Что делаем</label>
                <select wire:model.live="actionType" class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 rounded shadow-sm">
                    @foreach($actions as $a)
                        <option value="{{ $a->value }}">{{ $a->label() }}</option>
                    @endforeach
                </select>
            </div>

            @if($actionType === 'forward')
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Куда переслать</label>
                    <input type="email" wire:model="forwardToEmail" placeholder="claims@myzip.ru"
                           class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 rounded shadow-sm">
                    @error('forwardToEmail') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            @endif

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">IMAP-метка</label>
                <input type="text" wire:model="label" placeholder="MyLift/Заявка"
                       class="mt-1 block w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 rounded shadow-sm font-mono text-xs">
                <p class="text-xs text-gray-500 mt-1">Метка появится у письма в Yandex веб-клиенте. Без слэшей в начале/конце.</p>
                @error('label') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex items-center gap-4">
            <button type="submit" class="px-4 py-2 bg-[#D32027] hover:bg-[#A8181E] text-white text-sm font-medium rounded shadow-sm">
                Сохранить
            </button>
            <a href="{{ route('mail-rules.index') }}" class="text-sm text-gray-600 hover:underline">Отмена</a>
        </div>
    </form>
</div>
