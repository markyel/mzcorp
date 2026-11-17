{{-- Опрос «Фото для календаря» — организатору. Фото грузятся по одному ($wire.upload в цикле, с прогрессом);
     копии для галереи делает очередь — пока готовятся, страница опрашивает сервер. --}}
@php
    $tz = config('app.timezone');
    $tabs = ['photos' => 'Фото ('.$photos->count().')', 'voters' => 'Участники ('.$voters->count().')', 'results' => 'Итоги', 'settings' => 'Настройки'];
    $voted = $voters->whereNotNull('voted_at')->count();
    $notInvited = $voters->whereNull('invited_at')->count();
    $toRemind = $voters->whereNotNull('invited_at')->whereNull('voted_at')->count();
@endphp
<div @if($processing) wire:poll.4s @endif style="display: flex; flex-direction: column; gap: 12px;">
    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
        <h1 style="font-size: 18px; font-weight: 600; margin: 0 8px 0 0;">Фото для календаря</h1>
        @foreach($this->polls as $p)
            <button type="button" wire:click="selectPoll({{ $p->id }})" class="chip {{ $poll?->id === $p->id ? 'chip-info' : 'chip-neutral' }}" wire:key="poll-{{ $p->id }}">{{ $p->title }}</button>
        @endforeach
        <button type="button" wire:click="newPoll" class="btn btn-sm">+ Новый опрос</button>
    </div>

    @if($note)<div class="chip chip-ok" style="align-self: flex-start;">{{ $note }}</div>@endif

    @if($poll)
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px;">
            <span class="chip {{ $poll->acceptsVotes() ? 'chip-ok' : 'chip-neutral' }}">{{ $poll->statusLabel() }}</span>
            <span>Каждый выбирает {{ $poll->picks }}, в итог — {{ $poll->winners }}</span>
            @if($poll->closes_at)<span>· до {{ $poll->closes_at->timezone($tz)->format('d.m.Y H:i') }}</span>@endif
            <span>· выбрали {{ $voted }} из {{ $voters->count() }}</span>
            <span style="flex: 1"></span>
            <a href="{{ route('calendar-poll.show', $poll) }}" target="_blank" rel="noopener" class="btn btn-sm">Страница голосования</a>
            @if($poll->status !== \App\Models\CalendarPoll::STATUS_OPEN)
                <button type="button" wire:click="setStatus('open')" class="btn btn-sm btn-primary">Открыть голосование</button>
            @else
                <button type="button" wire:click="setStatus('closed')" class="btn btn-sm">Завершить</button>
            @endif
        </div>

        <div style="display: flex; gap: 4px; border-bottom: 1px solid var(--border-subtle);">
            @foreach($tabs as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" class="btn btn-sm"
                        style="border-radius: 6px 6px 0 0; {{ $tab === $key ? 'background: var(--bg-surface); border-bottom-color: transparent; font-weight: 600;' : 'border-color: transparent;' }}">{{ $label }}</button>
            @endforeach
        </div>
    @endif

    {{-- ───── Фото ───── --}}
    @if($poll && $tab === 'photos')
        <div x-data="{
                total: 0, done: 0, pct: 0, errors: [],
                async uploadAll(files) {
                    this.total = files.length; this.done = 0; this.errors = [];
                    for (const f of files) {
                        await new Promise(res => this.$wire.upload('one', f,
                            () => { this.done++; res() },
                            () => { this.errors.push(f.name); this.done++; res() },
                            e => { this.pct = e.detail.progress }));
                    }
                },
            }">
            <label class="btn" style="cursor: pointer;">
                Загрузить фото
                <input type="file" accept="image/*" multiple class="hidden" style="display: none;" x-on:change="uploadAll([...$event.target.files]); $event.target.value = ''">
            </label>
            <span x-show="total > 0 && done < total" style="font-size: 12.5px; margin-left: 8px;">загружаем <span x-text="done + 1"></span> из <span x-text="total"></span> · <span x-text="pct"></span>%</span>
            <span x-show="total > 0 && done === total" style="font-size: 12.5px; margin-left: 8px; color: var(--status-ok-fg);">загружено <span x-text="total - errors.length"></span></span>
            <template x-if="errors.length"><div style="font-size: 12.5px; color: var(--status-overdue-fg); margin-top: 4px;">Не загрузились: <span x-text="errors.join(', ')"></span></div></template>
            @error('one')<div style="font-size: 12.5px; color: var(--status-overdue-fg); margin-top: 4px;">{{ $message }}</div>@enderror
            <div style="font-size: 12px; color: var(--fg-3); margin-top: 4px;">JPG, PNG или WebP до 29 МБ; можно выбрать сразу все — уйдут по одному. Оригиналы хранятся как есть.</div>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px;">
            @foreach($photos as $photo)
                <div wire:key="ph-{{ $photo->id }}" style="background: var(--bg-surface); border: 1px solid var(--border-subtle); border-radius: 8px; overflow: hidden;">
                    @if($photo->isReady())
                        <img src="{{ route('calendar-poll.photo', [$poll, $photo, 'thumb']) }}" alt="" loading="lazy" style="width: 100%; aspect-ratio: 4 / 3; object-fit: cover; display: block; cursor: zoom-in;"
                             x-on:click="$dispatch('open-image', { src: @js(route('calendar-poll.photo', [$poll, $photo, 'preview'])), name: @js($photo->original_name), dl: @js(route('calendar-poll.photo', [$poll, $photo, 'original'])) })">
                    @else
                        <div style="aspect-ratio: 4 / 3; display: flex; align-items: center; justify-content: center; padding: 8px; text-align: center; font-size: 12px; color: {{ $photo->error ? 'var(--status-overdue-fg)' : 'var(--fg-3)' }};">{{ $photo->error ?? 'готовим копию…' }}</div>
                    @endif
                    <div style="display: flex; align-items: center; gap: 6px; padding: 4px 6px; font-size: 11.5px;">
                        <span class="truncate" style="flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $photo->original_name }}">{{ $photo->original_name }}</span>
                        @if($photo->votes_count)<span class="chip chip-info" title="Сколько участников выбрали">{{ $photo->votes_count }}</span>@endif
                        <button type="button" wire:click="deletePhoto({{ $photo->id }})" title="Удалить фото"
                                @if($photo->votes_count) wire:confirm="Фото уже выбрали {{ $photo->votes_count }} чел. — удалить вместе с их выбором?" @endif
                                style="background: none; border: 0; cursor: pointer; color: var(--fg-3);">✕</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ───── Участники ───── --}}
    @if($poll && $tab === 'voters')
        <div style="display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 2fr); gap: 16px; align-items: start;">
            <div style="display: flex; flex-direction: column; gap: 6px;">
                <label style="font-size: 13px; font-weight: 600;">Добавить по списку</label>
                <textarea wire:model="votersText" rows="10" placeholder="Иван Петров; ivan.petrov@example.ru&#10;Анна Смирнова <anna@example.ru>&#10;…" style="width: 100%; font-size: 13px; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px;"></textarea>
                <div style="font-size: 12px; color: var(--fg-3);">Строка — один человек: имя и адрес в любом порядке, через «;», запятую или табуляцию (можно скопировать из Excel). Сотрудники MyLift с тем же адресом войдут и без ссылки.</div>
                <button type="button" wire:click="addVoters" class="btn btn-sm" style="align-self: flex-start;">Добавить</button>
            </div>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                    <button type="button" wire:click="sendInvites('new')" class="btn btn-sm btn-primary" @disabled($notInvited === 0)>Разослать приглашения · {{ $notInvited }}</button>
                    <button type="button" wire:click="sendInvites('remind')" class="btn btn-sm" @disabled($toRemind === 0)>Напомнить не выбравшим · {{ $toRemind }}</button>
                    <span style="font-size: 12px; color: var(--fg-3);">с mail@myzip.ru, личная ссылка каждому; напоминание — с новой ссылкой</span>
                </div>
                @if($issuedLink)
                    <div style="padding: 8px 10px; border: 1px solid var(--border-subtle); border-radius: 6px; font-size: 12.5px; background: var(--bg-surface-2);">
                        Ссылка для {{ $issuedLink['name'] }} (прежняя перестала работать):
                        <input type="text" readonly value="{{ $issuedLink['url'] }}" x-on:focus="$el.select()" style="width: 100%; margin-top: 4px; font-size: 12px; padding: 4px 6px; border: 1px solid var(--border); border-radius: 4px;">
                    </div>
                @endif
                <table style="width: 100%; border-collapse: collapse; font-size: 12.5px;">
                    <thead><tr style="text-align: left; color: var(--fg-3);"><th style="padding: 4px;">Имя</th><th style="padding: 4px;">E-mail</th><th style="padding: 4px;">Статус</th><th style="padding: 4px;">Выбрал</th><th></th></tr></thead>
                    <tbody>
                    @forelse($voters as $v)
                        <tr wire:key="v-{{ $v->id }}" style="border-top: 1px solid var(--border-subtle);">
                            <td style="padding: 4px;">{{ $v->name }}@if($v->user_id) <span class="chip chip-neutral" title="Сотрудник MyLift — войдёт и из системы">MyLift</span>@endif</td>
                            <td style="padding: 4px;">{{ $v->email }}</td>
                            <td style="padding: 4px;">{{ $v->statusLabel() }}@if($v->invited_at)<span style="color: var(--fg-3);"> · письмо {{ $v->invited_at->timezone($tz)->format('d.m H:i') }}</span>@endif</td>
                            <td style="padding: 4px;">{{ $v->voted_at?->timezone($tz)->format('d.m H:i') ?? '—' }}</td>
                            <td style="padding: 4px; white-space: nowrap; text-align: right;">
                                <button type="button" wire:click="inviteOne({{ $v->id }})" class="btn btn-sm" title="Отправить письмо с новой ссылкой">письмо</button>
                                <button type="button" wire:click="issueLink({{ $v->id }})" class="btn btn-sm" title="Показать новую ссылку — переслать самому">ссылка</button>
                                <button type="button" wire:click="removeVoter({{ $v->id }})" wire:confirm="Удалить {{ $v->name }} из опроса вместе с выбором?" style="background: none; border: 0; cursor: pointer; color: var(--fg-3);" title="Удалить">✕</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" style="padding: 8px 4px; color: var(--fg-3);">Участников пока нет — вставьте список слева.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ───── Итоги ───── --}}
    @if($poll && $tab === 'results')
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-size: 13px;">
            <span>Выбрали {{ $voted }} из {{ $voters->count() }}. Баллы: 1-е место — {{ $poll->picks }}, {{ $poll->picks }}-е — 1.</span>
            @if($ranking->where('votes', '>', 0)->isNotEmpty())
                <a href="{{ route('calendar-poll.winners', $poll) }}" class="btn btn-sm">Скачать оригиналы топ-{{ $poll->winners }} (zip)</a>
            @endif
        </div>
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead><tr style="text-align: left; color: var(--fg-3);"><th style="padding: 4px;">Место</th><th style="padding: 4px;">Фото</th><th style="padding: 4px;">Баллы</th><th style="padding: 4px;">Голосов</th><th style="padding: 4px;">1-х мест</th><th style="padding: 4px;">Файл</th></tr></thead>
            <tbody>
            @foreach($ranking as $row)
                @php $win = $row['place'] <= $poll->winners && $row['votes'] > 0; @endphp
                <tr wire:key="r-{{ $row['id'] }}" style="border-top: 1px solid var(--border-subtle); {{ $win ? 'background: var(--status-ok-bg);' : '' }}">
                    <td style="padding: 4px; font-weight: {{ $win ? 600 : 400 }};">{{ $row['place'] }}</td>
                    <td style="padding: 4px;">
                        @if($row['photo']->isReady())
                            <img src="{{ route('calendar-poll.photo', [$poll, $row['photo'], 'thumb']) }}" alt="" loading="lazy" style="height: 56px; border-radius: 4px; cursor: zoom-in;"
                                 x-on:click="$dispatch('open-image', { src: @js(route('calendar-poll.photo', [$poll, $row['photo'], 'preview'])), name: @js($row['photo']->original_name), dl: @js(route('calendar-poll.photo', [$poll, $row['photo'], 'original'])) })">
                        @endif
                    </td>
                    <td style="padding: 4px; font-weight: 600;">{{ $row['points'] }}</td>
                    <td style="padding: 4px;">{{ $row['votes'] }}</td>
                    <td style="padding: 4px;">{{ $row['firsts'] }}</td>
                    <td style="padding: 4px; color: var(--fg-3);">{{ $row['photo']->original_name }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    {{-- ───── Настройки (и новый опрос) ───── --}}
    @if($tab === 'settings' || ! $poll)
        <div style="display: flex; flex-direction: column; gap: 8px; max-width: 560px; font-size: 13px;">
            <label>Название <input type="text" wire:model="title" style="width: 100%; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px;"></label>
            @error('title')<span style="color: var(--status-overdue-fg)">{{ $message }}</span>@enderror
            <label>Пояснение участникам (необязательно)
                <textarea wire:model="description" rows="3" style="width: 100%; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px;"></textarea></label>
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <label>Голосование до <input type="datetime-local" wire:model="closesAt" style="display: block; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px;"></label>
                <label>Каждый выбирает <input type="number" min="1" max="20" wire:model="picks" style="display: block; width: 90px; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px;"></label>
                <label>В итог берём <input type="number" min="1" max="100" wire:model="winners" style="display: block; width: 90px; padding: 6px 8px; border: 1px solid var(--border); border-radius: 6px;"></label>
            </div>
            @error('picks')<span style="color: var(--status-overdue-fg)">{{ $message }}</span>@enderror
            <button type="button" wire:click="saveSettings" class="btn btn-sm btn-primary" style="align-self: flex-start;">{{ $poll ? 'Сохранить' : 'Создать опрос' }}</button>
        </div>
    @endif
</div>
