<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ $user->exists ? 'Редактирование: ' . $user->name : 'Новый пользователь' }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('status'))
                <div class="p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <div class="text-[12.5px] text-fg-3">
                <a href="{{ route('managers.index') }}" class="text-sky-700 hover:underline">← Все менеджеры</a>
            </div>

            <livewire:admin.managers.editor :user="$user" wire:key="editor-{{ $user->id ?? 'new' }}" />

            @if($user->exists)
                <livewire:admin.managers.mailbox-oauth :user="$user" wire:key="oauth-{{ $user->id }}" />
            @endif
        </div>
    </div>
</x-app-layout>
