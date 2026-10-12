<x-app-layout>
    <div class="py-4 px-4">
        <div class="max-w-[1440px] mx-auto">
            {{-- Итог входа ВК (VkAdminAuthController) — приходит редиректом. --}}
            @if(session('status'))
                <div class="mb-3 p-2 rounded-md text-[12.5px]" style="background:var(--emerald-50);color:var(--emerald-700)">{{ session('status') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-3 p-2 rounded-md text-[12.5px]" style="background:var(--amber-50);color:var(--amber-800)">{{ session('error') }}</div>
            @endif
            <livewire:marketing.workspace />
        </div>
    </div>
</x-app-layout>
