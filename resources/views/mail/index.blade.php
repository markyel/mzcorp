<x-app-layout>
    {{--
        Без <x-slot name="header"> — на новых страницах (Pool, Dashboard)
        subnav рисуется внутри самой страницы. Контейнер max-w-[1440px]
        как в navigation header — иначе таблица «Почты» (8 колонок,
        ширина >1280px) обрезается под старым max-w-7xl.
    --}}
    <div class="py-4 px-4">
        <div class="max-w-[1440px] mx-auto">
            <div class="mb-2 flex items-center gap-3 text-[12.5px]">
                <span class="font-semibold text-fg-1">Вся почта</span>
                <a href="{{ route('mail.absent') }}" wire:navigate class="text-sky-700 hover:underline">→ Почта выбывших</a>
            </div>
            <livewire:mail.index />
        </div>
    </div>
</x-app-layout>
