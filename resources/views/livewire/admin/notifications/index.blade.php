<div class="space-y-4">
    <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm">
        <h3 class="font-semibold text-base text-gray-900 mb-1">Автоматические письма клиенту</h3>
        <p class="text-sm text-gray-600 leading-relaxed">
            Список всех автоматических уведомлений, которые система отправляет клиенту от имени менеджера. Каждый тип можно включить или выключить, текст редактируется. Письма уходят как reply в треде заявки — клиент видит «продолжение переписки», его ответ автоматически попадает обратно в нужную заявку.
        </p>
    </div>

    <div class="bg-white border border-gray-200 rounded-xl shadow-sm divide-y divide-gray-100">
        @foreach($templates as $t)
            <div class="p-5 flex items-start gap-4">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-3 mb-1">
                        <h4 class="font-medium text-gray-900">{{ $t->type->label() }}</h4>
                        @if($t->is_enabled)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">включено</span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 border border-gray-200">выключено</span>
                        @endif
                    </div>
                    <p class="text-sm text-gray-600 leading-relaxed mb-2">{{ $t->type->description() }}</p>
                    <div class="text-xs text-gray-500 mono">{{ $t->subject_template }}</div>
                </div>
                <div class="flex flex-col gap-2 items-end">
                    <a href="{{ route('notifications.edit', ['template' => $t->id]) }}"
                       class="inline-flex items-center px-3 py-1.5 text-sm rounded border border-gray-300 hover:bg-gray-50">
                        Редактировать
                    </a>
                    <button type="button"
                            wire:click="toggle({{ $t->id }})"
                            class="inline-flex items-center px-3 py-1.5 text-sm rounded
                                {{ $t->is_enabled
                                    ? 'border border-amber-300 bg-amber-50 text-amber-800 hover:bg-amber-100'
                                    : 'border border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100' }}">
                        {{ $t->is_enabled ? 'Выключить' : 'Включить' }}
                    </button>
                </div>
            </div>
        @endforeach
    </div>
</div>
