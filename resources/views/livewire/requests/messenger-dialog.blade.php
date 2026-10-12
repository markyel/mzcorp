{{-- Окно переписки с клиентом в Telegram/MAX — плавающее, как окно письма
     (requests/mail/compose-form): перетаскивается за заголовок, растягивается
     за уголок, сворачивается; геометрия — в localStorage. Компонент живёт в
     detail.blade ВНЕ табов. Телепорт в body — иначе position:fixed считается
     от предка с transform. --}}
<div class="messenger-dialog" wire:poll.8s="refreshIfOpen">
    @if($open)
        @php
            $req = $this->request;
            $chat = $req->messengerChat;
            $channel = $chat?->channel?->label() ?? 'мессенджер';
            $p = $this->presence;
        @endphp
        <template x-teleport="body">
        <div x-data="{
                min: false,
                x: null, y: null,
                w: Math.min(560, window.innerWidth - 32),
                h: Math.min(620, window.innerHeight - 110),
                init() {
                    try {
                        const g = JSON.parse(localStorage.getItem('mylift.messenger.geom') || 'null');
                        if (g && typeof g === 'object') {
                            if (typeof g.w === 'number') this.w = Math.min(Math.max(340, g.w), window.innerWidth - 8);
                            if (typeof g.h === 'number') this.h = Math.min(Math.max(320, g.h), window.innerHeight - 8);
                            if (typeof g.x === 'number' && typeof g.y === 'number') {
                                this.x = Math.min(Math.max(g.x, 60 - this.w), window.innerWidth - 100);
                                this.y = Math.min(Math.max(g.y, 0), window.innerHeight - 44);
                            }
                        }
                    } catch (e) {}
                    this.$nextTick(() => this.scrollDown());
                },
                persist() {
                    try { localStorage.setItem('mylift.messenger.geom', JSON.stringify({ x: this.x, y: this.y, w: this.w, h: this.h })); } catch (e) {}
                },
                scrollDown() { const el = this.$refs.feed; if (el) el.scrollTop = el.scrollHeight; },
                styleStr() {
                    const s = { width: this.w + 'px', height: this.min ? 'auto' : this.h + 'px' };
                    if (this.x === null) { s.right = '16px'; s.bottom = '16px'; s.left = 'auto'; s.top = 'auto'; }
                    else { s.left = this.x + 'px'; s.top = this.y + 'px'; s.right = 'auto'; s.bottom = 'auto'; }
                    return s;
                },
                startDrag(e) {
                    if (e.button !== undefined && e.button !== 0) return;
                    const r = this.$refs.win.getBoundingClientRect();
                    this.x = r.left; this.y = r.top;
                    const ox = e.clientX - r.left, oy = e.clientY - r.top;
                    const move = ev => {
                        this.x = Math.min(Math.max(ev.clientX - ox, 60 - this.w), window.innerWidth - 100);
                        this.y = Math.min(Math.max(ev.clientY - oy, 0), window.innerHeight - 44);
                    };
                    const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); this.persist(); };
                    window.addEventListener('pointermove', move);
                    window.addEventListener('pointerup', up);
                },
                startResize(e) {
                    e.preventDefault();
                    const r = this.$refs.win.getBoundingClientRect();
                    if (this.x === null) { this.x = r.left; this.y = r.top; }
                    const sw = this.w, sh = this.h, sx = e.clientX, sy = e.clientY;
                    const move = ev => {
                        this.w = Math.min(Math.max(340, sw + (ev.clientX - sx)), window.innerWidth - 8);
                        this.h = Math.min(Math.max(320, sh + (ev.clientY - sy)), window.innerHeight - 8);
                    };
                    const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); this.persist(); };
                    window.addEventListener('pointermove', move);
                    window.addEventListener('pointerup', up);
                }
             }"
             x-ref="win"
             x-on:messenger-sent.window="$nextTick(() => scrollDown())"
             :style="styleStr()"
             style="position: fixed; z-index: 61; display: flex; flex-direction: column;
                    max-width: calc(100vw - 8px); max-height: calc(100vh - 8px);
                    background: var(--bg-surface); border: 1px solid var(--border-strong);
                    border-radius: 10px; box-shadow: 0 18px 50px rgba(15, 23, 42, 0.3); overflow: hidden;">

            {{-- Заголовок: канал, клиент, статус; drag-handle. --}}
            <div @pointerdown="startDrag($event)"
                 style="cursor: move; user-select: none; touch-action: none; flex: 0 0 auto;
                        display: flex; align-items: center; gap: 8px; padding: 8px 12px;
                        background: var(--bg-surface-2); border-bottom: 1px solid var(--border-subtle);">
                <div style="pointer-events: none; min-width: 0; flex: 1;">
                    <div class="text-[13px] font-semibold text-fg-1 truncate">
                        💬 {{ $channel }} · {{ $chat?->displayName() }} · {{ $req->internal_code }}
                    </div>
                    <div class="text-[11px] truncate">
                        @if($p && $p['blocked'])
                            <span style="color: var(--amber-800)">⚠ клиент отключил бота — сообщения не доходят, пишите на {{ $req->client_email }}</span>
                        @elseif($p && $p['active'])
                            <span style="color: var(--emerald-700)">● активен в боте {{ $p['last']->diffForHumans() }}</span>
                        @elseif($p && $p['last'])
                            <span class="text-fg-3">был в боте {{ $p['last']->isToday() ? 'сегодня в '.$p['last']->format('H:i') : $p['last']->format('d.m.Y в H:i') }}</span>
                        @else
                            <span class="text-fg-3">ещё не писал в бот</span>
                        @endif
                        <span class="text-fg-4"> · онлайн-статус мессенджер боту не сообщает</span>
                    </div>
                </div>
                <button type="button" @pointerdown.stop x-on:click="min = !min"
                        class="text-fg-3 hover:text-fg-1 text-[13px]" style="padding: 2px 7px; line-height: 1;"
                        :title="min ? 'Развернуть окно' : 'Свернуть в строку'">
                    <span x-show="!min">▁</span><span x-show="min" x-cloak>▢</span>
                </button>
                <button type="button" @pointerdown.stop wire:click="close"
                        class="text-fg-3 hover:text-fg-1 text-[13px]" style="padding: 2px 7px; line-height: 1;" title="Закрыть">✕</button>
            </div>

            {{-- Лента переписки; новые сообщения клиента подтягиваются сами. --}}
            <div x-show="!min" x-ref="feed"
                 style="flex: 1 1 auto; overflow-y: auto; padding: 10px 12px; background: var(--bg-app);
                        display: flex; flex-direction: column; gap: 6px;">
                @forelse($this->messages as $m)
                    @php
                        $in = $m->direction === 'in';
                        $who = match ($m->kind) {
                            'request' => 'Клиент · заявка',
                            'client' => 'Клиент',
                            'manager' => $m->author?->name ?? 'Менеджер',
                            'notify' => 'Бот · уведомление',
                            default => $in ? 'Клиент' : 'Бот',
                        };
                        $atts = (array) (($m->meta ?? [])['attachments'] ?? []);
                        $files = (array) (($m->meta ?? [])['files'] ?? []);
                        $failed = ! empty(($m->meta ?? [])['send_failed']);
                        $body = $m->kind === 'notify' ? html_entity_decode(strip_tags((string) $m->text)) : (string) $m->text;
                    @endphp
                    <div style="display: flex; justify-content: {{ $in ? 'flex-start' : 'flex-end' }};" wire:key="md-{{ $m->id }}">
                        <div style="max-width: 82%; border-radius: 10px; padding: 6px 10px; font-size: 13px; line-height: 1.4;
                                    background: {{ $in ? 'var(--bg-surface)' : ($m->kind === 'manager' ? 'var(--sky-50)' : 'var(--neutral-100)') }};
                                    border: 1px solid var(--border-subtle);">
                            <div style="font-size: 10.5px; color: var(--fg-3); margin-bottom: 2px;">
                                {{ $who }} · {{ $m->created_at?->format('d.m H:i') }}
                                @if($failed)<span style="color: var(--amber-800)"> · не доставлено</span>@endif
                            </div>
                            @if(trim($body) !== '')
                                <div style="white-space: pre-line;">{{ $body }}</div>
                            @endif
                            @if($atts)
                                <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px;">
                                    @foreach($atts as $i => $a)
                                        @php $url = route('messenger.file', ['message' => $m->id, 'index' => $i]); @endphp
                                        @if(str_starts_with((string) ($a['mime'] ?? ''), 'image/'))
                                            <a href="{{ $url }}" target="_blank" rel="noopener">
                                                <img src="{{ $url }}" alt="{{ $a['name'] }}" loading="lazy"
                                                     style="width: 120px; height: 90px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-subtle);">
                                            </a>
                                        @else
                                            <a href="{{ $url }}" target="_blank" rel="noopener" class="text-sky-700 hover:underline" style="font-size: 12px;">📎 {{ $a['name'] }}</a>
                                        @endif
                                    @endforeach
                                </div>
                            @elseif($files)
                                <div style="font-size: 11.5px; color: var(--fg-3); margin-top: 3px;">📎 {{ implode(', ', $files) }}</div>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="text-[12.5px] text-fg-3">Переписки по этой заявке в {{ $channel }} пока нет.</div>
                @endforelse
            </div>

            {{-- Ответ клиенту. --}}
            @if($this->canWrite())
                <div x-show="!min" style="flex: 0 0 auto; padding: 8px 12px 18px; background: var(--bg-surface-2); border-top: 1px solid var(--border-subtle);">
                    @if($uploads)
                        <div style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px;">
                            @foreach($uploads as $i => $u)
                                <span class="chip text-[11px]" style="background: var(--neutral-100); color: var(--fg-2);" wire:key="up-{{ $i }}">
                                    📎 {{ $u->getClientOriginalName() }}
                                    <button type="button" wire:click="removeUpload({{ $i }})" class="text-fg-3 hover:text-fg-1" style="margin-left: 4px;">✕</button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    <textarea wire:model="text" rows="3" maxlength="3500"
                              x-on:keydown.ctrl.enter.prevent="$el.parentElement.querySelector('[data-send]')?.click()"
                              placeholder="Сообщение клиенту в {{ $channel }}… (Ctrl+Enter — отправить)"
                              style="width: 100%; resize: none; font-size: 13px; padding: 6px 8px; border-radius: 6px;
                                     border: 1px solid var(--border); background: var(--bg-surface);"></textarea>
                    <div class="messenger-send-scope" style="display: flex; align-items: center; gap: 8px; margin-top: 6px;">
                        <label class="btn btn-sm" style="cursor: pointer;" title="Фото, PDF, Excel — до 20 МБ, не больше 10 за раз">
                            📎 Файл
                            <input type="file" wire:model="uploads" multiple class="hidden">
                        </label>
                        <span wire:loading wire:target="uploads" class="text-[12px] text-amber-700">загружаем…</span>
                        <span style="flex: 1;"></span>
                        @if($flash)<span class="text-[12px] text-emerald-700">{{ $flash }}</span>@endif
                        <button type="button" data-send wire:click="send" class="btn btn-sm btn-primary"
                                wire:loading.attr="disabled" wire:target="send,uploads">Отправить в {{ $channel }}</button>
                    </div>
                    @if($error)<div class="text-[12px] mt-1" style="color: var(--amber-800)">{{ $error }}</div>@endif
                    @error('uploads') <div class="text-[12px] mt-1" style="color: var(--amber-800)">{{ $message }}</div> @enderror
                    @error('uploads.*') <div class="text-[12px] mt-1" style="color: var(--amber-800)">{{ $message }}</div> @enderror
                    @error('text') <div class="text-[12px] mt-1" style="color: var(--amber-800)">{{ $message }}</div> @enderror
                </div>
            @endif

            <div x-show="!min" @pointerdown="startResize($event)"
                 style="position: absolute; right: 0; bottom: 0; width: 20px; height: 20px; cursor: nwse-resize;
                        touch-action: none; display: flex; align-items: flex-end; justify-content: flex-end;
                        padding: 0 3px 1px 0; color: var(--fg-3); font-size: 11px; user-select: none; line-height: 1;"
                 title="Растянуть окно">◢</div>
        </div>
        </template>
    @endif
</div>
