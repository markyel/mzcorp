@php
    $inp = 'w-full h-[30px] px-2 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500';
    $area = 'w-full px-2 py-1.5 border border-border rounded-md bg-surface text-[12.5px] outline-none focus:border-sky-500';
    $rel = $this->openRelease;
    $statusChip = [
        'idea' => 'background:var(--neutral-100);color:var(--fg-3)',
        'draft' => 'background:var(--sky-50);color:var(--sky-700)',
        'ready' => 'background:var(--amber-100);color:var(--amber-800)',
        'sent' => 'background:var(--emerald-50);color:var(--emerald-700)',
        'archived' => 'background:var(--neutral-100);color:var(--fg-4)',
    ];
    $sendChip = [
        'queued' => 'background:var(--neutral-100);color:var(--fg-3)',
        'sent' => 'background:var(--sky-50);color:var(--sky-700)',
        'failed' => 'background:var(--amber-100);color:var(--amber-800)',
        'replied' => 'background:var(--emerald-50);color:var(--emerald-700)',
    ];
    $totals = $this->outletTotals;
@endphp

<div class="space-y-4">
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

    {{-- ─────────────── Инфоповоды и релизы ─────────────── --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">📰 Инфоповоды и релизы</h3>
            <span class="text-[12px] text-fg-3">инфоповод → текст → рассылка по редакциям → публикации</span>
            <span class="flex-1"></span>
            <label class="text-[12px] text-fg-3 flex items-center gap-1">
                <input type="checkbox" wire:model.live="showArchive"> архив
            </label>
            <button type="button" class="btn btn-sm btn-primary" wire:click="startRelease">Новый инфоповод</button>
        </div>

        @if($relForm)
            <div class="ds-card-body border-b border-border-subtle space-y-2">
                <div class="grid gap-2 md:grid-cols-[1fr_auto]">
                    <input type="text" wire:model="newTitle" placeholder="О чём новость — коротко" class="{{ $inp }}">
                    <input type="date" wire:model="newPlannedFor" class="{{ $inp }}" title="Когда разослать">
                </div>
                <textarea wire:model="newOccasion" rows="4" class="{{ $area }}"
                          placeholder="Факты: что произошло, когда, цифры, кто может прокомментировать. Релиз пишется только из этих фактов и медиапрофиля."></textarea>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-sm btn-primary" wire:click="createRelease">Добавить</button>
                    <button type="button" class="btn btn-sm" wire:click="cancelRelease">Отмена</button>
                </div>
            </div>
        @endif

        <div class="ds-card-body">
            @forelse($this->releases as $r)
                <div class="flex flex-wrap items-baseline gap-2 py-2 border-b border-border-subtle last:border-b-0 {{ $relId === $r->id ? 'bg-[var(--sky-50)] px-2 rounded' : '' }}"
                     wire:key="rel-{{ $r->id }}">
                    <span class="chip text-[10px]" style="{{ $statusChip[$r->status] ?? '' }}">{{ $r->statusLabel() }}</span>
                    <button type="button" class="text-[13px] font-medium text-fg-1 hover:underline text-left" wire:click="open({{ $r->id }})">{{ $r->title }}</button>
                    @if($r->planned_for)
                        <span class="text-[11.5px] text-fg-3 mono">{{ $r->planned_for->format('d.m.Y') }}</span>
                    @endif
                    <span class="flex-1"></span>
                    @if($r->sends_count)
                        <span class="text-[11.5px] text-fg-3">
                            писем {{ $r->sends_count }}
                            @if($r->replied_count) · <span class="text-emerald-700">ответов {{ $r->replied_count }}</span>@endif
                            @if($r->failed_count) · <span class="text-amber-800">не ушло {{ $r->failed_count }}</span>@endif
                        </span>
                    @endif
                    @if($r->mentions_count)
                        <span class="chip text-[10px]" style="background:var(--emerald-50);color:var(--emerald-700)">публикаций {{ $r->mentions_count }}</span>
                    @endif
                </div>
            @empty
                <p class="text-[12.5px] text-fg-3">
                    Инфоповодов пока нет. Добавьте первый: новое поступление на склад, бренд в наличии,
                    участие в выставке, изменение в работе — и факты к нему.
                </p>
            @endforelse
        </div>
    </div>

    {{-- ─────────────── Карточка релиза ─────────────── --}}
    @if($rel)
        @php $hasQueued = $rel->sends->contains('status', 'queued'); @endphp
        <div class="ds-card" wire:key="rel-card-{{ $rel->id }}" @if($hasQueued) wire:poll.15s="refreshSends" @endif>
            <div class="ds-card-header flex-wrap">
                <span class="chip text-[10px]" style="{{ $statusChip[$rel->status] ?? '' }}">{{ $rel->statusLabel() }}</span>
                <h3 class="text-[15px] font-semibold text-fg-1">{{ $rel->title }}</h3>
                <span class="flex-1"></span>
                <button type="button" class="btn btn-sm" wire:click="close">Закрыть</button>
            </div>

            <div class="ds-card-body space-y-3">
                <div class="grid gap-2 md:grid-cols-[1fr_auto]">
                    <div>
                        <div class="text-[11.5px] text-fg-3 mb-0.5">Заголовок</div>
                        <input type="text" wire:model="relTitle" class="{{ $inp }}">
                    </div>
                    <div>
                        <div class="text-[11.5px] text-fg-3 mb-0.5">Разослать</div>
                        <input type="date" wire:model="relPlannedFor" class="{{ $inp }}">
                    </div>
                </div>

                <div>
                    <div class="text-[11.5px] text-fg-3 mb-0.5">Инфоповод — факты для релиза</div>
                    <textarea wire:model="relOccasion" rows="4" class="{{ $area }}"
                              placeholder="Что произошло, когда, цифры, цитата руководителя (если есть)"></textarea>
                </div>

                @if($rel->status !== 'sent')
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="btn btn-sm" wire:click="write" wire:loading.attr="disabled" wire:target="write">
                            <span wire:loading.remove wire:target="write">✍ {{ trim((string) $rel->body) !== '' ? 'Переписать' : 'Написать' }} релиз по фактам</span>
                            <span wire:loading wire:target="write">Пишу… до минуты</span>
                        </button>
                        <span class="text-[11.5px] text-fg-4">пишет модель по инфоповоду и медиапрофилю; выдумывать факты и цитаты ей запрещено</span>
                    </div>
                @endif

                <div>
                    <div class="text-[11.5px] text-fg-3 mb-0.5">Тема письма редакции</div>
                    <input type="text" wire:model="relSubject" class="{{ $inp }}" placeholder="Пресс-релиз: …">
                </div>
                <div>
                    <div class="text-[11.5px] text-fg-3 mb-0.5">Сопроводительный текст (над релизом)</div>
                    <textarea wire:model="relCover" rows="3" class="{{ $area }}"></textarea>
                </div>
                <div>
                    <div class="text-[11.5px] text-fg-3 mb-0.5">Текст релиза · {{ mb_strlen($relBody) }} знаков</div>
                    <textarea wire:model.blur="relBody" rows="16" class="{{ $area }}"></textarea>
                    <div class="text-[11px] text-fg-4 mt-0.5">Подпись отправителя добавится к письму сама, рекламного блока в письмах редакциям нет.</div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-primary" wire:click="save">Сохранить</button>
                    @if(in_array($rel->status, ['idea', 'draft'], true))
                        <button type="button" class="btn btn-sm" wire:click="setStatus({{ $rel->id }}, 'ready')">Готов к рассылке</button>
                    @endif
                    @if($rel->status === 'ready')
                        <button type="button" class="btn btn-sm" wire:click="setStatus({{ $rel->id }}, 'draft')">Вернуть в черновик</button>
                    @endif
                    @if($rel->status !== 'archived')
                        <button type="button" class="btn btn-sm" wire:click="setStatus({{ $rel->id }}, 'archived')">В архив</button>
                    @else
                        <button type="button" class="btn btn-sm" wire:click="setStatus({{ $rel->id }}, '{{ $rel->sends->isNotEmpty() ? 'sent' : 'draft' }}')">Из архива</button>
                    @endif
                    @if($rel->sends->isEmpty())
                        <button type="button" class="btn btn-sm text-amber-800" wire:click="delete({{ $rel->id }})"
                                wire:confirm="Удалить инфоповод «{{ $rel->title }}»?">Удалить</button>
                    @endif
                </div>
            </div>

            {{-- Рассылка --}}
            <div class="ds-card-body border-t border-border-subtle space-y-2">
                <h4 class="text-[13.5px] font-semibold text-fg-1">✉ Рассылка</h4>
                @if(! $rel->isSendable())
                    <p class="text-[12.5px] text-fg-3">Нужны тема письма и текст релиза — тогда можно будет разослать.</p>
                @else
                    <div class="grid gap-2 md:grid-cols-[260px_1fr]">
                        <div>
                            <div class="text-[11.5px] text-fg-3 mb-0.5">Отправитель (ответы придут сюда)</div>
                            <select wire:model.live="sendMailbox" class="{{ $inp }}">
                                <option value="">— ящик —</option>
                                @foreach($this->mailboxes as $mb)
                                    <option value="{{ $mb->id }}">{{ $mb->email }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <div class="text-[11.5px] text-fg-3 mb-0.5">Пробное письмо себе</div>
                            <div class="flex gap-2">
                                <input type="email" wire:model="testEmail" class="{{ $inp }} max-w-[280px]">
                                <button type="button" class="btn btn-sm" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">Отправить пробу</button>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px]">
                        <span class="text-fg-3">Кому:</span>
                        @foreach(\App\Models\PressOutlet::PRIORITIES as $p => $label)
                            <label class="flex items-center gap-1">
                                <input type="checkbox" value="{{ $p }}" wire:model.live="sendPriorities">
                                <b>{{ $p }}</b> <span class="text-fg-3">{{ $label }} · {{ $totals[$p] ?? 0 }}</span>
                            </label>
                        @endforeach
                    </div>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px]">
                        <span class="text-fg-3">Категории:</span>
                        @foreach(\App\Models\PressOutlet::CATEGORIES as $cat)
                            <label class="flex items-center gap-1">
                                <input type="checkbox" value="{{ $cat }}" wire:model.live="sendCategories"> {{ $cat }}
                            </label>
                        @endforeach
                        <span class="text-[11.5px] text-fg-4">ничего не отмечено — все</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        @php
                            $n = $this->audienceCount;
                            $word = ($n % 10 === 1 && $n % 100 !== 11) ? 'редакция'
                                : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? 'редакции' : 'редакций');
                        @endphp
                        <button type="button" class="btn btn-sm btn-primary" wire:click="send"
                                @disabled($n === 0 || $sendMailbox === '')
                                wire:confirm="Разослать релиз в {{ $n }} редакций? Письма уйдут по одному, отозвать их будет нельзя.">
                            Разослать · {{ $n }} {{ $word }}
                        </button>
                        <span class="text-[11.5px] text-fg-4">каждой редакции — своё письмо с паузой между ними; кому релиз уже ушёл, повторно не отправится</span>
                    </div>
                @endif

                @if($rel->sends->isNotEmpty())
                    <div class="mt-2">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-[12px] text-fg-3">Письма · {{ $rel->sends->count() }}</span>
                            @if($rel->sends->contains('status', 'failed'))
                                <button type="button" class="btn btn-sm" wire:click="retryFailed">Повторить неушедшие</button>
                            @endif
                        </div>
                        <div class="divide-y divide-border-subtle">
                            @foreach($rel->sends as $s)
                                <div class="flex flex-wrap items-baseline gap-2 py-1 text-[12px]" wire:key="send-{{ $s->id }}">
                                    <span class="chip text-[10px]" style="{{ $sendChip[$s->status] ?? '' }}">{{ $s->statusLabel() }}</span>
                                    <span class="text-fg-1">{{ $s->outlet?->name }}</span>
                                    <span class="mono text-fg-3">{{ $s->email }}</span>
                                    @if($s->sent_at)<span class="text-fg-4">{{ $s->sent_at->format('d.m H:i') }}</span>@endif
                                    @if($s->error)<span class="text-amber-800">{{ $s->error }}</span>@endif
                                    @if($s->reply)
                                        <span class="text-emerald-700">
                                            ↩ {{ $s->reply->from_name ?: $s->reply->from_email }}: «{{ \Illuminate\Support\Str::limit((string) $s->reply->subject, 70) }}»
                                            {{ $s->replied_at?->format('d.m H:i') }}
                                        </span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <p class="text-[11px] text-fg-4 mt-1">Ответы редакций лежат во входящих ящика-отправителя и не превращаются в заявки.</p>
                    </div>
                @endif
            </div>

            {{-- Публикации релиза --}}
            <div class="ds-card-body border-t border-border-subtle">
                <div class="flex items-center gap-2 mb-1">
                    <h4 class="text-[13.5px] font-semibold text-fg-1">🗞 Вышло по релизу · {{ $rel->mentions->count() }}</h4>
                    <button type="button" class="btn btn-sm" wire:click="startMention({{ $rel->id }})">Добавить публикацию</button>
                </div>
                @foreach($rel->mentions as $m)
                    <div class="text-[12px] py-0.5" wire:key="rm-{{ $m->id }}">
                        <span class="mono text-fg-3">{{ $m->published_on?->format('d.m.Y') }}</span>
                        <span class="text-fg-2">{{ $m->outlet?->name }}</span>
                        <a href="{{ $m->url }}" target="_blank" rel="noopener" class="text-sky-700 hover:underline">{{ $m->title ?: $m->url }}</a>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ─────────────── Публикации ─────────────── --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🗞 Публикации в СМИ</h3>
            <span class="text-[12px] text-fg-3">что вышло о нас — по релизам и само</span>
            <span class="flex-1"></span>
            <button type="button" class="btn btn-sm btn-primary" wire:click="startMention">Добавить публикацию</button>
        </div>

        @if($menForm)
            <div class="ds-card-body border-b border-border-subtle space-y-2">
                <div class="grid gap-2 md:grid-cols-[1fr_auto]">
                    <input type="url" wire:model="menUrl" placeholder="Ссылка на публикацию" class="{{ $inp }}">
                    <input type="date" wire:model="menDate" class="{{ $inp }}">
                </div>
                <input type="text" wire:model="menTitle" placeholder="Заголовок публикации" class="{{ $inp }}">
                <div class="grid gap-2 md:grid-cols-2">
                    <select wire:model="menOutlet" class="{{ $inp }}">
                        <option value="">— площадка (узнаем по ссылке) —</option>
                        @foreach($this->outlets->where('is_active', true) as $o)
                            <option value="{{ $o->id }}">{{ $o->name }}</option>
                        @endforeach
                    </select>
                    <select wire:model="menRelease" class="{{ $inp }}">
                        <option value="">— без релиза —</option>
                        @foreach($this->releases as $r)
                            <option value="{{ $r->id }}">{{ \Illuminate\Support\Str::limit($r->title, 80) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-sm btn-primary" wire:click="saveMention">Добавить</button>
                    <button type="button" class="btn btn-sm" wire:click="cancelMention">Отмена</button>
                </div>
            </div>
        @endif

        <div class="ds-card-body">
            @forelse($this->mentions as $m)
                <div class="flex flex-wrap items-baseline gap-2 py-1.5 border-b border-border-subtle last:border-b-0 text-[12.5px]" wire:key="men-{{ $m->id }}">
                    <span class="mono text-fg-3">{{ $m->published_on?->format('d.m.Y') ?? '—' }}</span>
                    <span class="text-fg-2">{{ $m->outlet?->name ?? parse_url($m->url, PHP_URL_HOST) }}</span>
                    <a href="{{ $m->url }}" target="_blank" rel="noopener" class="text-sky-700 hover:underline">{{ $m->title ?: $m->url }}</a>
                    @if($m->release)<span class="text-[11.5px] text-fg-4">по релизу «{{ \Illuminate\Support\Str::limit($m->release->title, 50) }}»</span>@endif
                    <span class="flex-1"></span>
                    <button type="button" class="text-[11.5px] text-fg-4 hover:text-red-700" wire:click="deleteMention({{ $m->id }})"
                            wire:confirm="Убрать публикацию из списка?">убрать</button>
                </div>
            @empty
                <p class="text-[12.5px] text-fg-3">Публикаций пока нет. Вышла заметка — добавьте ссылку, и площадка получит отметку «публикует нас».</p>
            @endforelse
        </div>
    </div>

    {{-- ─────────────── База СМИ ─────────────── --}}
    <div class="ds-card">
        <div class="ds-card-header flex-wrap">
            <h3 class="text-[15px] font-semibold text-fg-1">🏛 База СМИ</h3>
            <span class="text-[12px] text-fg-3">
                активных: A {{ $totals['A'] ?? 0 }} · B {{ $totals['B'] ?? 0 }} · C {{ $totals['C'] ?? 0 }}
            </span>
            <span class="flex-1"></span>
            <button type="button" class="btn btn-sm btn-primary" wire:click="startOutlet">Добавить площадку</button>
        </div>

        @if($outForm)
            <div class="ds-card-body border-b border-border-subtle space-y-2">
                <div class="grid gap-2 md:grid-cols-2">
                    <input type="text" wire:model="outName" placeholder="Название" class="{{ $inp }}">
                    <input type="url" wire:model="outUrl" placeholder="Сайт https://…" class="{{ $inp }}">
                </div>
                <div class="grid gap-2 md:grid-cols-2">
                    <select wire:model="outCat" class="{{ $inp }}">
                        @foreach(\App\Models\PressOutlet::CATEGORIES as $cat)
                            <option value="{{ $cat }}">{{ $cat }}</option>
                        @endforeach
                    </select>
                    <select wire:model="outPri" class="{{ $inp }}">
                        @foreach(\App\Models\PressOutlet::PRIORITIES as $p => $label)
                            <option value="{{ $p }}">{{ $p }} — {{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <input type="text" wire:model="outEmails" placeholder="Адреса редакции через запятую — первый основной" class="{{ $inp }}">
                <textarea wire:model="outNotes" rows="2" class="{{ $area }}" placeholder="Заметки: условия, требования к релизу, кому писать"></textarea>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-sm btn-primary" wire:click="saveOutlet">Сохранить</button>
                    <button type="button" class="btn btn-sm" wire:click="cancelOutlet">Отмена</button>
                </div>
            </div>
        @endif

        <div class="ds-card-body border-b border-border-subtle">
            <div class="grid gap-2 md:grid-cols-3">
                <input type="text" wire:model.live.debounce.400ms="outSearch" placeholder="Поиск: название, сайт, адрес" class="{{ $inp }}">
                <select wire:model.live="outCategory" class="{{ $inp }}">
                    <option value="">Все категории</option>
                    @foreach(\App\Models\PressOutlet::CATEGORIES as $cat)
                        <option value="{{ $cat }}">{{ $cat }}</option>
                    @endforeach
                </select>
                <select wire:model.live="outPriority" class="{{ $inp }}">
                    <option value="">Все приоритеты</option>
                    @foreach(\App\Models\PressOutlet::PRIORITIES as $p => $label)
                        <option value="{{ $p }}">{{ $p }} — {{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="ds-card-body">
            @forelse($this->outlets as $o)
                <div class="flex flex-wrap gap-x-3 gap-y-0.5 py-2 border-b border-border-subtle last:border-b-0 {{ $o->is_active ? '' : 'opacity-50' }}"
                     wire:key="out-{{ $o->id }}">
                    <div style="width:52px;flex-shrink:0">
                        <select class="h-[26px] px-1 border border-border rounded-md bg-surface text-[12px] font-semibold"
                                wire:change="setPriority({{ $o->id }}, $event.target.value)" title="Приоритет рассылки">
                            @foreach(array_keys(\App\Models\PressOutlet::PRIORITIES) as $p)
                                <option value="{{ $p }}" @selected($o->priority === $p)>{{ $p }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <b class="text-[12.5px] text-fg-1">{{ $o->name }}</b>
                            <a href="{{ $o->url }}" target="_blank" rel="noopener" class="text-[11.5px] text-sky-700 hover:underline">{{ $o->host() }}</a>
                            <span class="text-[11px] text-fg-4">{{ $o->category }}</span>
                            @if($o->terms && ! str_starts_with($o->terms, 'неизвестно'))
                                <span class="chip text-[10px]" style="{{ str_starts_with($o->terms, 'платно') ? 'background:var(--amber-50);color:var(--amber-800)' : 'background:var(--emerald-50);color:var(--emerald-700)' }}"
                                      title="{{ $o->terms }}">{{ str_starts_with($o->terms, 'платно') ? 'платно' : 'бесплатно' }}</span>
                            @endif
                            @if($o->mentions_count)
                                <span class="chip text-[10px]" style="background:var(--emerald-50);color:var(--emerald-700)">публикует нас · {{ $o->mentions_count }}</span>
                            @endif
                        </div>
                        <div class="text-[11.5px] mono text-fg-2 break-all">
                            {{ $o->emails ? implode(', ', $o->emails) : '' }}
                            @if(! $o->emails && $o->other_contact)<span class="text-fg-3 font-sans">{{ \Illuminate\Support\Str::limit($o->other_contact, 160) }}</span>@endif
                        </div>
                        @if($o->notes)
                            <details class="text-[11.5px] text-fg-3">
                                <summary class="cursor-pointer text-fg-4">заметки</summary>
                                <div class="whitespace-pre-line">{{ $o->notes }}</div>
                                @if($o->email_source)<div class="text-fg-4 break-all">адрес взят: {{ $o->email_source }}</div>@endif
                            </details>
                        @endif
                    </div>
                    <div class="flex items-start gap-2 text-[11.5px] text-fg-3 whitespace-nowrap">
                        @if($o->sends_count)
                            <span title="Релизов отправлено / ответили / публикаций">{{ $o->sends_count }} / {{ $o->replied_count }} / {{ $o->mentions_count }}</span>
                        @endif
                        <button type="button" class="hover:text-fg-1" wire:click="editOutlet({{ $o->id }})">изменить</button>
                        <button type="button" class="hover:text-fg-1" wire:click="toggleOutlet({{ $o->id }})">{{ $o->is_active ? 'отключить' : 'включить' }}</button>
                    </div>
                </div>
            @empty
                <p class="text-[12.5px] text-fg-3">
                    База пуста. Загрузить таблицу: <code class="mono">php artisan press:import-outlets путь/к/press_media.xlsx</code>
                </p>
            @endforelse
        </div>
    </div>
</div>
