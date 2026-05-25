<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ $user->exists ? 'Редактирование: ' . $user->name : 'Новый пользователь' }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('status'))
                <div class="p-3 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            <div class="text-[12.5px] text-fg-3">
                <a href="{{ route('managers.index') }}" class="text-sky-700 hover:underline">← Все менеджеры</a>
            </div>

            <livewire:admin.managers.editor :user="$user" wire:key="editor-{{ $user->id ?? 'new' }}" />

            @if($user->exists)
                @php
                    // Личный ящик подключается только для ролей, которые ведут
                    // заявки (manager / head_of_sales). Директор / секретарь /
                    // админ — личный ящик НЕ синкается (Mailbox::scopeSyncable
                    // фильтрует по requestHandlerRoles). Кейс M-2026-1723:
                    // личный ящик директора был активен → закупочная переписка
                    // с поставщиками попадала как client_request.
                    $canHaveSyncableMailbox = $user->hasAnyRole(\App\Enums\Role::requestHandlerRoles());
                @endphp
                @if($canHaveSyncableMailbox)
                    <livewire:admin.managers.mailbox-oauth :user="$user" wire:key="oauth-{{ $user->id }}" />
                @else
                    <div class="ds-card p-4 mt-4 text-[13px] text-fg-2 border-l-2 border-[var(--amber-600)]">
                        <div class="font-semibold text-fg-1 mb-1">Личный ящик не подключается для этой роли</div>
                        Синхронизация личных ящиков работает только для менеджеров и РОПа — они ведут клиентские заявки. Для роли
                        <strong>{{ \App\Enums\Role::tryFrom($user->roles->first()?->name ?? '')?->label() ?? 'не определена' }}</strong>
                        личная переписка не относится к клиентским заявкам и не должна попадать в систему.
                        <div class="text-[12px] text-fg-3 mt-2">
                            Если нужно изменить роль — поле «Роль» в форме выше.
                        </div>
                    </div>
                @endif
            @endif
        </div>
    </div>
</x-app-layout>
