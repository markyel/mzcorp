<x-app-layout>
    <div class="max-w-[1100px] mx-auto px-6 pt-4 pb-8">

        <div class="flex items-center gap-3 text-[11.5px] uppercase tracking-wider text-fg-3 mb-3">
            <span>CRM</span>
            <span class="text-border-strong">/</span>
            <a href="{{ route('updates.index') }}" class="hover:text-fg-1">Обновления</a>
            <span class="text-border-strong">/</span>
            <span class="font-medium text-fg-1">Управление</span>
        </div>

        <div class="flex items-end justify-between gap-4 mb-5">
            <div>
                <h1 class="text-2xl font-semibold text-fg-1 leading-tight">Управление обновлениями</h1>
                <div class="text-fg-3 text-sm mt-1">Публикуйте важные для участников изменения системы.</div>
            </div>
            <a href="{{ route('updates.create') }}"
               class="inline-flex items-center gap-1.5 h-[34px] px-3.5 rounded-md bg-[var(--accent)] text-[var(--fg-on-accent)] border border-[var(--accent)] text-[13px] font-medium hover:opacity-90">
                + Новая запись
            </a>
        </div>

        @if(session('status'))
            <div class="mb-4 px-4 py-2.5 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-800 text-[13px]">
                {{ session('status') }}
            </div>
        @endif

        <livewire:admin.updates.index />
    </div>
</x-app-layout>
