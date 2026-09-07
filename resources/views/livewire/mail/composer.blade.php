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
.mail-composer .rte{flex:1 1 auto;display:flex;flex-direction:column;min-height:120px}
/* Панель в духе TipTap Simple Editor: иконки без рамок, группы через тонкие разделители. */
.mail-composer .rte-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:1px;padding:2px 0 6px;margin-bottom:8px;border-bottom:1px solid var(--border-subtle);flex:0 0 auto}
.mail-composer .rte-toolbar button,.mail-composer .rte-tablebar button{width:30px;height:30px;padding:0;border:none;background:transparent;border-radius:6px;cursor:pointer;color:var(--fg-2);display:inline-flex;align-items:center;justify-content:center;line-height:1;white-space:nowrap;transition:background .12s,color .12s}
.mail-composer .rte-toolbar button:hover,.mail-composer .rte-tablebar button:hover{background:var(--bg-hover);color:var(--fg-1)}
.mail-composer .rte-toolbar button.on,.mail-composer .rte-tablebar button.on{background:var(--accent-bg);color:var(--accent)}
.mail-composer .rte-toolbar button[disabled]{opacity:.35;cursor:default;background:transparent}
.mail-composer .rte-toolbar button.dd,.mail-composer .rte-tablebar button.dd{width:auto;padding:0 8px;gap:5px;font:500 12.5px/1 var(--font-sans)}
.mail-composer .rte-toolbar button.add{color:var(--fg-2)}
.mail-composer .rte-ico{width:16px;height:16px;flex:0 0 auto}
.mail-composer .rte-ico.sm{width:13px;height:13px;opacity:.7}
.mail-composer .rte-toolbar .sep,.mail-composer .rte-tablebar .sep{width:1px;height:20px;background:var(--border);margin:0 6px;flex:0 0 auto}
.mail-composer .rte-toolbar .clr-a{font:700 14px/1 var(--font-sans);border-bottom:3px solid var(--accent);padding:0 1px 1px}
.mail-composer .rte-pop{position:relative;display:inline-flex}
.mail-composer .rte-popover,.mail-composer .rte-menu{position:absolute;top:34px;left:0;z-index:5;background:var(--bg-surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 10px 30px rgba(15,23,42,.16);padding:6px;display:flex;align-items:center;gap:4px;font-size:12.5px;white-space:nowrap}
.mail-composer .rte-menu{flex-direction:column;align-items:stretch;min-width:170px;padding:4px}
.mail-composer .rte-menu button{width:100%;height:30px;justify-content:flex-start;padding:0 10px;font:400 13px/1 var(--font-sans);color:var(--fg-1);border-radius:5px}
.mail-composer .rte-menu button.h2{font-weight:600;font-size:15px}
.mail-composer .rte-menu button.h3{font-weight:600;font-size:13.5px}
.mail-composer .rte-popover input[type=text]{width:260px;height:30px;border:1px solid var(--border);border-radius:6px;padding:0 8px;font:400 12.5px/1 var(--font-mono);color:var(--fg-1);background:var(--bg-surface);outline:none}
.mail-composer .rte-popover input[type=text]:focus{border-color:var(--sky-500)}
.mail-composer .rte-popover input[type=number]{width:54px;height:30px;border:1px solid var(--border);border-radius:6px;padding:0 6px;font:400 12.5px/1 var(--font-sans);color:var(--fg-1);background:var(--bg-surface);margin-left:6px;outline:none}
.mail-composer .rte-popover label{color:var(--fg-2);display:inline-flex;align-items:center;margin-right:4px}
.mail-composer .rte-popover .ok{background:var(--accent);color:#fff}
.mail-composer .rte-popover .ok:hover{background:var(--accent-600);color:#fff}
.mail-composer .rte-popover .rm{color:var(--fg-3)}
.mail-composer .rte-colors{padding:8px}
.mail-composer .rte-colors .swatch{width:22px;height:22px;min-width:22px;border-radius:999px;border:1px solid rgba(0,0,0,.12);padding:0}
.mail-composer .rte-colors .swatch:hover{transform:scale(1.12)}
.mail-composer .rte-colors .swatch.none{background:var(--bg-surface);color:var(--fg-3)}
.mail-composer .rte-tablebar{display:flex;align-items:center;flex-wrap:wrap;gap:1px;padding:0 0 6px;margin-bottom:6px;border-bottom:1px dashed var(--border-subtle);flex:0 0 auto}
.mail-composer .rte-tablebar .lbl{font:500 11.5px/1 var(--font-sans);color:var(--fg-3);margin:0 6px 0 2px;text-transform:uppercase;letter-spacing:.04em}
.mail-composer .rte-tablebar .danger{color:var(--red-700)}
.mail-composer .rte-tablebar .danger:hover{background:var(--red-50);color:var(--red-700)}
.mail-composer .rte-note{font:400 11.5px/1.3 var(--font-sans);color:var(--fg-3);padding:0 0 6px}
.mail-composer .rte-note.err{color:var(--red-700)}
.mail-composer .rte-ed{flex:1 1 auto;min-height:100px;font:400 13.5px/1.6 var(--font-sans);color:var(--fg-1);overflow-y:auto;word-break:break-word;display:flex;flex-direction:column}
.mail-composer .rte-ed .ProseMirror{outline:none;flex:1 1 auto;min-height:100px}
.mail-composer .rte-ed .ProseMirror p{margin:0 0 6px}
.mail-composer .rte-ed .ProseMirror p.is-editor-empty:first-child::before{content:attr(data-placeholder);color:var(--fg-4);float:left;height:0;pointer-events:none}
.mail-composer .rte-ed h2{font:600 17px/1.3 var(--font-sans);margin:10px 0 6px}
.mail-composer .rte-ed h3{font:600 14.5px/1.3 var(--font-sans);margin:8px 0 4px}
.mail-composer .rte-ed a{color:var(--sky-700);text-decoration:underline}
.mail-composer .rte-ed ul{list-style:disc outside;margin:4px 0;padding-left:24px}
.mail-composer .rte-ed ol{list-style:decimal outside;margin:4px 0;padding-left:24px}
.mail-composer .rte-ed li{margin:2px 0}
.mail-composer .rte-ed blockquote{margin:6px 0;padding-left:12px;border-left:2px solid var(--border-strong);color:var(--fg-2)}
.mail-composer .rte-ed hr{border:none;border-top:1px solid var(--border);margin:10px 0}
.mail-composer .rte-ed img{max-width:100%;height:auto;display:block;margin:6px 0;border-radius:2px}
.mail-composer .rte-ed img.ProseMirror-selectednode{outline:2px solid var(--sky-500)}
.mail-composer .rte-ed table{border-collapse:collapse;table-layout:fixed;width:100%;margin:6px 0;overflow:hidden}
.mail-composer .rte-ed td,.mail-composer .rte-ed th{border:1px solid var(--border);padding:4px 8px;min-width:40px;vertical-align:top;position:relative}
.mail-composer .rte-ed th{background:var(--bg-surface-2);font-weight:600;text-align:left}
.mail-composer .rte-ed td p,.mail-composer .rte-ed th p{margin:0}
.mail-composer .rte-ed .selectedCell:after{content:'';position:absolute;inset:0;background:rgba(14,165,233,.12);pointer-events:none}
.mail-composer .rte-ed .ProseMirror-gapcursor:after{border-top:1px solid var(--fg-1)}
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
            {{-- Богатый редактор (TipTap, resources/js/mail-editor.js) под wire:ignore
                 (иначе Livewire morph сбивал бы курсор); wire:key по черновику —
                 при открытии другого письма редактор пересоздаётся с его HTML.
                 Синхронизация в $wire.bodyHtml с debounce; перед отправкой —
                 событие mail-editor-flush. --}}
            <div class="rte" wire:ignore wire:key="rte-{{ $draftId ?? 0 }}"
                 x-data="mailEditor({
                     wireId: @js($this->getId()),
                     initialHtml: @js($bodyHtml),
                     uploadUrl: @js($this->inlineImageUploadUrl),
                     csrf: @js(csrf_token()),
                     placeholder: 'Ваш ответ…',
                 })"
                 @mail-editor-flush.window="flush()"
                 @click.outside="linkOpen = false; tableOpen = false; colorOpen = false; blockOpen = false">
                <div class="rte-toolbar">
                    <button type="button" @click="run(c => c.undo())" :disabled="!can('undo')" title="Отменить (Ctrl+Z)"><x-rte-icon name="undo"/></button>
                    <button type="button" @click="run(c => c.redo())" :disabled="!can('redo')" title="Повторить (Ctrl+Y)"><x-rte-icon name="redo"/></button>
                    <span class="sep"></span>
                    <span class="rte-pop">
                        <button type="button" class="dd" :class="{ on: blockValue() !== 'p' }" @click="blockOpen = !blockOpen; linkOpen = false; tableOpen = false; colorOpen = false" title="Стиль абзаца">
                            <span x-text="blockValue() === 'p' ? 'Текст' : (blockValue() === '2' ? 'Заголовок' : 'Подзаголовок')"></span><x-rte-icon name="chevron" class="rte-ico sm"/>
                        </button>
                        <div class="rte-menu" x-show="blockOpen" x-cloak>
                            <button type="button" :class="{ on: blockValue() === 'p' }" @click="setBlock('p'); blockOpen = false">Обычный текст</button>
                            <button type="button" class="h2" :class="{ on: blockValue() === '2' }" @click="setBlock('2'); blockOpen = false">Заголовок</button>
                            <button type="button" class="h3" :class="{ on: blockValue() === '3' }" @click="setBlock('3'); blockOpen = false">Подзаголовок</button>
                        </div>
                    </span>
                    <span class="sep"></span>
                    <button type="button" :class="{ on: is('bold') }" @click="run(c => c.toggleBold())" title="Жирный (Ctrl+B)"><x-rte-icon name="bold"/></button>
                    <button type="button" :class="{ on: is('italic') }" @click="run(c => c.toggleItalic())" title="Курсив (Ctrl+I)"><x-rte-icon name="italic"/></button>
                    <button type="button" :class="{ on: is('underline') }" @click="run(c => c.toggleUnderline())" title="Подчёркнутый (Ctrl+U)"><x-rte-icon name="underline"/></button>
                    <button type="button" :class="{ on: is('strike') }" @click="run(c => c.toggleStrike())" title="Зачёркнутый"><x-rte-icon name="strike"/></button>
                    <span class="rte-pop">
                        <button type="button" :class="{ on: colorOpen }" @click="colorOpen = !colorOpen; linkOpen = false; tableOpen = false; blockOpen = false" title="Цвет текста"><span class="clr-a">A</span></button>
                        <div class="rte-popover rte-colors" x-show="colorOpen" x-cloak>
                            <template x-for="c in colors" :key="c">
                                <button type="button" class="swatch" :style="'background:' + c" @click="setColor(c)" :title="c"></button>
                            </template>
                            <button type="button" class="swatch none" @click="setColor(null)" title="Без цвета"><x-rte-icon name="x" class="rte-ico sm"/></button>
                        </div>
                    </span>
                    <span class="sep"></span>
                    <button type="button" :class="{ on: is({ textAlign: 'left' }) }" @click="run(c => c.setTextAlign('left'))" title="По левому краю"><x-rte-icon name="align-left"/></button>
                    <button type="button" :class="{ on: is({ textAlign: 'center' }) }" @click="run(c => c.setTextAlign('center'))" title="По центру"><x-rte-icon name="align-center"/></button>
                    <button type="button" :class="{ on: is({ textAlign: 'right' }) }" @click="run(c => c.setTextAlign('right'))" title="По правому краю"><x-rte-icon name="align-right"/></button>
                    <span class="sep"></span>
                    <button type="button" :class="{ on: is('bulletList') }" @click="run(c => c.toggleBulletList())" title="Маркированный список"><x-rte-icon name="list"/></button>
                    <button type="button" :class="{ on: is('orderedList') }" @click="run(c => c.toggleOrderedList())" title="Нумерованный список"><x-rte-icon name="list-ordered"/></button>
                    <button type="button" :class="{ on: is('blockquote') }" @click="run(c => c.toggleBlockquote())" title="Цитата"><x-rte-icon name="quote"/></button>
                    <span class="sep"></span>
                    <span class="rte-pop">
                        <button type="button" :class="{ on: is('link') || linkOpen }" @click="linkOpen ? (linkOpen = false) : openLink(); tableOpen = false; colorOpen = false; blockOpen = false" title="Ссылка (Ctrl+K)"><x-rte-icon name="link"/></button>
                        <div class="rte-popover rte-link" x-show="linkOpen" x-cloak @keydown.enter.prevent="applyLink()" @keydown.escape="linkOpen = false">
                            <input type="text" x-ref="linkInput" x-model="linkUrl" placeholder="https://…">
                            <button type="button" class="ok" @click="applyLink()" title="Применить"><x-rte-icon name="check"/></button>
                            <button type="button" class="rm" @click="removeLink()" title="Убрать ссылку"><x-rte-icon name="x"/></button>
                        </div>
                    </span>
                    <span class="rte-pop">
                        <button type="button" :class="{ on: is('table') || tableOpen }" @click="tableOpen = !tableOpen; linkOpen = false; colorOpen = false; blockOpen = false" title="Таблица"><x-rte-icon name="table"/></button>
                        <div class="rte-popover rte-table" x-show="tableOpen" x-cloak @keydown.enter.prevent="insertTable()" @keydown.escape="tableOpen = false">
                            <label>Строк <input type="number" min="1" max="30" x-model="tableRows"></label>
                            <label>Столбцов <input type="number" min="1" max="12" x-model="tableCols"></label>
                            <button type="button" class="ok" @click="insertTable()" title="Вставить таблицу"><x-rte-icon name="check"/></button>
                        </div>
                    </span>
                    <button type="button" @click="run(c => c.setHorizontalRule())" title="Разделитель"><x-rte-icon name="minus"/></button>
                    <button type="button" @click="clearFormat()" title="Убрать форматирование"><x-rte-icon name="eraser"/></button>
                    <span class="sep"></span>
                    <button type="button" class="dd add" @click="pickImage()" title="Картинка в текст письма (или вставьте из буфера / перетащите)"><x-rte-icon name="image"/><span>Картинка</span></button>
                </div>

                {{-- Панель таблицы — когда курсор внутри таблицы. --}}
                <div class="rte-tablebar" x-show="is('table')" x-cloak>
                    <span class="lbl">Таблица</span>
                    <button type="button" class="dd" @click="run(c => c.addRowAfter())" title="Добавить строку ниже"><x-rte-icon name="rows"/><span>+ строка</span></button>
                    <button type="button" class="dd" @click="run(c => c.addColumnAfter())" title="Добавить столбец справа"><x-rte-icon name="columns"/><span>+ столбец</span></button>
                    <span class="sep"></span>
                    <button type="button" class="dd" @click="run(c => c.deleteRow())" title="Удалить строку"><x-rte-icon name="minus"/><span>строка</span></button>
                    <button type="button" class="dd" @click="run(c => c.deleteColumn())" title="Удалить столбец"><x-rte-icon name="minus"/><span>столбец</span></button>
                    <span class="sep"></span>
                    <button type="button" class="dd" @click="run(c => c.toggleHeaderRow())" title="Строка-заголовок вкл/выкл"><span>Шапка</span></button>
                    <button type="button" @click="run(c => c.mergeOrSplit())" title="Объединить / разделить ячейки"><x-rte-icon name="merge"/></button>
                    <span class="sep"></span>
                    <button type="button" class="danger" @click="run(c => c.deleteTable())" title="Удалить таблицу"><x-rte-icon name="trash"/></button>
                </div>

                <div class="rte-note" x-show="uploading > 0" x-cloak>Загружаем картинку…</div>
                <div class="rte-note err" x-show="uploadError" x-text="uploadError" x-cloak></div>

                <div class="rte-ed" x-ref="ed"></div>
                <input type="file" x-ref="imgInput" accept="image/png,image/jpeg,image/gif" multiple style="display:none" @change="onImagePicked($event)">
            </div>

            @if($this->attachments->isNotEmpty())
                <div class="atts">
                    @foreach($this->attachments as $att)
                        <span class="att" wire:key="ca-{{ $att->id }}">
                            {{ \Illuminate\Support\Str::limit($att->display_filename, 24) }}
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
            <button class="btn" x-on:click="window.dispatchEvent(new CustomEvent('mail-editor-flush'))" wire:click="send" wire:loading.attr="disabled" wire:target="send">
                <span wire:loading.remove wire:target="send">Отправить</span>
                <span wire:loading wire:target="send">Отправка…</span>
            </button>
            <label class="lbl-file" title="Прикрепить файл">📎<input type="file" multiple wire:model="newFiles"></label>
            <button class="discard" wire:click="discard">Удалить</button>
            <span class="spacer"></span>
            <span class="save" wire:loading.flex wire:target="updatedBodyHtml,updatedSubject,updatedToRaw,updatedCcRaw,uploadAttachments"><span class="dot"></span>Сохранение…</span>
        </div>
    </div>

    {{-- Ручка изменения размера (правый нижний угол). --}}
    <div class="rgrip" x-show="!min" @pointerdown="startResize($event)" title="Потяните, чтобы изменить размер">⤡</div>
</div>
</template>
@endif
</div>
