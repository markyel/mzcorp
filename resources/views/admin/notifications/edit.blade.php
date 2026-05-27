<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Уведомление: {{ $template->type->label() }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            @if(session('status'))
                <div class="mb-4 p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <div class="mb-4">
                <a href="{{ route('notifications.index') }}" class="text-sm text-gray-600 hover:text-gray-900">← К списку</a>
            </div>

            <livewire:admin.notifications.edit :template="$template" />
        </div>
    </div>
</x-app-layout>
