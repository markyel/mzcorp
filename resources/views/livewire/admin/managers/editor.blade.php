<div class="ds-card p-5">
    <h3 class="text-[14.5px] font-semibold text-fg-1 mb-4">
        {{ $userId ? 'Учётная запись' : 'Новый пользователь' }}
    </h3>

    <form wire:submit="save" class="space-y-4">
        <div>
            <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">ФИО</label>
            <input type="text" wire:model="name"
                   class="w-full h-[34px] px-3 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]">
            @error('name') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Email (логин в CRM)</label>
                <input type="email" wire:model="email" autocomplete="off"
                       class="w-full h-[34px] px-3 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono">
                @error('email') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
            </div>

            <div>
                <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Роль</label>
                <select wire:model="role"
                        class="w-full h-[34px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)]">
                    @foreach($roles as $r)
                        <option value="{{ $r->value }}">{{ $r->label() }}</option>
                    @endforeach
                </select>
                @error('role') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
            </div>
        </div>

        {{-- Плановая нагрузка — только для ролей-исполнителей (manager / РОП).
             Для secretary / director / admin поле бессмысленно: они не попадают
             в pool auto-assign'а (см. Role::requestHandlerRoles). --}}
        @if(in_array($role, ['manager', 'head_of_sales'], true))
            <div class="grid grid-cols-2 gap-3 pt-2 border-t border-border-subtle">
                <div>
                    <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                        Плановая нагрузка
                    </label>
                    <div class="flex items-center gap-2">
                        <input type="number" wire:model="loadWeight"
                               min="1" max="500" step="1"
                               class="w-[110px] h-[34px] px-3 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono text-right">
                        <span class="text-[13px] text-fg-2">%</span>
                        <span class="text-[11.5px] text-fg-3">
                            @if($loadWeight == 100) обычная доля
                            @elseif($loadWeight < 100) {{ round(100 / max($loadWeight, 1), 2) }}× меньше чем у стандартного
                            @else {{ round($loadWeight / 100, 2) }}× больше чем у стандартного
                            @endif
                        </span>
                    </div>
                    @error('loadWeight') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                    <div class="text-[11.5px] text-fg-3 mt-1">
                        100 — норма; 50 — в 2 раза меньше заявок; 200 — в 2 раза больше. Применяется на стадии round-robin (sticky-правила «один клиент = один менеджер» имеют приоритет).
                    </div>
                </div>

                {{-- Потолок сложности: отстающему менеджеру оставляем только
                     простые заявки и поднимаем планку по мере роста.
                     См. App\Services\Request\ManagerComplexityGate. --}}
                <div>
                    <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                        Сложность заявок
                    </label>
                    <div class="relative w-full max-w-[260px]">
                        <select wire:model.live="maxComplexityLevel"
                                @disabled($onlyInternalSku)
                                class="w-full h-[34px] pl-3 pr-8 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] appearance-none disabled:opacity-50">
                            <option value="">Без ограничения</option>
                            @foreach($complexityLevels as $lvl)
                                <option value="{{ $lvl->value }}">Не сложнее: {{ mb_strtolower($lvl->label()) }}</option>
                            @endforeach
                        </select>
                        <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-fg-3 text-[10px]">▾</span>
                    </div>
                    @error('maxComplexityLevel') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror

                    <label class="flex items-start gap-2 mt-2 cursor-pointer">
                        <input type="checkbox" wire:model.live="onlyInternalSku"
                               class="mt-[2px] w-[15px] h-[15px] rounded border-border text-[var(--sky-600)] focus:ring-0">
                        <span class="text-[12.5px] text-fg-2">
                            Только заявки с M-артикулами
                            <span class="block text-[11.5px] text-fg-3">все позиции пришли нашим артикулом — самый простой поток</span>
                        </span>
                    </label>

                    <div class="text-[11.5px] text-fg-3 mt-1">
                        Ограничение действует при свободном распределении. Заявки своих клиентов и письма на личный ящик приходят как обычно, независимо от сложности. Если заявку не может взять никто — ограничение снимается.
                    </div>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-3 pt-2 border-t border-border-subtle">
            <div>
                <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">
                    {{ $userId ? 'Новый пароль (опционально)' : 'Временный пароль' }}
                </label>
                <input type="password" wire:model="password" autocomplete="new-password"
                       class="w-full h-[34px] px-3 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono">
                @error('password') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
                <div class="text-[11.5px] text-fg-3 mt-1">Минимум 8 символов. Менеджер сможет сменить в /profile.</div>
            </div>
            <div>
                <label class="block text-[12px] uppercase tracking-wider text-fg-3 font-semibold mb-1">Повторите пароль</label>
                <input type="password" wire:model="passwordConfirmation" autocomplete="new-password"
                       class="w-full h-[34px] px-3 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-[var(--sky-500)] mono">
                @error('passwordConfirmation') <div class="text-red-700 text-[12px] mt-1">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="flex items-center gap-2 pt-3 border-t border-border-subtle">
            <button type="submit" class="btn btn-primary">{{ $userId ? 'Сохранить' : 'Создать' }}</button>
            <a href="{{ route('managers.index') }}" class="btn">Отмена</a>
        </div>
    </form>
</div>
