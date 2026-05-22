<x-app-layout>
    {{--
        Без <x-slot name="header"> — на новых страницах (Pool, Dashboard)
        subnav рисуется внутри самой страницы. Контейнер max-w-[1440px]
        как в navigation header — иначе таблица «Почты» (8 колонок,
        ширина >1280px) обрезается под старым max-w-7xl.
    --}}
    <div class="py-4 px-4">
        <div class="max-w-[1440px] mx-auto">
            <livewire:mail.index />
        </div>
    </div>
</x-app-layout>
