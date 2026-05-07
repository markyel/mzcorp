<x-app-layout>
    {{--
        Phase 1.8d-extended: full-bleed 3-col shell под 03-requests.html.
        Внешняя навигация (topbar) идёт от <x-app-layout>; здесь — rail (56px) +
        list nav (240px) + main. Rail/list nav — UI-каркас Phase 2 (saved views,
        bulk-операции, search-индекс ⌘K не реализованы). Активные элементы —
        лишь те, что реально работают на текущем бэкенде.
    --}}
    <livewire:requests.pool />
</x-app-layout>
