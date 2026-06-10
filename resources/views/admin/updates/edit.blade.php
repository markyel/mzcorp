<x-app-layout>
    <div class="max-w-[900px] mx-auto px-6 pt-4 pb-8">

        <div class="flex items-center gap-3 text-[11.5px] uppercase tracking-wider text-fg-3 mb-3">
            <span>CRM</span>
            <span class="text-border-strong">/</span>
            <a href="{{ route('updates.index') }}" class="hover:text-fg-1">Обновления</a>
            <span class="text-border-strong">/</span>
            <a href="{{ route('updates.manage') }}" class="hover:text-fg-1">Управление</a>
            <span class="text-border-strong">/</span>
            <span class="font-medium text-fg-1">{{ ($entry ?? null) && $entry->exists ? 'Редактирование' : 'Новая запись' }}</span>
        </div>

        <h1 class="text-2xl font-semibold text-fg-1 leading-tight mb-5">
            {{ ($entry ?? null) && $entry->exists ? 'Редактирование записи' : 'Новая запись' }}
        </h1>

        <livewire:admin.updates.editor :entry="$entry ?? null" wire:key="upd-editor-{{ ($entry ?? null) && $entry->exists ? $entry->id : 'new' }}" />
    </div>
</x-app-layout>
