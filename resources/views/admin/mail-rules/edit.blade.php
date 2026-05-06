<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ isset($rule) && $rule->exists ? 'Редактировать правило' : 'Новое правило' }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <livewire:mail-rules.rule-editor :rule="$rule ?? null" />
        </div>
    </div>
</x-app-layout>
