{{-- Голосование «Фото для календаря». Выбор и порядок мест — в браузере (Alpine), сохранение — $wire.save(chosen).
     Клик по фото — просмотр (общий лайтбокс, листание). Ожидает $poll, $voter, $items, $chosen. --}}
@php
    $open = $poll->acceptsVotes();
    $canVote = $open && $voter !== null;
    $tz = config('app.timezone');
@endphp
<div x-data="{
        items: @js($items),
        chosen: @js($chosen),
        picks: {{ (int) $poll->picks }},
        canVote: @js($canVote),
        dirty: false,
        photo(id) { return this.items.find(i => i.id === id) },
        rankOf(id) { return this.chosen.indexOf(id) + 1 },
        toggle(id) {
            if (! this.canVote) return;
            const i = this.chosen.indexOf(id);
            if (i >= 0) { this.chosen.splice(i, 1) } else if (this.chosen.length < this.picks) { this.chosen.push(id) } else { return }
            this.dirty = true;
        },
        move(i, d) {
            const j = i + d;
            if (j < 0 || j >= this.chosen.length) return;
            [this.chosen[i], this.chosen[j]] = [this.chosen[j], this.chosen[i]];
            this.dirty = true;
        },
        view(index) {
            this.$dispatch('open-image', { items: this.items.map(i => ({ src: i.src, name: i.name, dl: i.src })), index });
        },
        async save() { await this.$wire.save(this.chosen); this.dirty = false; },
    }">
    <div style="margin-bottom: 12px; font-size: 14px; line-height: 1.5; color: var(--fg-2);">
        @if($voter)<div style="color: var(--fg-1)">{{ $voter->name }}, выберите <b>{{ $poll->picks }}</b> фото и расставьте их по местам: 1-е — самое понравившееся.</div>@endif
        @if(filled($poll->description))<div style="white-space: pre-line; margin-top: 4px;">{{ $poll->description }}</div>@endif
        <div style="margin-top: 4px; font-size: 12.5px;">
            Нажмите на фото, чтобы рассмотреть его крупно.
            @if($poll->closes_at) Голосование до {{ $poll->closes_at->timezone($tz)->format('d.m.Y H:i') }}.@endif
        </div>
        @if(! $open)
            <div class="chip chip-attn" style="margin-top: 8px;">Голосование {{ $poll->status === \App\Models\CalendarPoll::STATUS_DRAFT ? 'ещё не открыто' : 'закончилось' }}</div>
        @elseif($voter === null)
            <div class="chip chip-info" style="margin-top: 8px;">Вы смотрите как организатор — голосуют участники из списка.</div>
        @endif
    </div>

    {{-- Выбор по местам: прилипает к верху при прокрутке галереи. --}}
    <div style="position: sticky; top: 0; z-index: 20; background: var(--bg-surface, #fff); border: 1px solid var(--border-subtle); border-radius: 10px; padding: 10px 12px; margin-bottom: 14px; box-shadow: 0 2px 8px rgba(0,0,0,.06);">
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <span style="font-size: 13px; font-weight: 600;">Ваш выбор: <span x-text="chosen.length"></span> из {{ $poll->picks }}</span>
            <span style="flex: 1"></span>
            @if($flash)<span x-show="! dirty" class="chip chip-ok">{{ $flash }}</span>@endif
            @if($error)<span class="chip chip-over">{{ $error }}</span>@endif
            <span x-show="dirty && chosen.length === picks" style="font-size: 12px; color: var(--fg-3);">не сохранено</span>
            <button type="button" class="btn btn-primary" x-on:click="save()" x-bind:disabled="! canVote || chosen.length !== picks"
                    wire:loading.attr="disabled" wire:target="save">Сохранить выбор</button>
        </div>
        <div style="display: grid; grid-template-columns: repeat({{ (int) $poll->picks }}, minmax(0, 1fr)); gap: 8px; margin-top: 8px;">
            @for($slot = 0; $slot < $poll->picks; $slot++)
                <div style="position: relative; aspect-ratio: 4 / 3; border-radius: 8px; overflow: hidden; border: 1px dashed var(--border); background: var(--bg-surface-2, #f3f4f6);">
                    <template x-if="chosen[{{ $slot }}]">
                        <div style="position: absolute; inset: 0;">
                            <img x-bind:src="photo(chosen[{{ $slot }}])?.thumb" alt="" style="width: 100%; height: 100%; object-fit: cover; display: block;">
                            <div style="position: absolute; left: 0; right: 0; bottom: 0; display: flex; justify-content: space-between; align-items: center; padding: 2px 4px; background: rgba(0,0,0,.55); color: #fff; font-size: 12px;">
                                <button type="button" x-on:click="move({{ $slot }}, -1)" x-show="canVote && {{ $slot }} > 0" title="Выше" style="color: #fff; background: none; border: 0; cursor: pointer;">◀</button>
                                <b>{{ $slot + 1 }}</b>
                                <span>
                                    <button type="button" x-on:click="move({{ $slot }}, 1)" x-show="canVote && {{ $slot }} < chosen.length - 1" title="Ниже" style="color: #fff; background: none; border: 0; cursor: pointer;">▶</button>
                                    <button type="button" x-on:click="toggle(chosen[{{ $slot }}])" x-show="canVote" title="Убрать" style="color: #fff; background: none; border: 0; cursor: pointer;">✕</button>
                                </span>
                            </div>
                        </div>
                    </template>
                    <template x-if="! chosen[{{ $slot }}]">
                        <div style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 18px; color: var(--fg-4, #9ca3af);">{{ $slot + 1 }}</div>
                    </template>
                </div>
            @endfor
        </div>
    </div>

    @if($items === [])
        <div style="font-size: 14px; color: var(--fg-3);">Фото ещё не загружены.</div>
    @endif
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px;">
        <template x-for="(it, index) in items" :key="it.id">
            <div style="background: var(--bg-surface, #fff); border-radius: 10px; overflow: hidden; border: 2px solid transparent;"
                 x-bind:style="{ borderColor: rankOf(it.id) ? 'var(--accent, #D32027)' : 'var(--border-subtle)' }">
                <img x-bind:src="it.thumb" x-bind:alt="it.name" loading="lazy" x-on:click="view(index)"
                     style="width: 100%; aspect-ratio: 4 / 3; object-fit: cover; display: block; cursor: zoom-in;">
                <div style="display: flex; align-items: center; gap: 8px; padding: 6px 8px;">
                    <span style="font-size: 12px; color: var(--fg-3);" x-text="it.name"></span>
                    <span style="flex: 1"></span>
                    <template x-if="rankOf(it.id)">
                        <button type="button" class="btn btn-sm" x-on:click="toggle(it.id)" x-bind:disabled="! canVote">
                            <b x-text="rankOf(it.id) + '-е место'"></b>&nbsp;· убрать
                        </button>
                    </template>
                    <template x-if="! rankOf(it.id)">
                        <button type="button" class="btn btn-sm" x-on:click="toggle(it.id)" x-bind:disabled="! canVote || chosen.length >= picks"
                                x-text="chosen.length >= picks ? 'уже ' + picks : 'Выбрать'"></button>
                    </template>
                </div>
            </div>
        </template>
    </div>
</div>
