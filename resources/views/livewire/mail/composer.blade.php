<div>
@if($open)
<style>
/* Инвентарь окна (позиция/размер) — через Alpine :style; здесь только внутренности. */
.mail-composer *{box-sizing:border-box}
.mail-composer{font-family:var(--font-sans);color:var(--fg-1)}
.mail-composer .chd{display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg-surface-2);border-bottom:1px solid var(--border-subtle);cursor:move;user-select:none;touch-action:none;flex:0 0 auto}
.mail-composer .chd .ttl{font:600 12.5px/1 var(--font-sans);color:var(--fg-1);pointer-events:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mail-composer .chd .hint{font:400 11px/1 var(--font-sans);color:var(--fg-3);pointer-events:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.mail-composer .chd .wbtn{width:24px;height:24px;border:none;background:none;border-radius:4px;display:inline-flex;align-items:center;justify-content:center;color:var(--fg-3);cursor:pointer;font-size:13px}
.mail-composer .chd .wbtn:hover{background:var(--bg-hover);color:var(--fg-1)}
.mail-composer .cbody-wrap{flex:1 1 auto;overflow:hidden;display:flex;flex-direction:column;background:var(--bg-surface)}
.mail-composer .cfields{padding:0 14px;flex:0 0 auto}
.mail-composer .crow{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border-subtle);font-size:12.5px}
.mail-composer .crow .k{width:52px;color:var(--fg-3);font-weight:500;flex-shrink:0}
.mail-composer .crow input{flex:1;border:none;outline:none;background:transparent;font:400 13px/1.3 var(--font-sans);color:var(--fg-1);min-width:60px}
.mail-composer .crow .from{font:500 12.5px/1 var(--font-sans);color:var(--fg-1);display:flex;align-items:center;gap:6px}
.mail-composer .crow .from .dot{width:6px;height:6px;border-radius:999px;background:var(--emerald-600)}
.mail-composer .reqbadge{font:600 10.5px/1.4 var(--font-mono);background:var(--violet-50);color:var(--violet-700);padding:2px 7px;border-radius:4px}
.mail-composer .cbodyarea{flex:1 1 auto;overflow-y:auto;padding:12px 14px;display:flex;flex-direction:column;min-height:120px}
.mail-composer textarea{width:100%;flex:1 1 auto;min-height:110px;border:none;outline:none;resize:none;font:400 13.5px/1.6 var(--font-sans);color:var(--fg-1);background:transparent}
.mail-composer .sig{font:400 12px/1.5 var(--font-sans);color:var(--fg-3);margin-top:12px;padding-top:10px;border-top:1px dashed var(--border-subtle);white-space:pre-line;flex:0 0 auto}
.mail-composer .atts{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;flex:0 0 auto}
.mail-composer .att{display:inline-flex;align-items:center;gap:6px;height:24px;padding:0 8px;border-radius:999px;background:var(--sky-50);color:var(--sky-700);font:500 11px/1 var(--font-sans)}
.mail-composer .att .x{cursor:pointer;opacity:.7;border:none;background:none;color:inherit;font-size:12px}
.mail-composer .err{color:var(--red-700);font-size:11.5px;padding:4px 14px;flex:0 0 auto}
.mail-composer .cfoot{display:flex;align-items:center;gap:8px;padding:10px 14px;border-top:1px solid var(--border);background:var(--bg-surface-2);flex:0 0 auto}
.mail-composer .cfoot .btn{height:32px;padding:0 16px;border-radius:var(--r-md);font:600 12.5px/1 var(--font-sans);border:1px solid var(--accent);background:var(--accent);color:#fff;cursor:pointer}
.mail-composer .cfoot .btn[disabled]{opacity:.6;cursor:default}
.mail-composer .cfoot .lbl-file{width:32px;height:32px;border:1px solid var(--border);border-radius:var(--r-md);background:var(--bg-surface);display:inline-flex;align-items:center;justify-content:center;color:var(--fg-2);cursor:pointer;font-size:14px}
.mail-composer .cfoot .lbl-file input{display:none}
.mail-composer .cfoot .discard{border:none;background:none;color:var(--fg-3);cursor:pointer;font-size:12px}
.mail-composer .cfoot .spacer{flex:1}
.mail-composer .cfoot .save{font:400 11px/1 var(--font-sans);color:var(--fg-3);display:flex;align-items:center;gap:5px}
.mail-composer .cfoot .save .dot{width:5px;height:5px;border-radius:999px;background:var(--emerald-600)}
.mail-composer .rgrip{position:absolute;right:0;bottom:0;width:20px;height:20px;cursor:nwse-resize;touch-action:none;display:flex;align-items:flex-end;justify-content:flex-end;color:var(--fg-3);user-select:none;line-height:1;padding:2px}
[x-cloak]{display:none!important}
</style>

{{-- Телепорт в body: у предков layout есть transform → position:fixed без
     телепорта позиционируется относительно них («уезжает в подвал»). --}}
<template x-teleport="body">
<div x-data="{
        min: false,
        x: null, y: null,
        w: Math.min(560, window.innerWidth - 32),
        h: Math.min(520, window.innerHeight - 110),
        init() {
            try {
                const g = JSON.parse(localStorage.getItem('mylift.mail.composer.geom') || 'null');
                if (g && typeof g === 'object') {
                    if (typeof g.w === 'number') this.w = Math.min(Math.max(360, g.w), window.innerWidth - 8);
                    if (typeof g.h === 'number') this.h = Math.min(Math.max(300, g.h), window.innerHeight - 8);
                    if (typeof g.x === 'number' && typeof g.y === 'number') {
                        this.x = Math.min(Math.max(g.x, 60 - this.w), window.innerWidth - 100);
                        this.y = Math.min(Math.max(g.y, 0), window.innerHeight - 44);
                    }
                }
            } catch (e) {}
        },
        persist() {
            try { localStorage.setItem('mylift.mail.composer.geom', JSON.stringify({ x: this.x, y: this.y, w: this.w, h: this.h })); } catch (e) {}
        },
        styleStr() {
            const s = { width: this.w + 'px', height: this.min ? 'auto' : this.h + 'px' };
            if (this.x === null) { s.right = '24px'; s.bottom = '0px'; s.left = 'auto'; s.top = 'auto'; }
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
            window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
        },
        startResize(e) {
            e.preventDefault();
            const r = this.$refs.win.getBoundingClientRect();
            if (this.x === null) { this.x = r.left; this.y = r.top; }
            const sw = this.w, sh = this.h, sx = e.clientX, sy = e.clientY;
            const move = ev => {
                this.w = Math.min(Math.max(360, sw + (ev.clientX - sx)), window.innerWidth - 8);
                this.h = Math.min(Math.max(300, sh + (ev.clientY - sy)), window.innerHeight - 8);
            };
            const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); this.persist(); };
            window.addEventListener('pointermove', move); window.addEventListener('pointerup', up);
        }
     }"
     x-ref="win"
     class="mail-composer"
     :style="styleStr()"
     style="position:fixed;z-index:60;display:flex;flex-direction:column;
            max-width:calc(100vw - 8px);max-height:calc(100vh - 8px);
            background:var(--bg-surface);border:1px solid var(--border-strong);
            border-radius:10px 10px 0 0;box-shadow:0 18px 50px rgba(15,23,42,.3);overflow:hidden;">

    <div class="chd" @pointerdown="startDrag($event)">
        <span class="ttl">{{ $mode === 'compose' ? 'Новое письмо' : ($mode === 'forward' ? 'Пересылка' : ($subject ?: 'Ответ')) }}</span>
        <span class="hint">· перетащите за заголовок, растяните за угол</span>
        <button type="button" class="wbtn" @pointerdown.stop x-on:click="min = !min" :title="min ? 'Развернуть' : 'Свернуть'">
            <span x-show="!min">▁</span><span x-show="min" x-cloak>▢</span>
        </button>
        <button type="button" class="wbtn" @pointerdown.stop wire:click="close" title="Свернуть — черновик сохранится">×</button>
    </div>

    <div class="cbody-wrap" x-show="!min">
        <div class="cfields">
            <div class="crow">
                <span class="k">От</span>
                <span class="from"><span class="dot"></span>{{ $this->fromMailboxLabel }}</span>
                @if($relatedRequestId)<span class="reqbadge" title="Ответ отразится в заявке">заявка · пайплайн</span>@endif
            </div>
            <div class="crow">
                <span class="k">Кому</span>
                <input type="text" wire:model.live.debounce.1200ms="toRaw" placeholder="email, email …">
            </div>
            <div class="crow">
                <span class="k">Копия</span>
                <input type="text" wire:model.live.debounce.1200ms="ccRaw" placeholder="—">
            </div>
            <div class="crow">
                <span class="k">Тема</span>
                <input type="text" wire:model.live.debounce.1200ms="subject" placeholder="Тема письма">
            </div>
        </div>

        @error('subject')<div class="err">{{ $message }}</div>@enderror
        @error('toRaw')<div class="err">{{ $message }}</div>@enderror
        @error('bodyText')<div class="err">{{ $message }}</div>@enderror
        @error('newFiles.*')<div class="err">{{ $message }}</div>@enderror

        <div class="cbodyarea">
            <textarea wire:model.live.debounce.1200ms="bodyText" placeholder="Ваш ответ…"></textarea>

            @if($this->attachments->isNotEmpty())
                <div class="atts">
                    @foreach($this->attachments as $att)
                        <span class="att" wire:key="ca-{{ $att->id }}">
                            {{ \Illuminate\Support\Str::limit($att->filename, 24) }}
                            <button class="x" wire:click="removeAttachment({{ $att->id }})" title="Убрать">×</button>
                        </span>
                    @endforeach
                </div>
            @endif

            @if($this->signaturePreview)
                <div class="sig">{{ $this->signaturePreview }}</div>
            @endif
        </div>

        <div class="cfoot">
            <button class="btn" wire:click="send" wire:loading.attr="disabled" wire:target="send">
                <span wire:loading.remove wire:target="send">Отправить</span>
                <span wire:loading wire:target="send">Отправка…</span>
            </button>
            <label class="lbl-file" title="Прикрепить файл">📎<input type="file" multiple wire:model="newFiles"></label>
            <button class="discard" wire:click="discard">Удалить</button>
            <span class="spacer"></span>
            <span class="save" wire:loading.flex wire:target="updatedBodyText,updatedSubject,updatedToRaw,updatedCcRaw,uploadAttachments"><span class="dot"></span>Сохранение…</span>
        </div>
    </div>

    {{-- Ручка изменения размера (правый нижний угол). --}}
    <div class="rgrip" x-show="!min" @pointerdown="startResize($event)" title="Потяните, чтобы изменить размер">⤡</div>
</div>
</template>
@endif
</div>
