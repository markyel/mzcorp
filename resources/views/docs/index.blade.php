<x-app-layout>
    @include('docs._layout', ['sections' => $sections, 'current' => null, 'slot' => view('docs._index-content', ['sections' => $sections])])
</x-app-layout>
