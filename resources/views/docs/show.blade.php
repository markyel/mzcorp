<x-app-layout>
    @include('docs._layout', [
        'sections' => $sections,
        'current' => $page,
        'slot' => view('docs._show-content', ['page' => $page, 'html' => $html]),
    ])
</x-app-layout>
