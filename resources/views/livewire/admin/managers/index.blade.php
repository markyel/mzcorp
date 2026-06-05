@php
    use Illuminate\Support\Str;

    $users = $this->users;
    $counters = $this->counters;

    $filterChips = [
        ['key' => 'manager',       'label' => 'Менеджеры',    'count' => $counters['manager']],
        ['key' => 'head_of_sales', 'label' => 'РОП',          'count' => $counters['head_of_sales']],
        ['key' => 'secretary',     'label' => 'Секретари',    'count' => $counters['secretary']],
        ['key' => 'director',      'label' => 'Директорат',   'count' => $counters['director']],
        ['key' => 'all',           'label' => 'Все активные', 'count' => null],
        ['key' => 'archived',      'label' => 'Архив',        'count' => $counters['archived']],
    ];
@endphp

<div>
    {{-- Toolbar: filter chips + search + create button --}}
    <div class="flex items-center gap-2 flex-wrap mb-3">
        @foreach($filterChips as $chip)
            @php $active = $filter === $chip['key']; @endphp
            <button type="button"
                    wire:click="$set('filter', '{{ $chip['key'] }}')"
                    class="inline-flex items-center gap-1.5 px-2.5 h-[26px] rounded-md text-[12px] font-medium border transition-colors
                           {{ $active
                               ? 'bg-[var(--accent)] text-[var(--fg-on-accent)] border-[var(--accent)]'
                               : 'bg-[var(--bg-surface)] text-[var(--fg-2)] border-[var(--border)] hover:bg-[var(--bg-hover)]' }}">
                {{ $chip['label'] }}
                @if($chip['count'] !== null)
                    <span class="text-[10.5px] font-semibold {{ $active ? 'opacity-80' : 'text-[var(--fg-3)]' }}">{{ $chip['count'] }}</span>
                @endif
            </button>
        @endforeach

        <span class="flex-1"></span>

        <input type="search" wire:model.live.debounce.300ms="search"
               placeholder="Поиск по имени или email"
               class="h-[30px] px-2.5 border border-[var(--border)] rounded-md bg-[var(--bg-app)] text-[13px] outline-none focus:border-[var(--sky-500)] w-[260px]">

        <a href="{{ route('managers.create') }}" class="btn btn-primary btn-sm">+ Новый пользователь</a>
    </div>

    {{-- Table --}}
    <div class="ds-card">
        <table class="w-full text-[13px]">
            <thead>
                <tr class="text-[10.5px] uppercase tracking-wider text-fg-3 font-semibold border-b border-border-subtle">
                    <th class="px-4 py-2 text-left">Имя</th>
                    <th class="px-4 py-2 text-left">Email</th>
                    <th class="px-4 py-2 text-left">Роль</th>
                    <th class="px-4 py-2 text-left">Личный ящик</th>
                    <th class="px-4 py-2 text-left">Статус</th>
                    <th class="px-4 py-2 text-right">Действия</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $u)
                    @php
                        $roleNames = $u->roles->pluck('name')->all();
                        $roleLabel = match (true) {
                            in_array('head_of_sales', $roleNames, true) => 'РОП',
                            in_array('director', $roleNames, true) => 'Директорат',
                            in_array('secretary', $roleNames, true) => 'Секретарь',
                            in_array('manager', $roleNames, true) => 'Менеджер',
                            default => '—',
                        };
                        $personal = $u->ownedMailboxes->first();
                        // Личный ящик подключается только для ролей-исполнителей
                        // (manager / head_of_sales). У director / secretary /
                        // admin отсутствие ящика — это норма (Mailbox::scopeSyncable
                        // их исключает, sync не работает), показываем отдельной
                        // меткой чтобы не путать с «забыли подключить».
                        $canHaveMailbox = $u->hasAnyRole(\App\Enums\Role::requestHandlerRoles());
                        $personalChip = match (true) {
                            ! $canHaveMailbox && ! $personal => ['не для этой роли', 'text-fg-4'],
                            ! $canHaveMailbox && $personal => ['не должен синкаться', 'text-amber-700'],
                            ! $personal => ['—', 'text-fg-3'],
                            ! $personal->is_active => ['отвязан', 'text-amber-700'],
                            $personal->last_error_at && (! $personal->last_synced_at || $personal->last_error_at > $personal->last_synced_at) => ['ошибка sync', 'text-red-700'],
                            default => [$personal->email, 'text-emerald-700'],
                        };
                        $isArchived = $u->archived_at !== null;
                        $initials = collect(preg_split('/\s+/u', trim($u->name)))
                            ->filter()
                            ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
                            ->take(2)
                            ->implode('');
                    @endphp
                    <tr wire:key="user-{{ $u->id }}" class="border-b border-border-subtle last:border-b-0 hover:bg-hover">
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-2.5">
                                <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-neutral-200 text-fg-2 text-[10px] font-semibold">{{ $initials ?: '?' }}</span>
                                <span class="text-fg-1 font-medium">{{ $u->name }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-2 mono text-fg-2 text-[12px]">{{ $u->email }}</td>
                        <td class="px-4 py-2">{{ $roleLabel }}</td>
                        <td class="px-4 py-2 mono text-[12px] {{ $personalChip[1] }}">{{ $personalChip[0] }}</td>
                        <td class="px-4 py-2">
                            @if($isArchived)
                                <span class="chip chip-paused"><span class="dot"></span>в архиве</span>
                            @elseif($u->isUnavailable())
                                <span class="chip chip-warn" title="{{ $u->unavailable_reason }}">
                                    <span class="dot"></span>недоступен до {{ $u->unavailable_until->format('d.m.Y') }}
                                </span>
                            @elseif($u->isUnavailabilityPlanned())
                                <span class="chip chip-info" title="{{ $u->unavailable_reason }}">
                                    <span class="dot"></span>план: {{ $u->unavailable_from->format('d.m') }} – {{ $u->unavailable_until->format('d.m.Y') }}
                                </span>
                            @else
                                <span class="chip chip-ok"><span class="dot"></span>активен</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right">
                            <div class="inline-flex items-center gap-1.5">
                                <a href="{{ route('managers.edit', $u) }}" class="btn btn-sm">Редактировать</a>
                                @if($isArchived)
                                    <button type="button" wire:click="restore({{ $u->id }})"
                                            wire:confirm="Восстановить «{{ $u->name }}»?"
                                            class="btn btn-sm">Восстановить</button>
                                @else
                                    @if($u->hasAnyRole(\App\Enums\Role::requestHandlerRoles()))
                                        @if($u->isUnavailable() || $u->isUnavailabilityPlanned())
                                            <button type="button" wire:click="markAvailable({{ $u->id }})"
                                                    wire:confirm="Снять «недоступен» / отменить план с «{{ $u->name }}» прямо сейчас? Менеджер снова попадёт в round-robin, активные delegation'ы закроются."
                                                    class="btn btn-sm">{{ $u->isUnavailable() ? 'Доступен' : 'Отменить план' }}</button>
                                        @else
                                            <button type="button"
                                                    wire:click="$dispatch('open-unavailability', { userId: {{ $u->id }} })"
                                                    class="btn btn-sm" title="Отпуск / командировка / больничный">⏸ Недоступен…</button>
                                        @endif
                                    @endif
                                    <button type="button" wire:click="archive({{ $u->id }})"
                                            wire:confirm="Перевести «{{ $u->name }}» в архив? Логин будет заблокирован, в round-robin он не попадёт. Открытые заявки нужно переподчинить вручную."
                                            class="btn btn-sm btn-danger">Архивировать</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-fg-3 text-sm">
                            @if($search !== '')
                                Никого не нашли по запросу «{{ $search }}».
                            @else
                                В этом списке никого нет.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($users->hasPages())
        <div class="mt-3">{{ $users->links() }}</div>
    @endif

    {{-- Foundation Фаза 2: модалка «недоступен» — single-instance per page. --}}
    <livewire:admin.managers.unavailability-dialog wire:key="unavail-dialog" />
</div>
