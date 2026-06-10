<x-app-layout>
    <div class="max-w-[900px] mx-auto px-6 pt-4 pb-8">

        {{-- Subnav: breadcrumbs + h1 --}}
        <div class="flex items-center gap-3 text-[11.5px] uppercase tracking-wider text-fg-3 mb-3">
            <span>CRM</span>
            <span class="text-border-strong">/</span>
            <span class="font-medium text-fg-1">Обновления</span>
        </div>

        <div class="flex items-end justify-between gap-4 mb-5">
            <div>
                <h1 class="text-2xl font-semibold text-fg-1 leading-tight">Обновления системы</h1>
                <div class="text-fg-3 text-sm mt-1">Важные изменения в работе CRM — для всех участников.</div>
            </div>
        </div>

        <livewire:updates.index />
    </div>
</x-app-layout>
