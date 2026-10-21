<x-app-layout>
    <div class="py-4 px-4">
        <div class="max-w-[1440px] mx-auto">
            @if (session('status'))
                {{-- Итог входа через Яндекс для ящика внесистемных (OAuthYandexController). --}}
                <div class="mb-3 rounded-md border border-border bg-surface px-3 py-2 text-[13px]">{{ session('status') }}</div>
            @endif
            <livewire:quote-parts.index />
        </div>
    </div>
</x-app-layout>
