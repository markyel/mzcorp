<x-app-layout>
    <div class="max-w-[1440px] mx-auto px-6 pt-4 pb-8">

        {{-- Subnav: breadcrumbs + h1 --}}
        <div class="flex items-center gap-3 text-[11.5px] uppercase tracking-wider text-fg-3 mb-3">
            <span>CRM</span>
            <span class="text-border-strong">/</span>
            <span class="font-medium text-fg-1">Заявки</span>
        </div>

        <div class="flex items-end justify-between gap-4 mb-5">
            <div>
                <h1 class="text-2xl font-semibold text-fg-1 leading-tight">Заявки в работе</h1>
                <div class="text-fg-3 text-sm mt-1">
                    Пул назначенных и нераспределённых заявок. Менеджер видит свои; РОП и директор — все.
                </div>
            </div>
        </div>

        <livewire:requests.pool />
    </div>
</x-app-layout>
