<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Авто-отклонённые письма
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(session('status'))
                <div class="mb-4 p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                    {{ session('status') }}
                </div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-3 rounded bg-red-50 border border-red-200 text-red-800 text-sm">
                    {{ session('error') }}
                </div>
            @endif

            <livewire:admin.mail-review.index />
        </div>
    </div>
</x-app-layout>
