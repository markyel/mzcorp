<x-app-layout :rail="false">
    {{--
        Phase 1.8d-extended: full-bleed 3-col shell под 03-requests.html.
        Внешняя навигация (topbar) идёт от <x-app-layout>; здесь — rail (56px) +
        list nav (240px) + main. :rail="false" отключает глобальный rail из
        layout — у pool свой 3-col grid с rail внутри.
    --}}
    <livewire:requests.pool />
</x-app-layout>
