<div class="max-w-[1200px] mx-auto px-4 py-5">
    <div class="flex items-center gap-3 mb-1 flex-wrap">
        <h1 class="text-[18px] font-semibold text-fg-1">💡 Справочник опознания</h1>
        <span class="chip chip-neutral text-[11px]">типов {{ $this->counts['types'] }}</span>
        <span class="chip chip-ok text-[11px]">вопросов {{ $this->counts['hints'] }}</span>
        @if($this->counts['waiting'] > 0)
            <span class="chip chip-warn text-[11px]">ждут решения {{ $this->counts['waiting'] }}</span>
        @endif
    </div>
    <p class="text-[12.5px] text-fg-3 mb-4 max-w-[900px]">
        Что спросить у клиента, чтобы опознать деталь. Подсказка показывается у позиции без подбора по каталогу
        и в «Типовых вопросах» уточнения. Справочник пополняется сам по письмам-уточнениям менеджеров
        (разобрано писем: {{ $this->counts['letters'] }}): вопрос, заданный в {{ \App\Services\Catalog\IdentificationLearner::PROMOTE_AFTER }} разных письмах,
        становится подсказкой; новый тип детали ждёт подтверждения здесь — правило распознавания для него составлено автоматически.
        Чаще задаваемые вопросы — первыми. 🎯 Цель — какие данные вопрос должен дать: по ней видно, есть ли ответ у позиции уже (фото с читаемой маркировкой — есть, фото без неё — неполно).
    </p>

    <div class="flex items-center gap-3 mb-3 flex-wrap">
        <div class="inline-flex rounded-md border border-border overflow-hidden text-[12px]">
            @foreach(['all' => 'Все', 'candidates' => 'Ждут решения', 'disabled' => 'Выключенные'] as $key => $label)
                <button type="button" wire:click="$set('filter', '{{ $key }}')"
                        class="px-3 py-1.5 {{ $filter === $key ? 'bg-sky-600 text-white' : 'bg-surface text-fg-2' }}">{{ $label }}</button>
            @endforeach
        </div>
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Поиск: тип или вопрос"
               class="h-[32px] px-3 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500" style="width:260px">
        <button type="button" wire:click="startAddType" class="btn btn-sm">＋ Тип детали</button>
    </div>

    <div class="ds-card p-3 mb-4">
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-[12px] text-fg-2">Проверить название позиции:</span>
            <input type="text" wire:model.live.debounce.400ms="probe" placeholder="Например: Ролик отводки дверей Otis"
                   class="h-[30px] px-3 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500" style="width:360px">
            @if($this->probeResult !== null)
                @if($this->probeResult['key'] !== '')
                    <span class="text-[12.5px]">→ <b>{{ $this->probeResult['name'] }}</b></span>
                @else
                    <span class="text-[12.5px] text-fg-3">→ тип не узнан, подсказки не будет</span>
                @endif
            @endif
        </div>
    </div>

    @if($addingType || $editTypeId !== null)
        <div class="ds-card p-3 mb-4" style="border-color:#7dd3fc;">
            <div class="text-[13px] font-semibold mb-2">{{ $editTypeId !== null ? 'Тип детали' : 'Новый тип детали' }}</div>
            <div class="grid gap-2" style="grid-template-columns: 220px 1fr;">
                <label class="text-[12px] text-fg-2 pt-1.5">Название</label>
                <div>
                    <input type="text" wire:model="typeName" class="w-full h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px]">
                    @error('typeName')<div class="text-[11px]" style="color:#b91c1c;">{{ $message }}</div>@enderror
                </div>
                <label class="text-[12px] text-fg-2 pt-1.5">Как узнать в названии позиции</label>
                <div>
                    <input type="text" wire:model="typePattern" class="w-full h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] mono"
                           placeholder="начала слов через запятую: шкив, блок отвод">
                    <div class="text-[11px] text-fg-3 mt-0.5">Начала слов через запятую или регулярное выражение /…/iu. Тип выбирается по слову, которое стоит в названии раньше.</div>
                    @error('typePattern')<div class="text-[11px]" style="color:#b91c1c;">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="flex gap-2 mt-3">
                <button type="button" wire:click="saveType" class="btn btn-sm btn-primary">Сохранить</button>
                <button type="button" wire:click="cancel" class="btn btn-sm">Отмена</button>
            </div>
        </div>
    @endif

    @forelse($this->types as $type)
        <div wire:key="type-{{ $type->id }}" class="ds-card mb-3 overflow-hidden" @if(! $type->is_active) style="border-color:#fcd34d;" @endif>
            <div class="flex items-center gap-2 px-3 py-2 bg-surface-2 flex-wrap">
                <span class="text-[13.5px] font-semibold text-fg-1">{{ $type->name }}</span>
                <span class="text-[11px] text-fg-3 mono" title="{{ $type->pattern }}">{{ \Illuminate\Support\Str::limit($type->pattern, 70) }}</span>
                @if(! $type->is_active)
                    <span class="chip chip-warn text-[10.5px]">{{ $type->source === 'auto' ? 'найден в письмах — подтвердите' : 'выключен' }}</span>
                @endif
                @if($type->source === 'manual')<span class="chip chip-neutral text-[10.5px]">добавлен вручную</span>@endif
                <span class="ml-auto"></span>
                <button type="button" wire:click="startEditType({{ $type->id }})" class="btn btn-sm">✎ Правило</button>
                <button type="button" wire:click="toggleType({{ $type->id }})" class="btn btn-sm {{ $type->is_active ? '' : 'btn-primary' }}">
                    {{ $type->is_active ? 'Выключить' : '✓ Включить' }}
                </button>
            </div>
            <table class="w-full text-[12px]" style="border-collapse: collapse;">
                <tbody>
                    @foreach($type->hints as $hint)
                        <tr wire:key="hint-{{ $hint->id }}" class="border-t border-border-subtle align-top {{ $hint->status === 'disabled' ? 'opacity-50' : '' }}">
                            @if($editHintId === $hint->id)
                                <td class="px-3 py-2" colspan="4">
                                    <input type="text" wire:model="hintLabel" class="w-full h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] mb-1" placeholder="Название, 2–4 слова">
                                    @error('hintLabel')<div class="text-[11px]" style="color:#b91c1c;">{{ $message }}</div>@enderror
                                    <textarea wire:model="hintQuestion" rows="2" class="w-full px-2 py-1 border border-border rounded-md bg-surface text-[12.5px]" placeholder="Вопрос клиенту"></textarea>
                                    @error('hintQuestion')<div class="text-[11px]" style="color:#b91c1c;">{{ $message }}</div>@enderror
                                    <input type="text" wire:model="hintGoal" class="w-full h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] mt-1" placeholder="Цель — какие данные нужны: «модель и маркировка с корпуса»">
                                    @error('hintGoal')<div class="text-[11px]" style="color:#b91c1c;">{{ $message }}</div>@enderror
                                    <div class="flex gap-2 mt-1">
                                        <button type="button" wire:click="saveHint" class="btn btn-sm btn-primary">Сохранить</button>
                                        <button type="button" wire:click="cancel" class="btn btn-sm">Отмена</button>
                                    </div>
                                </td>
                            @else
                                <td class="px-3 py-2" style="width:220px">
                                    <div class="font-medium text-fg-1">{{ $hint->label }}</div>
                                    @if($hint->status === 'candidate')
                                        <span class="chip chip-warn text-[10px] mt-0.5">кандидат</span>
                                    @elseif($hint->status === 'disabled')
                                        <span class="chip chip-neutral text-[10px] mt-0.5">выключен</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-fg-2">
                                    {{ $hint->question }}
                                    <div class="text-[11px] mt-0.5" style="color:#0369a1">🎯 {{ $hint->goal ?: 'цель не задана' }}</div>
                                    @foreach($this->examples[$hint->id] ?? [] as $ex)
                                        <div class="text-[11px] text-fg-3 mt-0.5">
                                            «{{ \Illuminate\Support\Str::limit($ex->question, 110) }}»
                                            @if($ex->request_id)— <a href="{{ route('requests.show', $ex->request_id) }}" class="text-sky-700 hover:underline mono">{{ $ex->internal_code }}</a>@endif
                                        </div>
                                    @endforeach
                                </td>
                                <td class="px-3 py-2 text-[11px] text-fg-3 whitespace-nowrap" style="width:130px">
                                    спрашивали: <b class="text-fg-2">{{ $hint->asked_count }}</b>
                                    @if($hint->last_asked_at)<div>{{ $hint->last_asked_at->format('d.m.Y') }}</div>@endif
                                    <div>{{ ['seed' => 'начальный', 'auto' => 'из писем', 'manual' => 'вручную'][$hint->source] ?? $hint->source }}</div>
                                </td>
                                <td class="px-3 py-2 text-right whitespace-nowrap" style="width:190px">
                                    <button type="button" wire:click="startEditHint({{ $hint->id }})" class="btn btn-sm" title="Поправить">✎</button>
                                    @if($hint->status !== 'active')
                                        <button type="button" wire:click="setHintStatus({{ $hint->id }}, 'active')" class="btn btn-sm btn-primary">✓ В подсказки</button>
                                    @endif
                                    @if($hint->status !== 'disabled')
                                        <button type="button" wire:click="setHintStatus({{ $hint->id }}, 'disabled')" class="btn btn-sm">Выключить</button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    @if($addHintTypeId === $type->id)
                        <tr class="border-t border-border-subtle">
                            <td class="px-3 py-2" colspan="4">
                                <input type="text" wire:model="hintLabel" class="w-full h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] mb-1" placeholder="Название, 2–4 слова: «Фото таблички»">
                                @error('hintLabel')<div class="text-[11px]" style="color:#b91c1c;">{{ $message }}</div>@enderror
                                <textarea wire:model="hintQuestion" rows="2" class="w-full px-2 py-1 border border-border rounded-md bg-surface text-[12.5px]" placeholder="Вопрос клиенту: «Пришлите, пожалуйста, фото таблички…»"></textarea>
                                @error('hintQuestion')<div class="text-[11px]" style="color:#b91c1c;">{{ $message }}</div>@enderror
                                <input type="text" wire:model="hintGoal" class="w-full h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] mt-1" placeholder="Цель — какие данные нужны: «модель и маркировка с корпуса»">
                                @error('hintGoal')<div class="text-[11px]" style="color:#b91c1c;">{{ $message }}</div>@enderror
                                <div class="flex gap-2 mt-1">
                                    <button type="button" wire:click="saveHint" class="btn btn-sm btn-primary">Добавить</button>
                                    <button type="button" wire:click="cancel" class="btn btn-sm">Отмена</button>
                                </div>
                            </td>
                        </tr>
                    @else
                        <tr class="border-t border-border-subtle">
                            <td class="px-3 py-1.5" colspan="4">
                                <button type="button" wire:click="startAddHint({{ $type->id }})" class="text-[12px] text-sky-700 hover:underline">＋ вопрос</button>
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    @empty
        <div class="ds-card p-6 text-center text-fg-3">Ничего не найдено {{ trim($search) !== '' || $filter !== 'all' ? 'по фильтру' : '' }}</div>
    @endforelse
</div>
