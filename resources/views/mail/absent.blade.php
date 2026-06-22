<x-app-layout>
    <div class="py-4 px-4">
        <div class="max-w-[1440px] mx-auto">
            @if(auth()->user()?->hasAnyRole(['head_of_sales', 'secretary', 'director', 'admin']))
                <div class="mb-2 flex items-center gap-3 text-[12.5px]">
                    <a href="{{ route('mail.index') }}" wire:navigate class="text-sky-700 hover:underline">← Вся почта</a>
                    <span class="font-semibold text-fg-1">Почта выбывших</span>
                </div>
            @endif
            <livewire:mail.absent-inbox />
        </div>
    </div>
</x-app-layout>
