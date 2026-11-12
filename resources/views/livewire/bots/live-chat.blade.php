{{-- Живой чат с клиентом бота (BotLiveChatService) — в общем макете, поверх любой страницы. Окно перетаскивается
     за заголовок и сворачивается; место — в localStorage (mylift.livechat.geom): в другом разделе встаёт туда же.
     Стили — inline: новые классы Tailwind без пересборки CSS не работают. --}}
<div @if($chatId) wire:poll.3s="tick" @endif>
    @if($chat = $this->chat)
        @php
            $until = \App\Services\Bot\BotLiveChatService::releasesAt($chat);
            $channel = $chat->channel?->label() ?? 'мессенджер';
        @endphp
        <div x-data="{
                min: false, x: null, y: null, w: Math.min(420, window.innerWidth - 32), h: Math.min(560, window.innerHeight - 110),
                until: {{ $until ? $until->getTimestamp() * 1000 : 'null' }}, left: '',
                init() {
                    try {
                        const g = JSON.parse(localStorage.getItem('mylift.livechat.geom') || 'null');
                        if (g && typeof g === 'object') {
                            if (typeof g.min === 'boolean') this.min = g.min;
                            if (typeof g.w === 'number') this.w = Math.min(Math.max(320, g.w), window.innerWidth - 8);
                            if (typeof g.h === 'number') this.h = Math.min(Math.max(300, g.h), window.innerHeight - 8);
                            if (typeof g.x === 'number' && typeof g.y === 'number') {
                                this.x = Math.min(Math.max(g.x, 60 - this.w), window.innerWidth - 100);
                                this.y = Math.min(Math.max(g.y, 0), window.innerHeight - 44);
                            }
                        }
                    } catch (e) {}
                    const tick = () => { this.until = Number(this.$refs.win?.dataset.until || 0) || null; if (!this.until) { this.left = ''; return; } const s = Math.max(0, Math.round((this.until - Date.now()) / 1000)); this.left = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); };
                    tick(); setInterval(tick, 1000);
                    this.$nextTick(() => this.scrollDown(true));
                },
                persist() { try { localStorage.setItem('mylift.livechat.geom', JSON.stringify({ x: this.x, y: this.y, w: this.w, h: this.h, min: this.min })); } catch (e) {} },
                scrollDown(force) { const el = this.$refs.feed; if (el && (force || el.scrollHeight - el.scrollTop - el.clientHeight < 120)) el.scrollTop = el.scrollHeight; },
                styleObj() {
                    const s = { width: this.w + 'px', height: this.min ? 'auto' : this.h + 'px' };
                    if (this.x === null) { s.left = '16px'; s.bottom = '16px'; s.right = 'auto'; s.top = 'auto'; }
                    else { s.left = this.x + 'px'; s.top = this.y + 'px'; s.right = 'auto'; s.bottom = 'auto'; }
                    return s;
                },
                startDrag(e) {
                    if (e.button !== undefined && e.button !== 0) return;
                    const r = this.$refs.win.getBoundingClientRect();
                    this.x = r.left; this.y = r.top;
                    const ox = e.clientX - r.left, oy = e.clientY - r.top;
                    const move = ev => { this.x = Math.min(Math.max(ev.clientX - ox, 60 - this.w), window.innerWidth - 100); this.y = Math.min(Math.max(ev.clientY - oy, 0), window.innerHeight - 44); };
                    const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); this.persist(); };
                    window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
                },
                startResize(e) {
                    e.preventDefault();
                    const r = this.$refs.win.getBoundingClientRect();
                    if (this.x === null) { this.x = r.left; this.y = r.top; }
                    const sw = this.w, sh = this.h, sx = e.clientX, sy = e.clientY;
                    const move = ev => { this.w = Math.min(Math.max(320, sw + (ev.clientX - sx)), window.innerWidth - 8); this.h = Math.min(Math.max(300, sh + (ev.clientY - sy)), window.innerHeight - 8); };
                    const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); this.persist(); };
                    window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
                }
             }"
             x-ref="win" :style="styleObj()" wire:key="live-chat-{{ $chat->id }}" data-until="{{ $until ? $until->getTimestamp() * 1000 : '' }}"
             style="position: fixed; z-index: 62; display: flex; flex-direction: column; max-width: calc(100vw - 8px); max-height: calc(100vh - 8px);
                    background: var(--bg-surface); border: 2px solid var(--emerald-600, #059669); border-radius: 10px;
                    box-shadow: 0 18px 50px rgba(15, 23, 42, 0.32); overflow: hidden;">

            {{-- Заголовок: кто, канал, когда вернётся боту; drag-handle. --}}
            <div @pointerdown="startDrag($event)"
                 style="cursor: move; user-select: none; touch-action: none; flex: 0 0 auto; display: flex; align-items: center; gap: 8px;
                        padding: 8px 12px; background: var(--emerald-50, #ecfdf5); border-bottom: 1px solid var(--border-subtle);">
                <div style="pointer-events: none; min-width: 0; flex: 1;">
                    <div style="font-size: 13px; font-weight: 600; color: var(--fg-1); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                        ● Живой чат · {{ $chat->displayName() }} · {{ $channel }}
                    </div>
                    <div style="font-size: 11px; color: var(--fg-3);">бот молчит · вернётся боту через <b x-text="left" style="color: var(--fg-2)"></b> без сообщений</div>
                </div>
                <button type="button" @pointerdown.stop x-on:click="min = !min; persist()" style="padding: 2px 7px; line-height: 1; font-size: 13px; color: var(--fg-3);"
                        :title="min ? 'Развернуть окно' : 'Свернуть в строку'"><span x-show="!min">▁</span><span x-show="min" x-cloak>▢</span></button>
                <button type="button" @pointerdown.stop wire:click="release" wire:confirm="Вернуть чат боту? Клиенту придёт, что разговор с менеджером завершён."
                        class="btn btn-xs" title="Закончить разговор — дальше отвечает бот">Вернуть боту</button>
            </div>

            {{-- Лента: последние сообщения чата; новые подтягиваются сами. --}}
            <div x-show="!min" x-ref="feed"
                 x-init="new MutationObserver(() => scrollDown(false)).observe($el, { childList: true, subtree: true })"
                 style="flex: 1 1 auto; overflow-y: auto; padding: 10px 12px; background: var(--bg-app); display: flex; flex-direction: column; gap: 6px;">
                @foreach($this->messages as $m)
                    @php
                        $in = $m->direction === 'in';
                        $event = ($m->meta ?? [])['event'] ?? null;
                        $who = $in ? 'Клиент' : match ($m->kind) {
                            'live' => $m->author?->name ?? 'Менеджер',
                            'manager' => ($m->author?->name ?? 'Менеджер').' · по заявке',
                            'notify' => 'Бот · уведомление',
                            default => 'Бот'.($m->author ? ' · '.$m->author->name : ''),
                        };
                        $atts = (array) (($m->meta ?? [])['attachments'] ?? []);
                        $files = (array) (($m->meta ?? [])['files'] ?? []);
                        $body = $in || $m->kind === 'live' ? (string) $m->text : html_entity_decode(strip_tags((string) $m->text));
                        if ($m->kind === 'callback') { $body = '[нажал кнопку]'; }
                    @endphp
                    @if($event)
                        <div wire:key="lc-{{ $m->id }}" style="text-align: center; font-size: 11px; color: var(--fg-3);">
                            — {{ $event === 'start' ? 'чат взял '.($m->author?->name ?? 'менеджер') : 'чат вернулся боту' }} · {{ $m->created_at?->format('H:i') }} —
                        </div>
                        @continue
                    @endif
                    <div wire:key="lc-{{ $m->id }}" style="display: flex; justify-content: {{ $in ? 'flex-start' : 'flex-end' }};">
                        <div style="max-width: 85%; border-radius: 10px; padding: 6px 10px; font-size: 13px; line-height: 1.4; border: 1px solid var(--border-subtle);
                                    background: {{ $in ? 'var(--bg-surface)' : ($m->kind === 'live' ? 'var(--emerald-50, #ecfdf5)' : 'var(--neutral-100)') }};">
                            <div style="font-size: 10.5px; color: var(--fg-3); margin-bottom: 2px;">
                                {{ $who }} · {{ $m->created_at?->format('d.m H:i') }}
                                @if(! empty(($m->meta ?? [])['send_failed']))<span style="color: var(--amber-800)"> · не доставлено</span>@endif
                            </div>
                            @if(trim($body) !== '')<div style="white-space: pre-line;">{{ $body }}</div>@endif
                            @if($atts)
                                <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px;">
                                    @foreach($atts as $i => $a)
                                        @php $url = route('messenger.file', ['message' => $m->id, 'index' => $i]); @endphp
                                        @include('partials.file-link', ['url' => $url, 'name' => (string) ($a['name'] ?? 'файл'), 'mime' => $a['mime'] ?? null, 'fileClass' => 'text-sky-700 hover:underline text-[12px]'])
                                    @endforeach
                                </div>
                            @elseif($files)
                                <div style="font-size: 11.5px; color: var(--fg-3); margin-top: 3px;">📎 {{ implode(', ', $files) }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Ответ: Enter — отправить, Shift+Enter — новая строка. --}}
            <div x-show="!min" style="flex: 0 0 auto; padding: 8px 12px 16px; background: var(--bg-surface-2); border-top: 1px solid var(--border-subtle);">
                <textarea wire:model="text" rows="2" maxlength="{{ \App\Services\Bot\BotLiveChatService::MAX_TEXT }}"
                          x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.send().then(() => scrollDown(true)) }"
                          placeholder="Сообщение клиенту… Enter — отправить, Shift+Enter — новая строка"
                          style="width: 100%; resize: none; font-size: 13px; padding: 6px 8px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-surface);"></textarea>
                <div style="display: flex; align-items: center; gap: 8px; margin-top: 6px;">
                    @if($error)<span style="font-size: 12px; color: var(--amber-800)">{{ $error }}</span>@endif
                    @error('text')<span style="font-size: 12px; color: var(--amber-800)">{{ $message }}</span>@enderror
                    <span style="flex: 1;"></span>
                    <button type="button" wire:click="send" x-on:click="$nextTick(() => scrollDown(true))" class="btn btn-sm btn-primary" wire:loading.attr="disabled" wire:target="send">Отправить</button>
                </div>
            </div>

            <div x-show="!min" @pointerdown="startResize($event)"
                 style="position: absolute; right: 0; bottom: 0; width: 20px; height: 20px; cursor: nwse-resize; touch-action: none; display: flex;
                        align-items: flex-end; justify-content: flex-end; padding: 0 3px 1px 0; color: var(--fg-3); font-size: 11px; user-select: none; line-height: 1;"
                 title="Растянуть окно">◢</div>
        </div>
    @endif
</div>
