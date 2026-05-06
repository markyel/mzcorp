<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Правила маршрутизации почты
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(session('status'))
                <div class="mb-4 p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <livewire:mail-rules.rule-list />
        </div>
    </div>
</x-app-layout>
