<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Боты заявок — диалоги
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-[1440px] mx-auto px-4">
            {{-- Сценарий бота «Календарь в подарок» (?start=calendar): кто записался. В меню не выносим — сезонное. --}}
            <div style="margin-bottom: 10px; font-size: 13px;"><a href="{{ route('calendar-gift.index') }}" class="btn btn-sm">🎁 Календарь в подарок — получатели</a></div>
            <livewire:admin.bot-chats />
        </div>
    </div>
</x-app-layout>
