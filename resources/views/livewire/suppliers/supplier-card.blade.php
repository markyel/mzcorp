<div class="space-y-4">
    @php
        $in = 'h-[30px] w-full px-2 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500';
        $ta = 'w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[13px] outline-none focus:border-sky-500';
        $p = (array) ($org->qp_payload ?? []);
        $statusLabel = ['active' => 'работает в кабинете', 'mail' => 'запросы через QuoteParts', 'invited' => 'приглашён', 'terms_discussed' => 'обсуждает условия', 'declined' => 'отказался от платформы'][$org->qp_status] ?? 'не в QuoteParts';
        $matrix = (array) ($p['assortment_matrix'] ?? []);
    @endphp

    {{-- Заголовок: название, код 1С, состояние в QuoteParts --}}
    <div class="flex items-center gap-3 flex-wrap">
        <a href="{{ $backUrl ?: route('suppliers.index', ['tab' => 'registry']) }}" wire:navigate class="text-[12px] text-sky-700 hover:underline">{{ \App\Support\BackUrl::label($backUrl, '← Поставщики') }}</a>
        <h2 class="text-[16px] font-semibold text-fg-1">{{ $org->name }}</h2>
        @if($org->onec_code)
            <span class="chip mono" title="Код контрагента в 1С — ключ поставщика в QuoteParts">1С {{ $org->onec_code }}</span>
        @else
            <span class="chip chip-attn mono" title="Временный ключ до кода 1С">{{ $org->qp_key }}</span>
        @endif
        <span class="chip {{ $org->qp_status === 'active' ? 'chip-ok' : '' }}">{{ $statusLabel }}</span>
        @if($org->isArchived())<span class="chip chip-danger" title="Не виден в реестре и не предлагается в запросах цен">в архиве</span>@endif
        @if($p['is_agent'] ?? false)<span class="chip chip-info">агент</span>@endif
        <button type="button" wire:click="toggleAgent" class="btn btn-xs text-fg-3" title="Агент сам ищет поставщиков для нас: в его ценах — реальный поставщик">{{ ($p['is_agent'] ?? false) ? 'не агент' : 'это агент' }}</button>
        @if($org->qp_synced_at)<span class="text-[12px] text-fg-4 ml-auto">сверено с QuoteParts {{ $org->qp_synced_at->format('d.m H:i') }}</span>@endif
    </div>
    @if($org->onec_name && $org->onec_name !== $org->name)
        <div class="text-[12px] text-fg-3">В 1С: {{ $org->onec_name }}</div>
    @endif

    {{-- Код 1С: кандидаты или ручной ввод --}}
    @if(! $org->onec_code)
        <div class="ds-card">
            <div class="ds-card-header"><h3>Код 1С</h3><span class="text-[12px] text-fg-3 ml-2">станет постоянным ключом поставщика и в QuoteParts</span></div>
            <div class="ds-card-body space-y-2 text-[12.5px]">
                @foreach((array) $org->onec_candidates as $c)
                    <div class="flex items-center gap-2" wire:key="cand-{{ $c['code'] }}">
                        <span class="mono" style="width:90px">{{ $c['code'] }}</span>
                        <span class="flex-1">{{ $c['name'] }}</span>
                        <button type="button" wire:click="setCode('{{ $c['code'] }}')" wire:confirm="Назначить код {{ $c['code'] }}?" class="btn btn-xs">Это он</button>
                    </div>
                @endforeach
                <div class="flex items-center gap-2">
                    <input type="text" wire:model="code" placeholder="00-000000" class="{{ $in }} mono" style="max-width:140px">
                    <button type="button" wire:click="setManualCode" class="btn btn-xs">Назначить код</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Группы --}}
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="text-[12px] text-fg-3 font-semibold mr-1">Группы</span>
        @forelse($this->groupOptions as $g)
            <button type="button" wire:click="toggleGroup({{ $g['id'] }})" wire:key="g-{{ $g['id'] }}" class="btn btn-xs {{ $g['member'] ? 'btn-primary' : '' }}">{{ $g['member'] ? '✓ ' : '+ ' }}{{ $g['name'] }}</button>
        @empty
            <span class="text-[12px] text-fg-4">Групп пока нет.</span>
        @endforelse
        <span class="inline-flex items-center gap-1 ml-1">
            <input type="text" wire:model="newGroupName" wire:keydown.enter="createGroupAndAdd" placeholder="Новая группа" class="h-[26px] px-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500 " style="width:150px">
            <button type="button" wire:click="createGroupAndAdd" class="btn btn-xs">+ Создать</button>
        </span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:16px">
        {{-- Профиль --}}
        <div class="ds-card">
            <div class="ds-card-header"><h3>Профиль</h3></div>
            <div class="ds-card-body space-y-2 text-[12.5px]">
                <label class="block">Название
                    <input type="text" wire:model="companyName" class="{{ $in }}" @disabled($this->supplierOwnsCard)>
                    @if($this->supplierOwnsCard)<span class="text-[12px] text-fg-4">Карточку компании ведёт сам поставщик в кабинете.</span>@endif
                </label>
                <div class="grid grid-cols-3 gap-2">
                    <label>Страна <input type="text" wire:model="country" maxlength="2" placeholder="CN" class="{{ $in }} uppercase" @disabled($this->supplierOwnsCard)></label>
                    <label>Язык
                        <select wire:model="locale" class="{{ $in }}" @disabled($this->supplierOwnsCard)>
                            <option value="ru">русский</option><option value="en">английский</option><option value="zh_CN">китайский</option>
                        </select>
                    </label>
                    <label>Срок поставки, дн. <input type="number" wire:model="leadDays" min="0" class="{{ $in }}"></label>
                </div>
                <label class="block">Ассортимент
                    <textarea wire:model="description" rows="3" class="{{ $ta }}" placeholder="Чем занимается: узлы, бренды, особенности"></textarea>
                </label>
                <label class="block">Бренды <input type="text" wire:model="brands" placeholder="OTIS, KONE, Schindler" class="{{ $in }}"></label>
                <label class="block">Примечания (видим только мы)
                    <textarea wire:model="notes" rows="2" class="{{ $ta }}"></textarea>
                </label>
                @error('companyName')<div class="text-[12px] text-red-700">{{ $message }}</div>@enderror
                @error('country')<div class="text-[12px] text-red-700">{{ $message }}</div>@enderror
                <button type="button" wire:click="saveProfile" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveProfile">Сохранить профиль</button>
            </div>
        </div>

        {{-- Условия --}}
        <div class="ds-card">
            <div class="ds-card-header"><h3>Условия</h3>@if($this->termsLocked)<span class="text-[12px] text-fg-3 ml-2">согласованы с поставщиком</span>@endif</div>
            <div class="ds-card-body space-y-2 text-[12.5px]">
                <div class="grid grid-cols-3 gap-2">
                    <label>Incoterms
                        <select wire:model="incoterm" class="{{ $in }}" @disabled($this->termsLocked)>
                            <option value="">—</option>
                            @foreach(\App\Livewire\Suppliers\SupplierCard::INCOTERMS as $term)<option value="{{ $term }}">{{ $term }}</option>@endforeach
                        </select>
                    </label>
                    <label style="grid-column:span 2">Место <input type="text" wire:model="incotermPlace" placeholder="Ningbo" class="{{ $in }}" @disabled($this->termsLocked)></label>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <label>Валюта <input type="text" wire:model="currency" maxlength="3" placeholder="USD" class="{{ $in }} uppercase" @disabled($this->termsLocked)></label>
                    <label>Цена действует, дн. <input type="number" wire:model="validityDays" min="1" class="{{ $in }}" @disabled($this->termsLocked)></label>
                    <label class="flex items-end gap-1.5" style="padding-bottom:6px"><input type="checkbox" wire:model="packaging" @disabled($this->termsLocked)> упаковка в цене</label>
                </div>
                <label class="block">Оплата <input type="text" wire:model="paymentTerms" placeholder="30% предоплата, 70% перед отгрузкой" class="{{ $in }}" @disabled($this->termsLocked)></label>
                @error('currency')<div class="text-[12px] text-red-700">{{ $message }}</div>@enderror
                @unless($this->termsLocked)
                    <button type="button" wire:click="saveTerms" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="saveTerms">Сохранить условия</button>
                @endunless
            </div>
        </div>
    </div>

    {{-- Контакты по темам --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>Контакты</h3><span class="text-[12px] text-fg-3 ml-2">запрос цены уходит первому с темой «Расценка», если не выбрать адреса вручную</span></div>
        <div class="ds-card-body text-[12.5px]">
            <table class="w-full">
                <thead><tr class="text-left text-fg-3 text-[12px]">
                    <th class="py-1 pr-2">Адрес</th><th class="pr-2">Имя</th><th class="pr-2">Телефон</th>
                    @foreach(\App\Livewire\Suppliers\SupplierCard::TOPICS as $t => $label)<th class="pr-1 text-center font-normal" title="{{ $label }}">{{ mb_substr($label, 0, 10) }}</th>@endforeach
                    <th></th>
                </tr></thead>
                <tbody>
                @foreach($contacts as $i => $c)
                    <tr class="border-t border-border" wire:key="c-{{ $c['email'] }}">
                        <td class="py-1 pr-2 mono text-[12px]">{{ $c['email'] }}</td>
                        <td class="pr-2"><input type="text" wire:model="contacts.{{ $i }}.name" class="{{ $in }}"></td>
                        <td class="pr-2"><input type="text" wire:model="contacts.{{ $i }}.phone" class="{{ $in }}" style="max-width:150px"></td>
                        @foreach(\App\Livewire\Suppliers\SupplierCard::TOPICS as $t => $label)
                            <td class="text-center"><input type="checkbox" wire:model="contacts.{{ $i }}.topics" value="{{ $t }}" title="{{ $label }}"></td>
                        @endforeach
                        <td class="whitespace-nowrap text-right">
                            <button type="button" wire:click="moveContact({{ $i }}, -1)" class="btn btn-xs" title="Выше">↑</button>
                            <button type="button" wire:click="moveContact({{ $i }}, 1)" class="btn btn-xs" title="Ниже">↓</button>
                            <button type="button" wire:click="removeContact({{ $i }})" class="btn btn-xs text-fg-3" title="Убрать из контактов">×</button>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="flex items-center gap-2 mt-3">
                <input type="email" wire:model="newEmail" wire:keydown.enter="addContact" placeholder="новый@адрес" class="{{ $in }}" style="max-width:260px">
                <button type="button" wire:click="addContact" class="btn btn-xs">+ Контакт</button>
                <button type="button" wire:click="saveContacts" class="btn btn-primary btn-sm ml-auto" wire:loading.attr="disabled" wire:target="saveContacts">Сохранить контакты</button>
            </div>
            @error('newEmail')<div class="text-[12px] text-red-700 mt-1">{{ $message }}</div>@enderror
            @error('contacts')<div class="text-[12px] text-red-700 mt-1">{{ $message }}</div>@enderror
            @if(! empty($p['supplier_contacts']))
                <div class="mt-3 text-[12px] text-fg-3">Сам поставщик в кабинете назначил:
                    @foreach($p['supplier_contacts'] as $sc){{ \App\Livewire\Suppliers\SupplierCard::TOPICS[$sc['topic']] ?? $sc['topic'] }} — {{ $sc['email'] }}@if(! $loop->last); @endif @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Матрица ассортимента --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>Матрица ассортимента</h3><span class="text-[12px] text-fg-3 ml-2">по ней поставщик подбирается к позиции</span>
            <button type="button" wire:click="rebuildMatrix" class="btn btn-xs ml-auto" wire:loading.attr="disabled" wire:target="rebuildMatrix">Собрать по описанию</button>
        </div>
        <div class="ds-card-body text-[12.5px] space-y-1">
            @if($matrix === [])
                <div class="text-fg-4">Матрицы нет. Опишите ассортимент в профиле и соберите её.</div>
            @else
                <div><span class="text-fg-3">Категории:</span> {{ implode(', ', (array) ($matrix['categories'] ?? [])) ?: '—' }}</div>
                <div><span class="text-fg-3">Бренды:</span> {{ implode(', ', (array) ($matrix['brands'] ?? [])) ?: '—' }}</div>
                @if(! empty($matrix['pairs']))<div><span class="text-fg-3">Пары:</span> {{ collect($matrix['pairs'])->map(fn ($x) => ($x['brand'] ?? '').' / '.($x['category'] ?? ''))->implode('; ') }}</div>@endif
                @if(! empty($p['matrix_updated_at']))<div class="text-[12px] text-fg-4">собрана {{ \Illuminate\Support\Carbon::parse($p['matrix_updated_at'])->format('d.m.Y H:i') }}</div>@endif
            @endif
        </div>
    </div>

    {{-- Объединение: тот же поставщик заведён дважды (два адреса порознь, дубль из 1С) --}}
    <div class="flex flex-wrap items-center gap-2 text-[12.5px]">
        <span class="text-fg-3">Это же поставщик:</span>
        <input type="text" wire:model="mergeQuery" wire:keydown.enter="mergeIn" placeholder="код 1С или название" class="{{ $in }}" style="max-width:240px">
        <button type="button" wire:click="mergeIn" wire:confirm="Присоединить найденного поставщика к «{{ $org->name }}»? Его адреса и история перейдут сюда." class="btn btn-xs">Объединить сюда</button>
    </div>

    {{-- Архив: неактуальный поставщик не виден в реестре и не предлагается в запросах цен --}}
    <div class="ds-card">
        <div class="ds-card-header"><h3>Архив</h3>
            <span class="text-[12px] text-fg-3 ml-2">{{ $org->inQuoteParts() ? 'в QuoteParts тоже: новые запросы ему не уходят, в кабинете поставщика нас нет' : 'поставщика нет в QuoteParts — архив только в mzCorp' }}</span>
        </div>
        <div class="ds-card-body text-[12.5px] space-y-2">
            @if($org->isArchived())
                <div>
                    В архиве с {{ $org->archived_at->format('d.m.Y H:i') }}@if($org->archivedBy) · {{ $org->archivedBy->name }}@endif
                    @if($org->archived_reason)<div class="text-fg-3 mt-0.5">Причина: {{ $org->archived_reason }}</div>@endif
                </div>
                <button type="button" wire:click="restoreFromArchive" wire:loading.attr="disabled" wire:target="restoreFromArchive" class="btn btn-xs">Вернуть из архива</button>
            @else
                <div class="text-fg-3">История запросов и переписка сохранятся, письма от поставщика по-прежнему узнаются. Вернуть можно в любой момент: реестр → фильтр «Архив».</div>
                <div class="flex flex-wrap items-center gap-2">
                    <input type="text" wire:model="archiveReason" maxlength="500" placeholder="Причина (необязательно): не работаем, закрылся, дубль…" class="{{ $in }}" style="max-width:420px">
                    <button type="button" wire:click="archive" wire:loading.attr="disabled" wire:target="archive"
                            wire:confirm="Убрать «{{ $org->name }}» в архив? Он перестанет предлагаться в запросах цен{{ $org->inQuoteParts() ? ', и QuoteParts перестанет слать ему запросы' : '' }}." class="btn btn-xs btn-danger">В архив</button>
                </div>
            @endif
        </div>
    </div>
</div>
