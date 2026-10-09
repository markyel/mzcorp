@php
    $inp = 'w-full h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500';
    $area = 'w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500';
    $pub = $this->openPub;
@endphp

<div class="space-y-4">
    {{-- Страница длинная, кнопки внизу: ответ должен быть виден там, где нажали. --}}
    @if($flash || $error)
        <div class="sticky top-0 z-20">
            @if($flash)
                <div class="ds-card"><div class="ds-card-body text-[13px] text-emerald-700">{{ $flash }}</div></div>
            @endif
            @if($error)
                <div class="ds-card"><div class="ds-card-body text-[13px] text-amber-800">{{ $error }}</div></div>
            @endif
        </div>
    @endif

    {{-- ─────────────── Ближайшие выпуски ─────────────── --}}
    @php
        $runAt = \App\Services\Marketing\MediaAutopilotService::runAt();
        $lastRun = $this->lastRun;
    @endphp
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🗓 Ближайшие выпуски</h3>
            <span class="text-[12px] text-fg-3">что конвейер сделает сам в ближайшие 4 недели · прогон ежедневно в {{ $runAt }}</span>
        </div>

        @if($lastRun)
            @php $lastAt = \Illuminate\Support\Carbon::parse($lastRun['at']); @endphp
            <div class="ds-card-body border-b border-border-subtle text-[12px] text-fg-2">
                Последний прогон {{ $lastAt->format('d.m H:i') }}:
                черновиков {{ $lastRun['drafted'] }}, опубликовано {{ $lastRun['published'] }}@if(! $lastRun['publish']) (без публикации)@endif.
                @if($lastRun['skipped'])
                    <ul class="mt-1 space-y-0.5">
                        @foreach(array_slice($lastRun['skipped'], 0, 8) as $line)
                            <li class="text-amber-800">— {{ $line }}</li>
                        @endforeach
                        @if(count($lastRun['skipped']) > 8)
                            <li class="text-fg-4">и ещё {{ count($lastRun['skipped']) - 8 }}</li>
                        @endif
                    </ul>
                @endif
            </div>
        @endif

        <div class="ds-card-body">
            @forelse($this->upcoming as $day => $rows)
                @php $d = \Illuminate\Support\Carbon::parse($day); @endphp
                <div class="grid gap-x-3 gap-y-1 py-2 border-b border-border-subtle last:border-b-0 md:grid-cols-[110px_1fr]"
                     wire:key="up-{{ $day }}">
                    <div class="text-[12px] text-fg-3">
                        <b class="text-[13px] text-fg-1 mono">{{ $d->format('d.m') }}</b>
                        {{ \App\Models\MediaTopic::WEEKDAYS[$d->isoWeekday()] }}
                        @if($d->isToday())<span class="chip text-[10px]" style="background:var(--sky-50);color:var(--sky-700)">сегодня</span>@endif
                        @if($d->isTomorrow())<span class="text-fg-4">завтра</span>@endif
                    </div>
                    <div class="space-y-1.5">
                        @foreach($rows as $row)
                            <div wire:key="up-{{ $day }}-{{ $row['topic']->id }}">
                                <div class="flex flex-wrap items-baseline gap-2">
                                    <b class="text-[12.5px] text-fg-1">{{ $row['topic']->title }}</b>
                                    <span class="text-[11px] text-fg-4">{{ $row['topic']->cadenceLabel() }}</span>
                                    @if($row['overdue_since'])
                                        <span class="chip text-[10px]" style="background:var(--amber-100);color:var(--amber-800)"
                                              title="Срок темы прошёл, а выпуска не было — конвейер пробует каждый день">
                                            срок был {{ $row['overdue_since']->format('d.m') }}
                                        </span>
                                    @endif
                                    @if($row['data_driven'])
                                        <span class="text-[11px] text-fg-4"
                                              title="{{ $this->sourceNote($row['topic']->source) }}">выйдет, если за период найдутся данные</span>
                                    @endif
                                </div>
                                @if($row['note'])
                                    <div class="text-[11.5px] text-amber-800">{{ $row['note'] }}</div>
                                @endif
                                @foreach($row['entries'] as $e)
                                    <div class="flex flex-wrap items-baseline gap-2 pl-3 text-[11.5px]">
                                        <span class="text-fg-2">{{ $e['channel']->name }}@if($e['mirrors']) <span class="text-fg-4">+ {{ implode(', ', $e['mirrors']) }}</span>@endif</span>
                                        @if($e['blocker'])
                                            <span class="chip text-[10px]" style="background:var(--amber-50);color:var(--amber-800)">не выйдет</span>
                                            <span class="text-amber-800">{{ $e['blocker'] }}</span>
                                        @elseif($e['mode'] === 'publish')
                                            <span class="chip text-[10px]" style="background:var(--emerald-50);color:var(--emerald-700)"
                                                  title="Канал с автопубликацией: материал уйдёт без просмотра">опубликуется сам в {{ $runAt }}</span>
                                        @else
                                            <span class="chip text-[10px]" style="background:var(--neutral-100);color:var(--fg-3)"
                                                  title="Автопубликация в канале выключена: материал ляжет в «Материалы»">черновик на проверку</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="text-[12.5px] text-fg-3">
                    Регулярных тем со сроком нет — конвейеру нечего планировать. Задайте теме регулярность
                    («раз в N дн.») и дату, и она появится здесь.
                </p>
            @endforelse
        </div>
    </div>

    {{-- ─────────────── Каналы ─────────────── --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">📡 Каналы</h3>
            <span class="text-[12px] text-fg-3">где мы говорим с аудиторией</span>
            <span class="flex-1"></span>
            <button type="button" class="btn btn-sm btn-primary" wire:click="startChannel">Добавить канал</button>
        </div>

        @if($chForm)
            <div class="ds-card-body border-b border-border-subtle">
                <div class="grid gap-2 md:grid-cols-4 mb-2">
                    <input type="text" wire:model="chName" placeholder="Название" class="{{ $inp }}">
                    <select wire:model="chKind" class="{{ $inp }}">
                        @foreach(\App\Models\MediaChannel::KINDS as $k => $label)
                            <option value="{{ $k }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <input type="text" wire:model="chHandle" placeholder="@канал / id сообщества" class="{{ $inp }}">
                    <input type="text" wire:model="chPerWeek" placeholder="публикаций в неделю" class="{{ $inp }}">
                </div>
                <div class="grid gap-2 md:grid-cols-2 mb-2">
                    <input type="text" wire:model="chUrl" placeholder="Ссылка на канал" class="{{ $inp }}">
                    <select wire:model="chMirrorOf" class="{{ $inp }}"
                            title="Площадка забирает посты из другого канала — свой материал ей не пишут">
                        <option value="">публикуем сюда сами</option>
                        @foreach($this->channels->where('is_active', true)->whereNull('mirror_of_channel_id') as $src)
                            @if($src->id !== $chEditId)
                                <option value="{{ $src->id }}">повторяет: {{ $src->name }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
                <textarea wire:model="chNotes" rows="2" class="{{ $area }}" placeholder="Кто ведёт, особенности, ограничения"></textarea>
                <div class="flex items-center gap-2 mt-2">
                    <span class="flex-1"></span>
                    <button type="button" class="btn btn-sm" wire:click="cancelChannel">Отмена</button>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="saveChannel">Сохранить</button>
                </div>
            </div>
        @endif

        <div class="ds-card-body">
            @forelse($this->channels as $ch)
                <div class="flex flex-wrap items-baseline gap-2 py-1.5 border-b border-border-subtle last:border-b-0"
                     wire:key="ch-{{ $ch->id }}" style="{{ $ch->is_active ? '' : 'opacity:.5' }}">
                    <b class="text-[13px] text-fg-1">{{ $ch->name }}</b>
                    <span class="chip text-[10px]" style="background:var(--neutral-100);color:var(--fg-3)">{{ $ch->kindLabel() }}</span>
                    @if($ch->handle)<span class="mono text-[11.5px] text-fg-3">{{ $ch->handle }}</span>@endif
                    @if($ch->url)
                        <a href="{{ $ch->url }}" target="_blank" rel="noopener" class="text-[11.5px] underline">открыть</a>
                    @endif
                    @if($ch->posts_per_week)
                        <span class="text-[11.5px] text-fg-3">план {{ $ch->posts_per_week }}/нед</span>
                    @endif
                    @if($ch->isMirror())
                        <span class="chip text-[10px]" style="background:var(--violet-50);color:var(--violet-700)"
                              title="Площадка забирает посты из этого канала сама — отдельный материал ей не пишется">
                            повторяет: {{ $ch->mirrorOf?->name }}
                        </span>
                    @elseif($ch->isPostable())
                        <span class="chip text-[10px]"
                              style="{{ $ch->isConnected()
                                ? 'background:var(--emerald-50);color:var(--emerald-700)'
                                : 'background:var(--amber-50);color:var(--amber-800)' }}"
                              title="{{ $ch->isConnected() ? 'Доступ настроен' : 'Доступ не заполнен — публиковать нечем' }}">
                            {{ $ch->isConnected() ? 'подключён' : 'нет доступа' }}
                        </span>
                        @if($ch->auto_publish)
                            <span class="chip text-[10px]" style="background:var(--sky-50);color:var(--sky-700)"
                                  title="Материалы по регулярным темам уходят сюда без просмотра">авто</span>
                        @endif
                    @endif
                    <span class="flex-1"></span>
                    @if($ch->last_error)
                        <span class="text-[11px] text-amber-800" title="{{ $ch->last_error }}">ошибка площадки</span>
                    @endif
                    @if($ch->last_posted_at)
                        <span class="text-[11px] text-fg-4">последняя {{ $ch->last_posted_at->format('d.m H:i') }}</span>
                    @endif
                    <span class="text-[11.5px] text-fg-3 mono">опубликовано: {{ $ch->published_count }}</span>
                    @if($ch->isPostable())
                        <button type="button" class="btn btn-xs" wire:click="startCredentials({{ $ch->id }})">доступ</button>
                        <button type="button" class="btn btn-xs" wire:click="checkChannel({{ $ch->id }})"
                                wire:loading.attr="disabled" wire:target="checkChannel({{ $ch->id }})">связь</button>
                        <button type="button" class="btn btn-xs {{ $ch->auto_publish ? 'btn-primary' : '' }}"
                                wire:click="toggleAutoPublish({{ $ch->id }})"
                                wire:confirm="{{ $ch->auto_publish
                                    ? 'Выключить автопубликацию? Материалы будут ждать вашей кнопки.'
                                    : 'Включить автопубликацию? Материалы по регулярным темам будут уходить в ленту без просмотра.' }}">
                            авто: {{ $ch->auto_publish ? 'вкл' : 'выкл' }}
                        </button>
                    @endif
                    <button type="button" class="btn btn-xs" wire:click="editChannel({{ $ch->id }})">править</button>
                    <button type="button" class="btn btn-xs" wire:click="toggleChannel({{ $ch->id }})">
                        {{ $ch->is_active ? 'в архив' : 'вернуть' }}
                    </button>
                </div>

                @if($credFor === $ch->id)
                    <div class="p-2 mb-2 rounded-md border border-border-subtle" wire:key="cred-{{ $ch->id }}">
                        <div class="grid gap-2 md:grid-cols-2 mb-2">
                            <input type="password" wire:model="credToken" autocomplete="new-password"
                                   placeholder="{{ $ch->kind === 'vk' ? 'Токен сообщества (права wall)' : 'Токен бота' }}"
                                   class="{{ $inp }}">
                            <input type="text" wire:model="credTarget"
                                   placeholder="{{ match ($ch->kind) { 'vk' => 'Ссылка на сообщество, короткое имя или id', 'max' => 'Ссылка на канал, его название или id', default => '@канал или chat_id' } }}"
                                   class="{{ $inp }}">
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="flex-1 text-[11px] text-fg-4">
                                {{ match ($ch->kind) {
                                    'vk' => 'Токен сообщества берётся в «Управление → Работа с API → Ключи доступа», нужны права «Стена». Во второе поле можно вставить ссылку на сообщество или его короткое имя — числовой id подставим сами. Токен хранится зашифрованным и на экран не возвращается.',
                                    'max' => 'Токен бота — на платформе MAX для партнёров: «Чат-боты → ⋮ → Настройки». Бота добавьте в канал администратором с правом публикации. Второе поле можно оставить пустым: после добавления бота нажмите «Проверить связь» — канал найдётся сам. Токен хранится зашифрованным.',
                                    default => 'Бот должен быть администратором канала с правом публикации. Токен хранится зашифрованным.',
                                } }}
                            </span>
                            <button type="button" class="btn btn-xs" wire:click="cancelCredentials">Отмена</button>
                            <button type="button" class="btn btn-xs btn-primary" wire:click="saveCredentials">Сохранить</button>
                        </div>
                    </div>
                @endif
            @empty
                <p class="text-[12.5px] text-fg-3">
                    Каналов пока нет. Заведите те, что уже есть: Директ, рассылку, блок в письмах, Телеграм,
                    Дзен, ВК — дальше по ним планируются темы.
                </p>
            @endforelse
        </div>
    </div>

    {{-- ─────────────── Темы ─────────────── --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🗂 Темы</h3>
            <span class="text-[12px] text-fg-3">о чём говорим и как часто</span>
            <span class="flex-1"></span>
            @if($this->dueTopics->isNotEmpty())
                <span class="chip text-[10px]" style="background:var(--amber-100);color:var(--amber-800)">
                    пора публиковать: {{ $this->dueTopics->count() }}
                </span>
            @endif
            <button type="button" class="btn btn-sm" wire:click="spreadTopics"
                    wire:confirm="Разложить регулярные темы по разным будням? Сроки сдвинутся на ближайший подходящий день."
                    title="Раскидать темы по понедельникам–пятницам, чтобы не выходили одной пачкой">
                разнести по дням
            </button>
            <button type="button" class="btn btn-sm btn-primary" wire:click="startTopic">Добавить тему</button>
        </div>

        @if($tpForm)
            <div class="ds-card-body border-b border-border-subtle">
                <div class="grid gap-2 md:grid-cols-4 mb-2">
                    <input type="text" wire:model="tpTitle" placeholder="Название темы" class="{{ $inp }} md:col-span-2">
                    <select wire:model.live="tpSource" class="{{ $inp }}">
                        @foreach(\App\Models\MediaTopic::SOURCES as $k => $label)
                            <option value="{{ $k }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="grid grid-cols-3 gap-2">
                        <input type="text" wire:model="tpCadence" placeholder="раз в N дн." class="{{ $inp }}">
                        <select wire:model="tpWeekday" class="{{ $inp }}" title="День недели публикации">
                            <option value="">день любой</option>
                            @foreach(\App\Models\MediaTopic::WEEKDAYS as $n => $label)
                                <option value="{{ $n }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <input type="date" wire:model="tpNextDue" class="{{ $inp }}">
                    </div>
                </div>
                <textarea wire:model="tpBrief" rows="3" class="{{ $area }}"
                          placeholder="Бриф: о чём тема, для кого, что в ней должно быть всегда"></textarea>
                <div class="flex items-center gap-2 mt-2">
                    <span class="flex-1 text-[11.5px] text-fg-4">{{ $this->sourceNote($tpSource) }}</span>
                    <button type="button" class="btn btn-sm" wire:click="cancelTopic">Отмена</button>
                    <button type="button" class="btn btn-sm btn-primary" wire:click="saveTopic">Сохранить</button>
                </div>
            </div>
        @endif

        <div class="ds-card-body space-y-2">
            @forelse($this->topics as $t)
                <div class="ds-card p-3" wire:key="tp-{{ $t->id }}"
                     style="{{ $t->is_active ? ($t->isDue() ? 'background:var(--amber-50);border-color:var(--amber-300)' : '') : 'opacity:.5' }}">
                    <div class="flex flex-wrap items-baseline gap-2">
                        <b class="text-[13px] text-fg-1">{{ $t->title }}</b>
                        <span class="chip text-[10px]" style="background:var(--neutral-100);color:var(--fg-3)">{{ $t->sourceLabel() }}</span>
                        <span class="text-[11.5px] text-fg-3">
                            {{ $t->cadenceLabel() }}@if($t->weekdayLabel()), {{ $t->weekdayLabel() }}@endif
                        </span>
                        @if($t->next_due_on)
                            <span class="text-[11.5px] {{ $t->isDue() ? 'text-amber-800' : 'text-fg-3' }}">
                                срок {{ $t->next_due_on->format('d.m.Y') }}
                            </span>
                        @endif
                        <span class="flex-1"></span>
                        <span class="text-[11.5px] text-fg-3 mono">материалов: {{ $t->publications_count }}</span>
                        <button type="button" class="btn btn-xs" wire:click="editTopic({{ $t->id }})">править</button>
                        <button type="button" class="btn btn-xs" wire:click="toggleTopic({{ $t->id }})">
                            {{ $t->is_active ? 'в архив' : 'вернуть' }}
                        </button>
                    </div>

                    @if($t->brief)
                        <div class="text-[12.5px] text-fg-2 mt-1 break-words">{{ $t->brief }}</div>
                    @endif

                    @if($t->is_active)
                        <div class="flex flex-wrap items-center gap-2 mt-2">
                            <select wire:model="draftChannel.{{ $t->id }}"
                                    class="h-[28px] px-2 border border-border rounded-md bg-surface text-[12px]">
                                <option value="">— канал —</option>
                                {{-- Зеркала не предлагаем: им материал не пишут, они повторяют чужой. --}}
                                @foreach($this->channels->where('is_active', true)->whereNull('mirror_of_channel_id') as $ch)
                                    <option value="{{ $ch->id }}">{{ $ch->name }}</option>
                                @endforeach
                            </select>
                            <button type="button" class="btn btn-xs btn-primary" wire:click="draft({{ $t->id }})"
                                    wire:loading.attr="disabled" wire:target="draft({{ $t->id }})">
                                <span wire:loading.remove wire:target="draft({{ $t->id }})">написать черновик</span>
                                <span wire:loading wire:target="draft({{ $t->id }})">пишу…</span>
                            </button>
                            <span class="text-[11px] text-fg-4">{{ $this->sourceNote($t->source) }}</span>
                        </div>

                        {{-- Повод сегодняшнего материала. Для новости обязателен: о выставке
                             или изменении в работе система знать не может, и без фактов
                             модель их выдумает. Для остальных тем — необязательный акцент. --}}
                        <textarea wire:model="draftNote.{{ $t->id }}" rows="2"
                                  class="{{ $area }} mt-2"
                                  placeholder="{{ $t->source === 'news'
                                      ? 'Что произошло: событие, дата, место, участники, чем полезно читателю'
                                      : 'Необязательно: на чём сделать акцент в этом выпуске' }}"></textarea>
                    @endif
                </div>
            @empty
                <p class="text-[12.5px] text-fg-3">
                    Тем пока нет. Регулярные — «новые позиции каталога», «снижение цен», «советы по оформлению
                    заявок»: материал для них система соберёт из наших же данных. Разовые — новости и события.
                </p>
            @endforelse
        </div>
    </div>

    {{-- ─────────────── Материалы ─────────────── --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">📝 Материалы</h3>
            <span class="text-[12px] text-fg-3">черновик → проверка по профилю → согласовано → опубликовано</span>
            <span class="flex-1"></span>
            <label class="flex items-center gap-1 text-[11.5px] text-fg-2">
                <input type="checkbox" wire:model.live="showArchive"> показывать опубликованные
            </label>
        </div>

        @if($pub)
            <div class="ds-card-body border-b border-border-subtle">
                <div class="flex flex-wrap items-baseline gap-2 mb-2">
                    <b class="text-[13px] text-fg-1">{{ $pub->topic?->title ?? 'Без темы' }}</b>
                    @if($pub->channel)
                        <span class="chip text-[10px]" style="background:var(--neutral-100);color:var(--fg-3)">{{ $pub->channel->name }}</span>
                    @endif
                    <span class="chip text-[10px]" style="background:var(--sky-50);color:var(--sky-700)">{{ $pub->statusLabel() }}</span>
                    <span class="flex-1"></span>
                    <button type="button" class="btn btn-xs" wire:click="closePublication">свернуть</button>
                </div>

                <div class="grid gap-2 md:grid-cols-3 mb-2">
                    <input type="text" wire:model="pubTitle" placeholder="Заголовок" class="{{ $inp }} md:col-span-2">
                    <input type="date" wire:model="pubPlannedFor" class="{{ $inp }}">
                </div>
                <textarea wire:model="pubBody" rows="14" class="{{ $area }} leading-relaxed"></textarea>

                {{-- Фото позиций: в ВК уходят вложениями, в Telegram и MAX альбомом. --}}
                @if($pub->images())
                    <div class="mt-2">
                        <div class="text-[11.5px] text-fg-3 mb-1">
                            Фото в публикации: {{ count($pub->images()) }} — уйдут вместе с текстом, лишние уберите
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @foreach($pub->images() as $img)
                                <div class="relative" wire:key="img-{{ md5($img) }}">
                                    <img src="{{ $img }}" alt="" loading="lazy"
                                         class="w-[84px] h-[84px] object-cover rounded-md border border-border bg-surface">
                                    <button type="button" class="btn btn-xs absolute -top-1 -right-1"
                                            wire:click="dropImage(@js($img))" title="Убрать из публикации">×</button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if($pub->review)
                    @php $issues = is_array($pub->review->issues) ? $pub->review->issues : []; @endphp
                    <div class="mt-2 p-2 rounded-md border border-border-subtle">
                        <div class="text-[11.5px] text-fg-3 mb-1">
                            Проверка по медиапрофилю от {{ $pub->review->created_at?->format('d.m.Y H:i') }}:
                            {{ count($issues) === 0 ? 'замечаний нет' : 'замечаний '.count($issues) }}
                        </div>
                        @foreach($issues as $iss)
                            <div class="text-[12px] text-fg-2 mb-1">
                                <b>{{ $iss['problem'] ?? '' }}</b>
                                @if(! empty($iss['quote']))<span class="italic text-fg-3"> — «{{ $iss['quote'] }}»</span>@endif
                                @if(! empty($iss['fix']))<div class="text-[11.5px] text-fg-3">→ {{ $iss['fix'] }}</div>@endif
                            </div>
                        @endforeach
                        @if($pub->review->rewritten_text)
                            <button type="button" class="btn btn-xs" wire:click="acceptRewrite">взять правку в материал</button>
                        @endif
                    </div>
                @endif

                {{-- Тот же ответ, но рядом с кнопками: до верхней плашки отсюда не докрутить. --}}
                @if($error)
                    <div class="mt-2 text-[12.5px] text-amber-800">{{ $error }}</div>
                @elseif($flash)
                    <div class="mt-2 text-[12.5px] text-emerald-700">{{ $flash }}</div>
                @endif

                @if($pub->channel?->isPostable() && ! $pub->channel->isConnected())
                    <div class="mt-2 text-[12.5px] text-amber-800">
                        У канала «{{ $pub->channel->name }}» не заполнен доступ — публиковать нечем.
                        Кнопка «доступ» в списке каналов выше.
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-2 mt-2">
                    <input type="text" wire:model="pubUrl" placeholder="Ссылка на публикацию"
                           class="flex-1 min-w-[220px] h-[30px] px-2 border border-border rounded-md bg-surface text-[12px]">
                    <button type="button" class="btn btn-xs" wire:click="savePublication">сохранить</button>
                    <button type="button" class="btn btn-xs" wire:click="checkPublication"
                            wire:loading.attr="disabled" wire:target="checkPublication">
                        <span wire:loading.remove wire:target="checkPublication">проверить по профилю</span>
                        <span wire:loading wire:target="checkPublication">проверяю…</span>
                    </button>
                    <button type="button" class="btn btn-xs" wire:click="setStatus({{ $pub->id }}, 'approved')">согласовано</button>
                    @if($pub->channel?->isPostable())
                        <button type="button" class="btn btn-xs btn-primary" wire:click="publishNow({{ $pub->id }})"
                                wire:loading.attr="disabled" wire:target="publishNow({{ $pub->id }})"
                                wire:confirm="Разместить материал в «{{ $pub->channel->name }}» прямо сейчас? Отменить публикацию из системы нельзя."
                                @disabled(! $pub->channel->isConnected())
                                title="{{ $pub->channel->isConnected()
                                    ? 'Отправить в канал через API'
                                    : 'У канала не заполнен доступ' }}">
                            <span wire:loading.remove wire:target="publishNow({{ $pub->id }})">опубликовать в {{ $pub->channel->kindLabel() }}</span>
                            <span wire:loading wire:target="publishNow({{ $pub->id }})">публикую…</span>
                        </button>
                    @endif
                    <button type="button" class="btn btn-xs" wire:click="setStatus({{ $pub->id }}, 'published')"
                            title="Отметить, что материал размещён вручную — ссылку впишите слева">отметить опубликованным</button>
                    <button type="button" class="btn btn-xs" wire:click="setStatus({{ $pub->id }}, 'rejected')">отклонить</button>
                </div>
            </div>
        @endif

        <div class="ds-card-body">
            @forelse($this->publications as $p)
                <div class="flex flex-wrap items-baseline gap-2 py-1.5 border-b border-border-subtle last:border-b-0"
                     wire:key="pub-{{ $p->id }}">
                    <span class="chip text-[10px]"
                          style="{{ $p->isPublished()
                            ? 'background:var(--emerald-50);color:var(--emerald-700)'
                            : 'background:var(--neutral-100);color:var(--fg-3)' }}">{{ $p->statusLabel() }}</span>
                    <b class="text-[12.5px] text-fg-1">{{ \Illuminate\Support\Str::limit($p->title ?: ($p->topic?->title ?? 'Без заголовка'), 70) }}</b>
                    @if($p->channel)<span class="text-[11.5px] text-fg-3">{{ $p->channel->name }}</span>@endif
                    @if($p->planned_for)<span class="text-[11.5px] text-fg-3">на {{ $p->planned_for->format('d.m') }}</span>@endif
                    @if($p->url)<a href="{{ $p->url }}" target="_blank" rel="noopener" class="text-[11.5px] underline">ссылка</a>@endif
                    <span class="flex-1"></span>
                    <button type="button" class="btn btn-xs" wire:click="openPublication({{ $p->id }})">открыть</button>
                    <button type="button" class="btn btn-xs" wire:click="deletePublication({{ $p->id }})"
                            wire:confirm="Удалить материал?">×</button>
                </div>
            @empty
                <p class="text-[12.5px] text-fg-3">
                    {{ $showArchive ? 'Материалов пока нет.' : 'Ничего в работе — всё либо опубликовано, либо ещё не начато.' }}
                </p>
            @endforelse
        </div>
    </div>

    <div class="ds-card">
        <div class="ds-card-body text-[11.5px] text-fg-4">
            Раз в сутки в {{ $runAt }} система пишет черновики темам, которым пора, и публикует их в каналы с
            включённой автопубликацией; остальные ждут вашей кнопки. Публиковать через API умеем во
            ВКонтакте, Telegram и MAX. У Дзена своего API публикаций нет, но его канал привязывается к
            телеграм-каналу и забирает посты сам: отметьте Дзен как «повторяет Telegram» — материал будет
            писаться один, а публикация запишется на обе площадки.
        </div>
    </div>
</div>
