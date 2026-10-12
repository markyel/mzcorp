<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Боты заявок — диалоги
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-[1440px] mx-auto px-4">
            <livewire:admin.bot-chats />
        </div>
    </div>
</x-app-layout>
