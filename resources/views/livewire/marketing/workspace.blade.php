@php
    $inp = 'w-full h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500';
    $area = 'w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500';
    $lbl = 'block text-[11.5px] text-fg-3 mb-1';
    $sum = $this->periodSummary;
@endphp

<div class="space-y-4">

    {{-- Шапка: период + вкладки --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">📈 Маркетинг</h3>
            <span class="text-[12px] text-fg-3">доступы, план, журнал работ и отчёт по договору</span>
            <span class="flex-1"></span>
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden">
                <button type="button" wire:click="shiftPeriod(-1)" class="h-[30px] px-2.5 bg-surface text-fg-2 hover:text-fg-1 border-r border-border" title="Предыдущий месяц">‹</button>
                <span class="h-[30px] px-3 inline-flex items-center text-[12.5px] font-medium text-fg-1 bg-surface whitespace-nowrap">{{ $this->periodLabel() }}</span>
                <button type="button" wire:click="shiftPeriod(1)" class="h-[30px] px-2.5 bg-surface text-fg-2 hover:text-fg-1 border-l border-border" title="Следующий месяц">›</button>
            </div>
        </div>
        <div class="ds-card-body pt-0">
            @php
                $tabs = [
                    'access' => ['🔐 Доступы', count($this->services)],
                    'contacts' => ['📇 Записная книжка', $this->contactsTotal],
                    'plan' => ['🗒 План и заметки', $sum['plan'] + $sum['note']],
                    'log' => ['✅ Журнал работ', $sum['work']],
                    'report' => ['📄 Отчёт', null],
                    'profile' => ['🎭 Медиапрофиль', $this->profileEntries->flatten()->count()],
                    'review' => ['🔍 Проверка материалов', null],
                ];
            @endphp
            <div class="inline-flex items-stretch rounded-md border border-border overflow-hidden text-[12.5px]">
                @foreach($tabs as $k => [$label, $count])
                    <button type="button" wire:click="setTab('{{ $k }}')"
                            class="h-[30px] px-3 whitespace-nowrap font-medium border-r border-border last:border-r-0
                                   {{ $tab === $k ? 'bg-[var(--accent)] text-fg-on-accent' : 'bg-surface text-fg-2 hover:text-fg-1' }}">
                        {{ $label }}@if($count !== null) <span class="mono opacity-70">{{ $count }}</span>@endif
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    @if($flashMessage)
        <div class="ds-card"><div class="ds-card-body text-[13px] text-emerald-700">{{ $flashMessage }}</div></div>
    @endif
    @if($flashError)
        <div class="ds-card"><div class="ds-card-body text-[13px] text-amber-800">{{ $flashError }}</div></div>
    @endif

    {{-- ============================ ДОСТУПЫ ============================ --}}
    @if($tab === 'access')
        <div class="ds-card">
            <div class="ds-card-header">
                <h3 class="text-[15px] font-semibold text-fg-1">🔐 Доступы к сервисам</h3>
                <span class="text-[12px] text-fg-3">пароли и ключи хранятся зашифрованными, видны только админу</span>
                <span class="flex-1"></span>
                <button type="button" wire:click="{{ $showServiceForm ? 'cancelServiceForm' : 'startServiceCreate' }}"
                        class="btn btn-sm btn-primary">{{ $showServiceForm ? 'Отмена' : '+ Добавить сервис' }}</button>
            </div>

            @if($showServiceForm)
                <div class="ds-card-body border-b border-border-subtle">
                    <form wire:submit.prevent="saveService" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="{{ $lbl }}">Название</label>
                            <input type="text" wire:model="sName" maxlength="120" placeholder="Яндекс.Директ" class="{{ $inp }}">
                            @error('sName') <span class="text-[11px] text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="{{ $lbl }}">Категория</label>
                            <select wire:model="sCategory" class="{{ $inp }}">
                                @foreach($categories as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="{{ $lbl }}">Адрес входа</label>
                            <input type="text" wire:model="sUrl" maxlength="500" placeholder="https://direct.yandex.ru" class="{{ $inp }}">
                        </div>
                        <div>
                            <label class="{{ $lbl }}">Логин</label>
                            <input type="text" wire:model="sLogin" maxlength="255" class="{{ $inp }}">
                        </div>
                        <div>
                            <label class="{{ $lbl }}">Пароль</label>
                            <input type="text" wire:model="sPassword" autocomplete="off" class="{{ $inp }} mono">
                        </div>
                        <div>
                            <label class="{{ $lbl }}">API-ключ / токен</label>
                            <input type="text" wire:model="sApiKey" autocomplete="off" class="{{ $inp }} mono">
                        </div>
                        <div>
                            <label class="{{ $lbl }}">На кого оформлен</label>
                            <input type="text" wire:model="sOwner" maxlength="160" placeholder="ИП Маркелов / ООО «Мой Лифт»" class="{{ $inp }}">
                        </div>
                        <div class="md:col-span-2">
                            <label class="{{ $lbl }}">Секретное примечание <span class="text-fg-4">(2FA, резервные коды — шифруется)</span></label>
                            <input type="text" wire:model="sExtra" class="{{ $inp }}">
                        </div>
                        <div class="md:col-span-3">
                            <label class="{{ $lbl }}">Заметки <span class="text-fg-4">(не секрет: тариф, кто в поддержке, особенности)</span></label>
                            <textarea wire:model="sNotes" rows="2" class="{{ $area }}"></textarea>
                        </div>
                        <div class="md:col-span-3 flex items-center gap-3">
                            <label class="inline-flex items-center gap-2 text-[12.5px] text-fg-2">
                                <input type="checkbox" wire:model="sActive" class="rounded border-border"> используется
                            </label>
                            <span class="flex-1"></span>
                            <button type="button" wire:click="cancelServiceForm" class="btn btn-sm">Отмена</button>
                            <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
                        </div>
                    </form>
                </div>
            @endif

            <div class="ds-card-body">
                @forelse($this->services as $service)
                    @php $secrets = $this->revealedSecrets($service->id); $open = ! empty($secrets) || in_array($service->id, $revealed, true); @endphp
                    <div wire:key="svc-{{ $service->id }}"
                         class="py-2.5 {{ ! $loop->last ? 'border-b border-border-subtle' : '' }} {{ $service->is_active ? '' : 'opacity-60' }}">
                        <div class="flex flex-wrap items-center gap-2 text-[13px]">
                            <span class="font-medium text-fg-1">{{ $service->name }}</span>
                            <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-3)">{{ $service->categoryLabel() }}</span>
                            @if(! $service->is_active)
                                <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-4)">не используется</span>
                            @endif
                            @if($service->url)
                                <a href="{{ $service->url }}" target="_blank" rel="noopener noreferrer"
                                   class="text-[12px] text-sky-700 hover:underline">{{ \Illuminate\Support\Str::limit($service->url, 48) }}</a>
                            @endif
                            <span class="flex-1"></span>
                            @if($service->last_verified_at)
                                <span class="text-[11px] text-fg-4">проверен {{ $service->last_verified_at->format('d.m.Y') }}</span>
                            @endif
                            <button type="button" wire:click="markVerified({{ $service->id }})" class="btn btn-sm" title="Отметить, что доступ рабочий">✓ Проверен</button>
                            <button type="button" wire:click="toggleReveal({{ $service->id }})" class="btn btn-sm">
                                {{ $open ? '🙈 Скрыть' : '👁 Показать' }}
                            </button>
                            <button type="button" wire:click="startServiceEdit({{ $service->id }})" class="btn btn-sm">✎</button>
                            <button type="button" wire:click="deleteService({{ $service->id }})"
                                    wire:confirm="Удалить доступ «{{ $service->name }}»? Пароль будет потерян." class="btn btn-sm btn-danger">✕</button>
                        </div>

                        <div class="mt-1 text-[12px] text-fg-3 flex flex-wrap gap-x-4 gap-y-1">
                            @if($service->login)<span>Логин: <span class="mono text-fg-2">{{ $service->login }}</span></span>@endif
                            @if($service->account_owner)<span>Оформлен на: {{ $service->account_owner }}</span>@endif
                            @if(! $service->hasSecrets())<span class="text-amber-700">пароль не задан</span>@endif
                        </div>

                        @if($open && $secrets)
                            <div class="mt-2 p-2 rounded-md border border-border bg-[var(--bg-hover)] text-[12px] space-y-1">
                                @foreach(['password' => 'Пароль', 'api_key' => 'API-ключ', 'extra' => 'Примечание'] as $key => $label)
                                    @if(! empty($secrets[$key]))
                                        <div class="flex items-center gap-2">
                                            <span class="text-fg-3 w-[90px] shrink-0">{{ $label }}</span>
                                            <span class="mono text-fg-1 select-all break-all">{{ $secrets[$key] }}</span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        @if($service->notes)
                            <div class="mt-1 text-[12px] text-fg-2 whitespace-pre-line">{{ $service->notes }}</div>
                        @endif
                    </div>
                @empty
                    <div class="text-[13px] text-fg-3">Пока ни одного сервиса. Добавьте Яндекс.Директ, сервис рассылок, аналитику — всё, к чему нужны доступы.</div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- ======================= ЗАПИСНАЯ КНИЖКА ======================= --}}
    @if($tab === 'contacts')
        <div class="ds-card">
            <div class="ds-card-header flex-wrap">
                <h3 class="text-[15px] font-semibold text-fg-1">📇 Записная книжка</h3>
                <span class="text-[12px] text-fg-3">подрядчики и площадки по направлениям</span>
                <span class="flex-1"></span>
                <input type="search" wire:model.live.debounce.300ms="contactSearch"
                       placeholder="Поиск: направление, организация, адрес, заметка"
                       class="h-[30px] w-[280px] px-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500">
                <button type="button" wire:click="{{ $showContactForm ? 'cancelContactForm' : 'startContactCreate' }}"
                        class="btn btn-sm btn-primary">{{ $showContactForm ? 'Отмена' : '+ Добавить контакт' }}</button>
            </div>

            @if($showContactForm)
                <div class="ds-card-body border-b border-border-subtle">
                    <form wire:submit.prevent="saveContact" class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div>
                            <label class="{{ $lbl }}">Направление</label>
                            <input type="text" wire:model="cTopic" maxlength="80" list="mk-topics"
                                   placeholder="Календари" class="{{ $inp }}">
                            <datalist id="mk-topics">
                                @foreach($this->contactTopics as $topic)
                                    <option value="{{ $topic }}"></option>
                                @endforeach
                            </datalist>
                            @error('cTopic') <span class="text-[11px] text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <div class="md:col-span-2">
                            <label class="{{ $lbl }}">Организация</label>
                            <input type="text" wire:model="cOrganization" maxlength="200"
                                   placeholder="типография ООО «МИРАО»" class="{{ $inp }}">
                            @error('cOrganization') <span class="text-[11px] text-red-600">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label class="{{ $lbl }}">Контактное лицо</label>
                            <input type="text" wire:model="cPerson" maxlength="160" placeholder="Анна Февралева" class="{{ $inp }}">
                        </div>
                        <div>
                            <label class="{{ $lbl }}">Телефон</label>
                            <input type="text" wire:model="cPhone" maxlength="120" placeholder="+7 905 542-50-24" class="{{ $inp }}">
                        </div>
                        <div>
                            <label class="{{ $lbl }}">Адреса <span class="text-fg-4">(по одному в строке или через запятую)</span></label>
                            <textarea wire:model="cEmails" rows="2" placeholder="print2@m-ppk.ru" class="{{ $area }}"></textarea>
                        </div>
                        <div class="md:col-span-3">
                            <label class="{{ $lbl }}">Папка с файлами</label>
                            <input type="text" wire:model="cFolder" maxlength="500"
                                   placeholder="\\phobos\MyZip\CommonFiles\Marketing\КАЛЕНДАРИ" class="{{ $inp }} mono">
                        </div>
                        <div class="md:col-span-3">
                            <label class="{{ $lbl }}">Заметка</label>
                            <textarea wire:model="cNotes" rows="2"
                                      placeholder="съёмки моделей или мозаика; первый счёт по выставке ждём в сентябре 2026" class="{{ $area }}"></textarea>
                        </div>
                        <div class="md:col-span-3 flex items-center gap-3">
                            <label class="inline-flex items-center gap-2 text-[12.5px] text-fg-2">
                                <input type="checkbox" wire:model="cActive" class="rounded border-border"> работаем
                            </label>
                            <span class="flex-1"></span>
                            <button type="button" wire:click="cancelContactForm" class="btn btn-sm">Отмена</button>
                            <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
                        </div>
                    </form>
                </div>
            @endif

            <div class="ds-card-body space-y-4">
                @forelse($this->contactGroups as $topic => $contacts)
                    <div wire:key="topic-{{ md5($topic) }}">
                        <div class="flex items-baseline gap-2 mb-1.5">
                            <span class="text-[13px] font-semibold text-fg-1">{{ $topic }}</span>
                            <span class="mono text-[11px] text-fg-4">{{ count($contacts) }}</span>
                        </div>
                        <div class="border border-border rounded-md divide-y divide-[var(--border-subtle)]">
                            @foreach($contacts as $contact)
                                <div wire:key="ct-{{ $contact->id }}" class="px-3 py-2 {{ $contact->is_active ? '' : 'opacity-60' }}">
                                    <div class="flex flex-wrap items-center gap-2 text-[13px]">
                                        <span class="font-medium text-fg-1">{{ $contact->organization }}</span>
                                        @if($contact->contact_person)
                                            <span class="text-[12.5px] text-fg-2">· {{ $contact->contact_person }}</span>
                                        @endif
                                        @if($contact->phone)
                                            <span class="mono text-[12px] text-fg-2">{{ $contact->phone }}</span>
                                            <x-copy-button :value="$contact->phone" title="Скопировать телефон" />
                                        @endif
                                        @if(! $contact->is_active)
                                            <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-4)">не работаем</span>
                                        @endif
                                        <span class="flex-1"></span>
                                        <button type="button" wire:click="startContactEdit({{ $contact->id }})" class="btn btn-sm">✎</button>
                                        <button type="button" wire:click="deleteContact({{ $contact->id }})"
                                                wire:confirm="Удалить контакт «{{ $contact->organization }}»?" class="btn btn-sm btn-danger">✕</button>
                                    </div>

                                    @if($contact->emailList())
                                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px]">
                                            @foreach($contact->emailList() as $email)
                                                <span class="inline-flex items-center gap-1">
                                                    <a href="mailto:{{ $email }}" class="mono text-sky-700 hover:underline">{{ $email }}</a>
                                                    <x-copy-button :value="$email" title="Скопировать адрес" />
                                                </span>
                                            @endforeach
                                            @if(count($contact->emailList()) > 1)
                                                <x-copy-button :value="$contact->emailsJoined()" title="Скопировать все адреса одной строкой" />
                                            @endif
                                        </div>
                                    @endif

                                    @if($contact->folder_path)
                                        {{-- Ссылку на сетевую папку браузер не откроет — даём путь с копированием. --}}
                                        <div class="mt-1 flex items-center gap-1.5 text-[12px] text-fg-3">
                                            <span>📁</span>
                                            <span class="mono text-fg-2 break-all select-all">{{ $contact->folder_path }}</span>
                                            <x-copy-button :value="$contact->folder_path" title="Скопировать путь к папке" />
                                        </div>
                                    @endif

                                    @if($contact->notes)
                                        <div class="mt-1 text-[12.5px] text-fg-2 whitespace-pre-line">{{ $contact->notes }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="text-[13px] text-fg-3">
                        {{ $contactSearch !== '' ? 'По запросу «'.$contactSearch.'» ничего не нашлось.' : 'Книжка пустая. Добавьте первое направление — например, «Календари» или «Выставка».' }}
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- ======================== ПЛАН / ЖУРНАЛ ======================== --}}
    @if($tab === 'plan' || $tab === 'log')
        @php $isLog = $tab === 'log'; $rows = $isLog ? $this->logEntries : $this->planEntries; @endphp
        <div class="ds-card">
            <div class="ds-card-header flex-wrap">
                <h3 class="text-[15px] font-semibold text-fg-1">{{ $isLog ? '✅ Журнал работ' : '🗒 План и заметки' }}</h3>
                <span class="text-[12px] text-fg-3">
                    {{ $isLog ? 'из этих записей собирается ежемесячный отчёт' : 'план месяца попадает в п. 9 отчёта за предыдущий месяц' }}
                </span>
                <span class="flex-1"></span>
                @if($showEntryForm)
                    <button type="button" wire:click="cancelEntryForm" class="btn btn-sm">Отмена</button>
                @else
                    @if($isLog)
                        <button type="button" wire:click="startEntryCreate('work')" class="btn btn-sm btn-primary">+ Запись о работе</button>
                    @else
                        <button type="button" wire:click="startEntryCreate('plan')" class="btn btn-sm btn-primary">+ Пункт плана</button>
                        <button type="button" wire:click="startEntryCreate('note')" class="btn btn-sm">+ Заметка</button>
                    @endif
                @endif
            </div>

            @if($showEntryForm)
                <div class="ds-card-body border-b border-border-subtle">
                    <form wire:submit.prevent="saveEntry" class="space-y-3">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                            <div>
                                <label class="{{ $lbl }}">Тип</label>
                                <select wire:model="eKind" class="{{ $inp }}">
                                    @foreach(\App\Models\MarketingEntry::KINDS as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $lbl }}">Раздел отчёта</label>
                                <select wire:model.live="eSection" class="{{ $inp }}">
                                    @foreach($sections as $section)
                                        <option value="{{ $section->value }}">{{ $section->formNumber() }}. {{ $section->shortLabel() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $lbl }}">Статус</label>
                                <select wire:model="eStatus" class="{{ $inp }}">
                                    @foreach(\App\Models\MarketingEntry::STATUSES as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="{{ $lbl }}">{{ $isLog ? 'Дата выполнения' : 'Срок' }}</label>
                                <input type="date" wire:model="eHappenedOn" class="{{ $inp }}">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                            <div class="md:col-span-3">
                                <label class="{{ $lbl }}">Что сделано / что сделать</label>
                                <input type="text" wire:model="eTitle" maxlength="200"
                                       placeholder="Перезапустил товарную кампанию по эскалаторным цепям" class="{{ $inp }}">
                                @error('eTitle') <span class="text-[11px] text-red-600">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="{{ $lbl }}">Приоритет</label>
                                <select wire:model="ePriority" class="{{ $inp }}">
                                    @foreach(\App\Models\MarketingEntry::PRIORITIES as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="{{ $lbl }}">Подробности <span class="text-fg-4">(попадут в отчёт после заголовка)</span></label>
                            <textarea wire:model="eBody" rows="3" class="{{ $area }}"></textarea>
                        </div>

                        @if($eSection === \App\Enums\MarketingSection::Ads->value)
                            <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
                                @foreach(['spend' => 'Расходы, руб.', 'impressions' => 'Показы', 'clicks' => 'Переходы', 'leads' => 'Обращения', 'other' => 'Иные показатели'] as $key => $label)
                                    <div>
                                        <label class="{{ $lbl }}">{{ $label }}</label>
                                        <input type="text" wire:model="eMetrics.{{ $key }}" class="{{ $inp }} mono">
                                    </div>
                                @endforeach
                            </div>
                            <div class="text-[11.5px] text-fg-4">Показатели за месяц суммируются в п. 2 отчёта; стоимость обращения считается из суммы.</div>
                        @endif

                        <div class="flex items-center gap-3">
                            <span class="text-[12px] text-fg-3">Период: {{ $this->periodLabel() }}</span>
                            <span class="flex-1"></span>
                            <button type="button" wire:click="cancelEntryForm" class="btn btn-sm">Отмена</button>
                            <button type="submit" class="btn btn-sm btn-primary">Сохранить</button>
                        </div>
                    </form>
                </div>
            @endif

            <div class="ds-card-body">
                @forelse($rows as $entry)
                    <div wire:key="ent-{{ $entry->id }}"
                         class="flex flex-wrap items-start gap-2 py-2 {{ ! $loop->last ? 'border-b border-border-subtle' : '' }}">
                        <div class="flex-1 min-w-[260px]">
                            <div class="flex flex-wrap items-center gap-2 text-[13px]">
                                @if($entry->kind === \App\Models\MarketingEntry::KIND_NOTE)
                                    <span class="chip text-[10.5px]" style="background:var(--amber-50);color:var(--amber-800)">заметка</span>
                                @endif
                                <span class="font-medium text-fg-1">{{ $entry->title }}</span>
                                <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-3)">
                                    {{ $entry->sectionEnum()?->emoji() }} {{ $entry->sectionLabel() }}
                                </span>
                                @if($entry->status === \App\Models\MarketingEntry::STATUS_DONE)
                                    <span class="chip text-[10.5px]" style="background:var(--emerald-50);color:var(--emerald-700)"><span class="dot"></span>сделано</span>
                                @elseif($entry->status === \App\Models\MarketingEntry::STATUS_DROPPED)
                                    <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-4)">снято</span>
                                @elseif($entry->status === \App\Models\MarketingEntry::STATUS_IN_PROGRESS)
                                    <span class="chip text-[10.5px]" style="background:var(--neutral-100);color:var(--fg-3)"><span class="dot"></span>в работе</span>
                                @endif
                                @if($entry->priority === 1)
                                    <span class="chip text-[10.5px]" style="background:var(--red-50);color:var(--red-700)">высокий</span>
                                @endif
                                @if($entry->happened_on)
                                    <span class="mono text-[11.5px] text-fg-4">{{ $entry->happened_on->format('d.m.Y') }}</span>
                                @endif
                            </div>
                            @if($entry->body)
                                <div class="mt-0.5 text-[12.5px] text-fg-2 whitespace-pre-line">{{ $entry->body }}</div>
                            @endif
                            @if($entry->metrics)
                                <div class="mt-0.5 text-[11.5px] text-fg-3 mono flex flex-wrap gap-x-3">
                                    @foreach($entry->metrics as $k => $v)
                                        <span>{{ \App\Enums\MarketingSection::AD_METRICS[$k] ?? $k }}: {{ $v }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0">
                            @if(! $isLog && $entry->status !== \App\Models\MarketingEntry::STATUS_DONE)
                                <button type="button" wire:click="setEntryStatus({{ $entry->id }}, 'done')" class="btn btn-sm" title="Отметить выполненным">✓</button>
                                <button type="button" wire:click="movePlanToNextMonth({{ $entry->id }})" class="btn btn-sm" title="Перенести на следующий месяц">→</button>
                            @endif
                            <button type="button" wire:click="startEntryEdit({{ $entry->id }})" class="btn btn-sm">✎</button>
                            <button type="button" wire:click="deleteEntry({{ $entry->id }})" wire:confirm="Удалить запись?" class="btn btn-sm btn-danger">✕</button>
                        </div>
                    </div>
                @empty
                    <div class="text-[13px] text-fg-3">
                        {{ $isLog
                            ? 'За '.$this->periodLabel().' записей о работах нет. Добавьте — и отчёт соберётся сам.'
                            : 'На '.$this->periodLabel().' плана нет.' }}
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- ============================= ОТЧЁТ ============================= --}}
    @if($tab === 'report')
        @php $report = $this->report; $final = $report?->isFinal() ?? false; @endphp
        <div class="ds-card">
            <div class="ds-card-header flex-wrap">
                <h3 class="text-[15px] font-semibold text-fg-1">📄 Отчёт за {{ $this->periodLabel() }}</h3>
                <span class="text-[12px] text-fg-3">форма Приложения № 1 к договору</span>
                @if($report)
                    <span class="chip text-[10.5px]"
                          style="background:{{ $final ? 'var(--emerald-50)' : 'var(--neutral-100)' }};color:{{ $final ? 'var(--emerald-700)' : 'var(--fg-3)' }}">
                        <span class="dot"></span>{{ $report->statusLabel() }}
                    </span>
                @endif
                <span class="flex-1"></span>
                <button type="button" wire:click="rebuildReport" class="btn btn-sm"
                        wire:confirm="Пересобрать разделы из журнала? Ручные правки текста будут потеряны.">↻ Собрать из журнала</button>
                @if($report)
                    <a href="{{ route('marketing.report.download', $report->id) }}" class="btn btn-sm">⬇ Word</a>
                @endif
                @if($final)
                    <button type="button" wire:click="reopenReport" class="btn btn-sm">Вернуть в работу</button>
                @else
                    <button type="button" wire:click="saveReport" class="btn btn-sm">Сохранить</button>
                    <button type="button" wire:click="finalizeReport" class="btn btn-sm btn-primary">Отчёт сдан</button>
                @endif
            </div>

            <div class="ds-card-body space-y-4">
                {{-- Реквизиты договора --}}
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div>
                        <label class="{{ $lbl }}">Договор №</label>
                        <input type="text" wire:model="requisites.contract_number" class="{{ $inp }}">
                    </div>
                    <div>
                        <label class="{{ $lbl }}">Дата договора</label>
                        <input type="text" wire:model="requisites.contract_date" placeholder="«01» октября 2026 г." class="{{ $inp }}">
                    </div>
                    <div>
                        <label class="{{ $lbl }}">Исполнитель</label>
                        <input type="text" wire:model="requisites.contractor" class="{{ $inp }}">
                    </div>
                    <div>
                        <label class="{{ $lbl }}">Заказчик</label>
                        <input type="text" wire:model="requisites.customer" class="{{ $inp }}">
                    </div>
                </div>

                {{-- 1. Основные задачи --}}
                <div>
                    <div class="text-[13px] font-semibold text-fg-1 mb-1.5">1. Основные выполненные задачи</div>
                    <div class="space-y-1.5">
                        @for($i = 0; $i < 5; $i++)
                            <div class="flex items-center gap-2">
                                <span class="mono text-[11.5px] text-fg-4 w-[14px]">{{ $i + 1 }}</span>
                                <input type="text" wire:model="form.main_tasks.{{ $i }}" class="{{ $inp }}">
                            </div>
                        @endfor
                    </div>
                </div>

                {{-- 2–8. Разделы --}}
                @foreach($sections as $section)
                    <div>
                        <div class="text-[13px] font-semibold text-fg-1 mb-1.5">
                            {{ $section->formNumber() }}. {{ $section->emoji() }} {{ $section->label() }}
                        </div>
                        <div class="space-y-2">
                            @foreach($section->fields() as $field => $label)
                                <div>
                                    <label class="{{ $lbl }}">{{ $label }}</label>
                                    <textarea wire:model="form.sections.{{ $section->value }}.{{ $field }}" rows="2" class="{{ $area }}"></textarea>
                                </div>
                            @endforeach
                            @if($section === \App\Enums\MarketingSection::Ads)
                                <div class="grid grid-cols-2 md:grid-cols-6 gap-2">
                                    @foreach(\App\Enums\MarketingSection::AD_METRICS as $key => $label)
                                        <div>
                                            <label class="{{ $lbl }}">{{ $label }}</label>
                                            <input type="text" wire:model="form.ad_metrics.{{ $key }}" class="{{ $inp }} mono">
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach

                {{-- 9. План на следующий месяц --}}
                <div>
                    <div class="text-[13px] font-semibold text-fg-1 mb-1.5">
                        9. План и приоритеты на следующий месяц
                        <span class="text-[11.5px] font-normal text-fg-4">— подтягивается из плана на {{ \App\Models\MarketingReport::monthLabel($this->periodDate()->addMonth()) }}</span>
                    </div>
                    <div class="space-y-1.5">
                        @for($i = 0; $i < 5; $i++)
                            <div class="flex items-center gap-2">
                                <span class="mono text-[11.5px] text-fg-4 w-[14px]">{{ $i + 1 }}</span>
                                <input type="text" wire:model="form.next_plan.{{ $i }}" class="{{ $inp }}">
                            </div>
                        @endfor
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-1 border-t border-border-subtle">
                    <span class="text-[11.5px] text-fg-4">
                        Пустые разделы в Word не попадают — п. 4.2 договора это разрешает.
                    </span>
                    <span class="flex-1"></span>
                    @if(! $final)
                        <button type="button" wire:click="saveReport" class="btn btn-sm btn-primary">Сохранить отчёт</button>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- ─────────────── Медиапрофиль ───────────────
         Копилка утверждений о компании, из которых складывается рекламный
         образ. Рубрики выбраны так, чтобы каждая давала проверяемое
         требование к тексту: через них потом пойдут новости, рассылки и
         буклеты. Поэтому у записи есть короткое утверждение (оно и
         проверяется) и пояснение — примеры и оговорки для человека. --}}
    @if($tab === 'profile')
        @php $entries = $this->profileEntries; @endphp
        <div class="ds-card">
            <div class="ds-card-header">
                <h3 class="text-[15px] font-semibold text-fg-1">🎭 Медиапрофиль компании</h3>
                <span class="text-[12px] text-fg-3">
                    из чего складывается наш рекламный образ — и по чему потом сверяются материалы
                </span>
            </div>
            <div class="ds-card-body grid gap-4 md:grid-cols-2">
                @foreach(\App\Enums\MediaProfileFacet::ordered() as $facet)
                    @php $rows = $entries[$facet->value] ?? collect(); @endphp
                    <div class="ds-card p-3">
                        <div class="flex items-baseline gap-2 mb-1">
                            <span>{{ $facet->icon() }}</span>
                            <b class="text-[13px] text-fg-1">{{ $facet->label() }}</b>
                            <span class="mono text-[11px] text-fg-4">{{ $rows->count() }}</span>
                            <span class="flex-1"></span>
                            <button type="button" class="btn btn-xs"
                                    wire:click="startProfileEntry('{{ $facet->value }}')">добавить</button>
                        </div>
                        <div class="text-[11px] text-fg-4 mb-2">{{ $facet->hint() }}</div>

                        @forelse($rows as $entry)
                            <div class="py-1 border-t border-border-subtle text-[12.5px] {{ $entry->is_active ? '' : 'opacity-50' }}"
                                 wire:key="mp-{{ $entry->id }}">
                                <div class="flex items-baseline gap-2">
                                    @if($entry->is_strict)
                                        <span class="chip text-[10px]" style="background:var(--red-50);color:var(--red-700)"
                                              title="Обязательное требование — материал без него не выпускаем">строго</span>
                                    @endif
                                    <span class="flex-1 text-fg-1 break-words">{{ $entry->statement }}</span>
                                    <button type="button" class="btn btn-xs" wire:click="editProfileEntry({{ $entry->id }})">правка</button>
                                    <button type="button" class="btn btn-xs" wire:click="toggleProfileEntry({{ $entry->id }})"
                                            title="{{ $entry->is_active ? 'Убрать из проверки материалов' : 'Вернуть в проверку' }}">
                                        {{ $entry->is_active ? 'в архив' : 'вернуть' }}
                                    </button>
                                </div>
                                @if($entry->details)
                                    <div class="text-[11.5px] text-fg-3 break-words">{{ $entry->details }}</div>
                                @endif
                                <div class="text-[10.5px] text-fg-4">
                                    {{ $entry->author?->name ?? 'система' }} · {{ $entry->created_at?->format('d.m.Y') }}
                                </div>
                            </div>
                        @empty
                            <div class="text-[12px] text-fg-4 py-1">Пока пусто.</div>
                        @endforelse

                        @if($profileFacet === $facet->value)
                            <div class="mt-2 pt-2 border-t border-border">
                                <input type="text" wire:model="pStatement" placeholder="Утверждение — коротко и проверяемо"
                                       class="w-full h-[32px] px-2 border border-border rounded-md bg-surface text-[12.5px] mb-1">
                                <textarea wire:model="pDetails" rows="2" placeholder="Пояснение: примеры, оговорки, откуда взялось"
                                          class="w-full px-2 py-1 border border-border rounded-md bg-surface text-[12px] mb-1"></textarea>
                                <div class="flex items-center gap-2">
                                    <label class="flex items-center gap-1 text-[11.5px] text-fg-2">
                                        <input type="checkbox" wire:model="pStrict"> обязательное требование
                                    </label>
                                    <span class="flex-1"></span>
                                    <button type="button" class="btn btn-xs" wire:click="cancelProfileEntry">отмена</button>
                                    <button type="button" class="btn btn-xs btn-primary" wire:click="saveProfileEntry">сохранить</button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="ds-card-body pt-0 text-[11.5px] text-fg-3">
                «Строго» — то, нарушение чего в материале недопустимо; остальное система будет
                считать пожеланием. Архивная запись остаётся в истории, но в проверку не идёт.
            </div>
        </div>
    @endif

    {{-- ─────────────── Проверка материалов ───────────────
         Материал проходит через медиапрофиль: требования берутся только из
         него, замечания привязаны к цитатам, правка возвращается целым
         текстом. Выдумывать факты модели запрещено — цены и сроки берутся
         только из исходника. --}}
    @if($tab === 'review')
        @php $review = $this->mvReview; @endphp
        <div class="ds-card">
            <div class="ds-card-header flex-wrap">
                <h3 class="text-[15px] font-semibold text-fg-1">🔍 Проверка материала по медиапрофилю</h3>
                <span class="text-[12px] text-fg-3">новость, рассылка, буклет — сверяем со своим образом</span>
            </div>
            <div class="ds-card-body">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <select wire:model="mvKind"
                            class="h-[32px] px-2 border border-border rounded-md bg-surface text-[12.5px]">
                        @foreach(\App\Models\MediaProfileReview::KINDS as $k => $label)
                            <option value="{{ $k }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <input type="text" wire:model="mvTitle" placeholder="Название материала (необязательно)"
                           class="flex-1 min-w-[220px] h-[32px] px-2 border border-border rounded-md bg-surface text-[12.5px]">
                    <button type="button" class="btn btn-sm btn-primary" wire:click="runMaterialReview"
                            wire:loading.attr="disabled" wire:target="runMaterialReview">
                        <span wire:loading.remove wire:target="runMaterialReview">Проверить</span>
                        <span wire:loading wire:target="runMaterialReview">Читаю…</span>
                    </button>
                </div>

                <textarea wire:model="mvText" rows="12" placeholder="Вставьте текст материала"
                          class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] leading-relaxed"></textarea>

                @if($review)
                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                        <div>
                            <div class="text-[12px] text-fg-3 mb-1">
                                Замечания:
                                <b class="text-fg-1">{{ $review->strictCount() }}</b> обязательных,
                                <b class="text-fg-1">{{ $review->softCount() }}</b> пожеланий
                            </div>
                            @forelse($review->issues ?? [] as $issue)
                                <div class="ds-card p-2 mb-2 text-[12.5px]"
                                     style="{{ ($issue['severity'] ?? '') === 'strict'
                                        ? 'background:var(--red-50);border-color:var(--red-300)'
                                        : 'background:var(--amber-50);border-color:var(--amber-300)' }}">
                                    <div class="flex items-baseline gap-2">
                                        <b class="text-fg-1">{{ $issue['facet_label'] ?? '—' }}</b>
                                        <span class="text-[11px] text-fg-3">
                                            {{ ($issue['severity'] ?? '') === 'strict' ? 'обязательное' : 'пожелание' }}
                                        </span>
                                    </div>
                                    @if(($issue['quote'] ?? '') !== '')
                                        <div class="text-[12px] text-fg-2 italic break-words">«{{ $issue['quote'] }}»</div>
                                    @endif
                                    <div class="text-fg-1 break-words">{{ $issue['problem'] ?? '' }}</div>
                                    @if(($issue['fix'] ?? '') !== '')
                                        <div class="text-[12px] text-fg-3 break-words">→ {{ $issue['fix'] }}</div>
                                    @endif
                                </div>
                            @empty
                                <div class="ds-card p-2 text-[12.5px]"
                                     style="background:var(--emerald-50);border-color:var(--emerald-300)">
                                    Материал профилю соответствует — замечаний нет.
                                </div>
                            @endforelse
                        </div>

                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-[12px] text-fg-3 flex-1">Материал с правками</span>
                                @if($review->rewritten_text)
                                    <button type="button" class="btn btn-xs" wire:click="acceptRewrite">
                                        Перенести в поле выше
                                    </button>
                                @endif
                            </div>
                            <textarea rows="14" readonly
                                      class="w-full px-3 py-2 border border-border rounded-md bg-surface text-[13px] leading-relaxed"
                            >{{ $review->rewritten_text ?? 'Модель правок не предложила.' }}</textarea>
                            <div class="text-[11px] text-fg-4 mt-1">
                                Цены, сроки и обещания модель брать из воздуха не может — если для правки
                                нужен новый факт, она оставит «[уточнить]» и скажет об этом замечанием.
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        @if($this->mvHistory->isNotEmpty())
            <div class="ds-card">
                <div class="ds-card-header">
                    <h3 class="text-[15px] font-semibold text-fg-1">Последние проверки</h3>
                    <span class="text-[12px] text-fg-3">повторяющиеся замечания — повод дополнить профиль, а не текст</span>
                </div>
                <div class="ds-card-body">
                    @foreach($this->mvHistory as $r)
                        <div class="flex items-baseline gap-2 py-1 border-b border-border-subtle text-[12.5px]"
                             wire:key="mvr-{{ $r->id }}">
                            <span class="chip text-[10px]" style="background:var(--neutral-100);color:var(--fg-3)">{{ $r->kindLabel() }}</span>
                            <span class="flex-1 truncate text-fg-1">
                                {{ $r->title ?: \Illuminate\Support\Str::limit(trim($r->source_text), 70) }}
                            </span>
                            <span class="mono text-[11px] {{ $r->strictCount() > 0 ? 'text-[var(--red-700)]' : 'text-fg-4' }}">
                                {{ $r->strictCount() }} / {{ $r->softCount() }}
                            </span>
                            <span class="text-[11px] text-fg-4">{{ $r->author?->name }} · {{ $r->created_at?->format('d.m H:i') }}</span>
                            <button type="button" class="btn btn-xs" wire:click="openMaterialReview({{ $r->id }})">открыть</button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
