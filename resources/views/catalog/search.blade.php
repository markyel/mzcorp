<x-app-layout :rail="false">
    {{-- Standalone-поиск по каталогу. См. App\Livewire\Catalog\Search.
         :rail="false" — у search свой grid с rail внутри. --}}
    <livewire:catalog.search />
</x-app-layout>
