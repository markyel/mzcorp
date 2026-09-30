<div class="space-y-4">
    @php
        $inputCls = 'h-[30px] w-full px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500';
        $matrix = is_array($supplier->assortment_matrix) ? $supplier->assortment_matrix : [];
        $mBrands = $matrix['brands'] ?? [];
        $mCats = $matrix['categories'] ?? [];
        $mPairs = $matrix['pairs'] ?? [];
    @endphp

    {{-- Заголовок --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ route('suppliers.index', ['tab' => 'registry']) }}" wire:navigate class="text-[12px] text-sky-700 hover:underline">← Поставщики</a>
        <h2 class="text-[16px] font-semibold text-fg-1">{{ $supplier->name ?: ($supplier->email ?: $supplier->domain) }}</h2>
    </div>

    {{-- Группы: клик включает / исключает поставщика, сохраняется сразу. По группам
         выбирают поставщиков для запроса цены (заявка → «Поставщики», «Снабжение»). --}}
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="text-[11px] uppercase tracking-wider text-fg-3 font-semibold mr-1">Группы</span>
        @forelse($this->groupOptions as $g)
            <button type="button" wire:click="toggleGroup({{ $g['id'] }})" wire:key="sg-{{ $g['id'] }}"
                    class="btn btn-xs {{ $g['member'] ? 'btn-primary' : '' }}"
                    title="{{ $g['member'] ? 'Исключить из группы' : 'Добавить в группу' }} · в группе {{ $g['count'] }}">{{ $g['member'] ? '✓ ' : '+ ' }}{{ $g['name'] }}</button>
        @empty
            <span class="text-[12px] text-fg-4">Групп пока нет.</span>
        @endforelse
        <span class="inline-flex items-center gap-1 ml-1">
            <input type="text" wire:model="newGroupName" wire:keydown.enter="createGroupAndAdd" placeholder="Новая группа"
                   class="h-[26px] px-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500 w-[150px]">
            <button type="button" wire:click="createGroupAndAdd" class="btn btn-xs" title="Создать группу и добавить в неё поставщика">+ Создать</button>
        </span>
    </div>

    {{-- Организация: несколько адресов одной компании под общим названием.
         Письма уходят по-прежнему на адрес, организация группирует адреса в реестре. --}}
    @php $org = $supplier->organization; $cand = $this->organizationCandidates; @endphp
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Организация</h3>
            <span class="text-[12px] text-fg-3 ml-2">{{ $org ? 'адреса одной компании' : 'адрес пока сам по себе' }}</span>
        </div>
        <div class="ds-card-body space-y-3 text-[12.5px]">
            @if($org)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-[14px] font-semibold text-fg-1">🏢</span>
                    <input type="text" wire:model="orgName" wire:keydown.enter="renameOrganization"
                           class="h-[30px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500 w-[340px] max-w-full">
                    <button type="button" wire:click="renameOrganization" class="btn btn-xs">Переименовать</button>
                    <button type="button" wire:click="leaveOrganization" wire:confirm="Вывести этот адрес из организации «{{ $org->name }}»?"
                            class="btn btn-xs text-fg-3">Выйти из организации</button>
                </div>
                <div>
                    <div class="text-[11px] uppercase tracking-wider text-fg-3 mb-1">Другие адреса организации</div>
                    @forelse($this->organizationMembers as $m)
                        <div wire:key="om-{{ $m->id }}" class="flex items-center gap-2 py-0.5">
                            <a href="{{ route('suppliers.registry-edit', $m->id) }}" wire:navigate class="mono text-sky-700 hover:underline">{{ $m->email ?: $m->domain }}</a>
                            @if($m->name)<span class="text-fg-3">{{ $m->name }}</span>@endif
                            <button type="button" wire:click="removeMember({{ $m->id }})" class="text-[11px] text-fg-4 hover:text-red-600" title="Вывести адрес из организации">×</button>
                        </div>
                    @empty
                        <div class="text-fg-4">Пока только этот адрес — добавьте другие ниже.</div>
                    @endforelse
                </div>
            @else
                <div class="flex flex-wrap items-center gap-2">
                    <input type="text" wire:model="orgName" wire:keydown.enter="createOrganization" placeholder="{{ $supplier->name ?: 'Название организации' }}"
                           class="h-[30px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500 w-[340px] max-w-full">
                    <button type="button" wire:click="createOrganization" class="btn btn-xs">Создать организацию</button>
                    <span class="text-[11.5px] text-fg-4">или объедините с другим адресом ниже</span>
                </div>
            @endif

            <div class="border-t border-border-subtle pt-3">
                <div class="text-[11px] uppercase tracking-wider text-fg-3 mb-1">{{ $org ? 'Добавить адрес в организацию' : 'Объединить с' }}</div>
                <input type="search" wire:model.live.debounce.300ms="orgSearch" placeholder="Поиск: email / домен / название поставщика или организации"
                       class="h-[30px] px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500 w-[440px] max-w-full">
                @if(trim($orgSearch) === '' && $cand['suppliers']->isNotEmpty())
                    <div class="text-[11px] text-fg-4 mt-1.5">На том же домене:</div>
                @endif
                <div class="mt-1 space-y-0.5">
                    @foreach($cand['organizations'] as $o)
                        <div wire:key="oc-{{ $o->id }}" class="flex items-center gap-2">
                            <span class="text-fg-1 font-medium">🏢 {{ $o->name }}</span>
                            <span class="text-fg-4 text-[11px]">{{ $o->suppliers_count }} адр.</span>
                            <button type="button" wire:click="joinOrganization({{ $o->id }})" class="btn btn-xs">Войти в организацию</button>
                        </div>
                    @endforeach
                    @foreach($cand['suppliers'] as $c)
                        <div wire:key="sc-{{ $c->id }}" class="flex items-center gap-2 flex-wrap">
                            <span class="mono text-fg-1">{{ $c->email ?: $c->domain }}</span>
                            @if($c->name)<span class="text-fg-3">{{ $c->name }}</span>@endif
                            @if($c->organization)<span class="chip chip-neutral text-[10.5px]" title="Уже в организации — организации объединятся в одну">🏢 {{ $c->organization->name }}</span>@endif
                            <button type="button" wire:click="mergeWith({{ $c->id }})" class="btn btn-xs">{{ $org ? 'Добавить' : 'Объединить' }}</button>
                        </div>
                    @endforeach
                    @if(mb_strlen(trim($orgSearch)) >= 2 && $cand['suppliers']->isEmpty() && $cand['organizations']->isEmpty())
                        <div class="text-fg-4">Ничего не найдено.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {{-- Реквизиты + ассортимент --}}
        <div class="lg:col-span-2 ds-card">
            <div class="ds-card-header"><h3>Поставщик</h3></div>
            <div class="ds-card-body space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Название</label>
                        <input type="text" wire:model="name" class="{{ $inputCls }}">
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Контактное лицо</label>
                        <input type="text" wire:model="contact_person" class="{{ $inputCls }}" placeholder="Имя (Отчество)">
                        <div class="text-[10.5px] text-fg-4 mt-0.5">Подставляется в обращение письма: «Здравствуйте, {Имя}!» — вместо названия компании.</div>
                        @error('contact_person') <div class="text-[11px] text-red-600 mt-0.5">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">E-mail</label>
                        <input type="email" wire:model="email" class="{{ $inputCls }} mono">
                        @error('email') <div class="text-[11px] text-red-600 mt-0.5">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Домен</label>
                        <input type="text" wire:model="domain" placeholder="supplier.ru" class="{{ $inputCls }} mono">
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Телефон</label>
                        <input type="text" wire:model="phone" class="{{ $inputCls }} mono">
                    </div>
                    <div>
                        <label class="block text-[11.5px] text-fg-3 mb-1">Язык общения</label>
                        <select wire:model="language" class="{{ $inputCls }}">
                            <option value="ru">Русский</option>
                            <option value="en">English</option>
                        </select>
                        <div class="text-[10.5px] text-fg-4 mt-0.5">Письмо-запрос и номенклатура — на этом языке (для каталожных позиций — англ. название).</div>
                    </div>
                </div>
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Описание ассортимента <span class="text-fg-4">(бренды, типы запчастей — свободным текстом)</span></label>
                    <textarea wire:model="assortment_description" rows="4" placeholder="Напр.: Возим запчасти KONE, OTIS, Schindler — лебёдки, двери кабины, частотные преобразователи, платы управления." class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                </div>
                <div>
                    <label class="block text-[11.5px] text-fg-3 mb-1">Заметки</label>
                    <textarea wire:model="notes" rows="2" class="w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500"></textarea>
                </div>
                <div class="flex gap-2 pt-1">
                    <button type="button" wire:click="save" class="btn btn-sm btn-primary">Сохранить</button>
                    <button type="button" wire:click="rebuildMatrix" wire:loading.attr="disabled" class="btn btn-sm">
                        <span wire:loading.remove wire:target="rebuildMatrix">↻ Пересобрать матрицу</span>
                        <span wire:loading wire:target="rebuildMatrix">Собираю…</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Матрица ассортимента --}}
        <div class="ds-card">
            <div class="ds-card-header">
                <h3>Матрица</h3>
                <span class="text-[12px] text-fg-3 ml-2">для подбора под позицию</span>
            </div>
            <div class="ds-card-body space-y-3 text-[12.5px]">
                @if(empty($mBrands) && empty($mCats) && empty($mPairs))
                    <div class="text-fg-3">Матрица не собрана. Заполните описание ассортимента и нажмите «Пересобрать матрицу».</div>
                @else
                    @if(!empty($mBrands))
                        <div>
                            <div class="text-[11px] uppercase tracking-wider text-fg-3 mb-1">Бренды</div>
                            <div class="flex flex-wrap gap-1">
                                @foreach($mBrands as $b)<span class="chip chip-neutral text-[11px]">{{ $b }}</span>@endforeach
                            </div>
                        </div>
                    @endif
                    @if(!empty($mCats))
                        <div>
                            <div class="text-[11px] uppercase tracking-wider text-fg-3 mb-1">Категории</div>
                            <div class="flex flex-wrap gap-1">
                                @foreach($mCats as $c)<span class="chip chip-sky text-[11px]">{{ $c }}</span>@endforeach
                            </div>
                        </div>
                    @endif
                    @if(!empty($mPairs))
                        <div>
                            <div class="text-[11px] uppercase tracking-wider text-fg-3 mb-1">Пары бренд × категория</div>
                            <div class="flex flex-wrap gap-1">
                                @foreach($mPairs as $p)<span class="chip chip-info text-[11px]">{{ $p['brand'] ?? '' }} · {{ $p['category'] ?? '' }}</span>@endforeach
                            </div>
                        </div>
                    @endif
                    @if($supplier->matrix_built_at)
                        <div class="text-[11px] text-fg-4 pt-1 border-t border-border-subtle">Собрана {{ $supplier->matrix_built_at->format('d.m.Y H:i') }} · {{ $supplier->matrix_built_with_model ?: '—' }}</div>
                    @endif
                @endif
            </div>
        </div>
    </div>

    {{-- Правила подбора с wildcard «ВСЕ» (ручные, приоритетны) --}}
    <div class="ds-card">
        <div class="ds-card-header">
            <h3>Правила подбора</h3>
            <span class="text-[12px] text-fg-3 ml-2">бренд × категория, «ВСЕ» = любой</span>
        </div>
        <div class="ds-card-body space-y-3">
            <div class="text-[12px] text-fg-3">
                Точное правило поверх авто-матрицы. Примеры: <b>Schneider</b> × <b>ВСЕ</b> — любое оборудование Schneider; <b>ВСЕ</b> × <b>Ролик</b> — ролики любых марок; <b>ВСЕ</b> × <b>ВСЕ</b> — все запросы.
            </div>

            @if(!empty($rules))
                <div class="flex flex-wrap gap-1.5">
                    @foreach($rules as $idx => $r)
                        <span class="inline-flex items-center gap-1.5 chip chip-sky text-[11.5px]">
                            <span class="font-medium">{{ $r['brand'] ?? 'ВСЕ' }}</span>
                            <span class="text-fg-4">×</span>
                            <span class="font-medium">{{ $r['category'] ?? 'ВСЕ' }}</span>
                            <button type="button" wire:click="removeRule({{ $idx }})" class="text-red-600 ml-0.5" title="Удалить правило">×</button>
                        </span>
                    @endforeach
                </div>
            @else
                <div class="text-[12px] text-fg-4">Правил нет.</div>
            @endif

            <div class="flex flex-wrap items-end gap-2 border-t border-border-subtle pt-3">
                <div>
                    <label class="block text-[11px] text-fg-3 mb-1">Бренд</label>
                    <select wire:model="newRuleBrand" class="{{ $inputCls }} min-w-[180px]">
                        @foreach($this->brandOptions as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[11px] text-fg-3 mb-1">Категория</label>
                    <select wire:model="newRuleCategory" class="{{ $inputCls }} min-w-[220px]">
                        @foreach($this->categoryOptions as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach
                    </select>
                </div>
                <button type="button" wire:click="addRule" class="btn btn-sm btn-primary">Добавить правило</button>
            </div>
        </div>
    </div>

    {{-- Удаление --}}
    <div class="ds-card">
        <div class="ds-card-body flex items-center justify-between gap-3 flex-wrap">
            <div class="text-[12px] text-fg-3">Удалить поставщика из реестра. Запросы и переписка не удаляются.</div>
            @if($confirmingDelete)
                <div class="flex items-center gap-2">
                    <span class="text-[12px] text-red-700">Точно удалить?</span>
                    <button type="button" wire:click="deleteSupplier" class="btn btn-sm" style="background:var(--red-600,#dc2626);color:#fff">Удалить</button>
                    <button type="button" wire:click="$set('confirmingDelete', false)" class="btn btn-sm">Отмена</button>
                </div>
            @else
                <button type="button" wire:click="$set('confirmingDelete', true)" class="btn btn-sm text-red-600">Удалить</button>
            @endif
        </div>
    </div>
</div>
