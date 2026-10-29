@props([
    /** id Livewire-компонента, в чей bodyHtml пишет редактор ($this->getId()). */
    'wireId',
    'html' => '',
    /** POST-адрес картинки в тело письма; null — картинки недоступны («черновик ещё не создан»). */
    'uploadUrl' => null,
    'placeholder' => 'Ваш ответ…',
    /** wire:key — при открытии другого письма редактор пересоздаётся с его HTML. */
    'editorKey',
])
{{--
    Богатый редактор письма (TipTap, resources/js/mail-editor.js): заголовки, начертания, цвет,
    выравнивание, списки, цитата, ссылки, таблицы, картинки в тексте. Один и тот же в композере
    почты (livewire.mail.composer) и в ответе поддержки QuoteParts (livewire.quoteparts.support-requests).
    Слот $tools — свои кнопки в конце панели (меню шаблонов).
    Под wire:ignore (иначе Livewire morph сбивал бы курсор); синхронизация в $wire.bodyHtml с debounce,
    перед отправкой — window-событие mail-editor-flush; новое тело с сервера — mail-editor-set-html.
--}}
@once
<style>
.mail-rte{flex:1 1 auto;display:flex;flex-direction:column;min-height:120px}
/* Панель в духе TipTap Simple Editor: иконки без рамок, группы через тонкие разделители. */
.mail-rte .rte-toolbar{display:flex;align-items:center;flex-wrap:wrap;gap:1px;padding:2px 0 6px;margin-bottom:8px;border-bottom:1px solid var(--border-subtle);flex:0 0 auto}
.mail-rte .rte-toolbar button,.mail-rte .rte-tablebar button{width:30px;height:30px;padding:0;border:none;background:transparent;border-radius:6px;cursor:pointer;color:var(--fg-2);display:inline-flex;align-items:center;justify-content:center;line-height:1;white-space:nowrap;transition:background .12s,color .12s}
.mail-rte .rte-toolbar button:hover,.mail-rte .rte-tablebar button:hover{background:var(--bg-hover);color:var(--fg-1)}
.mail-rte .rte-toolbar button.on,.mail-rte .rte-tablebar button.on{background:var(--accent-bg);color:var(--accent)}
.mail-rte .rte-toolbar button[disabled]{opacity:.35;cursor:default;background:transparent}
.mail-rte .rte-toolbar button.dd,.mail-rte .rte-tablebar button.dd{width:auto;padding:0 8px;gap:5px;font:500 12.5px/1 var(--font-sans)}
.mail-rte .rte-toolbar button.add{color:var(--fg-2)}
.mail-rte .rte-ico{width:16px;height:16px;flex:0 0 auto}
.mail-rte .rte-ico.sm{width:13px;height:13px;opacity:.7}
.mail-rte .rte-toolbar .sep,.mail-rte .rte-tablebar .sep{width:1px;height:20px;background:var(--border);margin:0 6px;flex:0 0 auto}
.mail-rte .rte-toolbar .clr-a{font:700 14px/1 var(--font-sans);border-bottom:3px solid var(--accent);padding:0 1px 1px}
.mail-rte .rte-pop{position:relative;display:inline-flex}
.mail-rte .rte-popover,.mail-rte .rte-menu{position:absolute;top:34px;left:0;z-index:5;background:var(--bg-surface);border:1px solid var(--border);border-radius:8px;box-shadow:0 10px 30px rgba(15,23,42,.16);padding:6px;display:flex;align-items:center;gap:4px;font-size:12.5px;white-space:nowrap}
.mail-rte .rte-menu{flex-direction:column;align-items:stretch;min-width:170px;padding:4px}
.mail-rte .rte-menu button{width:100%;height:30px;justify-content:flex-start;padding:0 10px;font:400 13px/1 var(--font-sans);color:var(--fg-1);border-radius:5px}
.mail-rte .rte-menu button.h2{font-weight:600;font-size:15px}
.mail-rte .rte-menu button.h3{font-weight:600;font-size:13.5px}
/* Меню шаблонов писем и подпанель «сохранить как шаблон». */
.mail-rte .tplmenu{left:auto;right:0;min-width:270px;max-width:320px}
.mail-rte .tplmenu .tpltree{max-height:240px;overflow-y:auto}
.mail-rte .tplmenu .tplfolder{padding:4px 6px;font:600 12px/1.4 var(--font-sans);color:var(--fg-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mail-rte .tplmenu button.tplitem{height:28px;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.mail-rte .tplmenu .tplhint{padding:8px 6px;font:400 12px/1.4 var(--font-sans);color:var(--fg-3);white-space:normal}
.mail-rte .tplmenu .tplsep{height:1px;background:var(--border-subtle);margin:4px 2px}
.mail-rte .tplmenu .tpllink{display:block;padding:7px 10px;font:400 12.5px/1 var(--font-sans);color:var(--sky-700);text-decoration:none;border-radius:5px}
.mail-rte .tplmenu .tpllink:hover{background:var(--bg-hover)}
.mail-rte .tplmenu .tplsave{display:flex;flex-direction:column;gap:6px;padding:4px}
.mail-rte .tplmenu .tplsave input,.mail-rte .tplmenu .tplsave select{height:30px;border:1px solid var(--border);border-radius:6px;padding:0 8px;font:400 12.5px/1 var(--font-sans);background:var(--bg-surface);color:var(--fg-1);outline:none}
.mail-rte .tplmenu .tplbtns{display:flex;gap:6px}
.mail-rte .tplmenu .tplbtns button{height:28px;width:auto;padding:0 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg-surface)}
.mail-rte .tplmenu .tplbtns button.prim{background:var(--accent);border-color:var(--accent);color:var(--fg-on-accent);font-weight:600}
.mail-rte .rte-popover input[type=text]{width:260px;height:30px;border:1px solid var(--border);border-radius:6px;padding:0 8px;font:400 12.5px/1 var(--font-mono);color:var(--fg-1);background:var(--bg-surface);outline:none}
.mail-rte .rte-popover input[type=text]:focus{border-color:var(--sky-500)}
.mail-rte .rte-popover input[type=number]{width:54px;height:30px;border:1px solid var(--border);border-radius:6px;padding:0 6px;font:400 12.5px/1 var(--font-sans);color:var(--fg-1);background:var(--bg-surface);margin-left:6px;outline:none}
.mail-rte .rte-popover label{color:var(--fg-2);display:inline-flex;align-items:center;margin-right:4px}
.mail-rte .rte-popover .ok{background:var(--accent);color:#fff}
.mail-rte .rte-popover .ok:hover{background:var(--accent-600);color:#fff}
.mail-rte .rte-popover .rm{color:var(--fg-3)}
.mail-rte .rte-colors{padding:8px}
.mail-rte .rte-colors .swatch{width:22px;height:22px;min-width:22px;border-radius:999px;border:1px solid rgba(0,0,0,.12);padding:0}
.mail-rte .rte-colors .swatch:hover{transform:scale(1.12)}
.mail-rte .rte-colors .swatch.none{background:var(--bg-surface);color:var(--fg-3)}
.mail-rte .rte-tablebar{display:flex;align-items:center;flex-wrap:wrap;gap:1px;padding:0 0 6px;margin-bottom:6px;border-bottom:1px dashed var(--border-subtle);flex:0 0 auto}
.mail-rte .rte-tablebar .lbl{font:500 11.5px/1 var(--font-sans);color:var(--fg-3);margin:0 6px 0 2px;text-transform:uppercase;letter-spacing:.04em}
.mail-rte .rte-tablebar .danger{color:var(--red-700)}
.mail-rte .rte-tablebar .danger:hover{background:var(--red-50);color:var(--red-700)}
.mail-rte .rte-note{font:400 11.5px/1.3 var(--font-sans);color:var(--fg-3);padding:0 0 6px}
.mail-rte .rte-note.err{color:var(--red-700)}
.mail-rte .rte-ed{flex:1 1 auto;min-height:100px;font:400 13.5px/1.6 var(--font-sans);color:var(--fg-1);overflow-y:auto;word-break:break-word;display:flex;flex-direction:column}
.mail-rte .rte-ed .ProseMirror{outline:none;flex:1 1 auto;min-height:100px}
.mail-rte .rte-ed .ProseMirror p{margin:0 0 6px}
.mail-rte .rte-ed .ProseMirror p.is-editor-empty:first-child::before{content:attr(data-placeholder);color:var(--fg-4);float:left;height:0;pointer-events:none}
.mail-rte .rte-ed h2{font:600 17px/1.3 var(--font-sans);margin:10px 0 6px}
.mail-rte .rte-ed h3{font:600 14.5px/1.3 var(--font-sans);margin:8px 0 4px}
.mail-rte .rte-ed a{color:var(--sky-700);text-decoration:underline}
.mail-rte .rte-ed ul{list-style:disc outside;margin:4px 0;padding-left:24px}
.mail-rte .rte-ed ol{list-style:decimal outside;margin:4px 0;padding-left:24px}
.mail-rte .rte-ed li{margin:2px 0}
.mail-rte .rte-ed blockquote{margin:6px 0;padding-left:12px;border-left:2px solid var(--border-strong);color:var(--fg-2)}
.mail-rte .rte-ed hr{border:none;border-top:1px solid var(--border);margin:10px 0}
.mail-rte .rte-ed img{max-width:100%;height:auto;display:block;margin:6px 0;border-radius:2px}
.mail-rte .rte-ed img.ProseMirror-selectednode{outline:2px solid var(--sky-500)}
.mail-rte .rte-ed table{border-collapse:collapse;table-layout:fixed;width:100%;margin:6px 0;overflow:hidden}
.mail-rte .rte-ed td,.mail-rte .rte-ed th{border:1px solid var(--border);padding:4px 8px;min-width:40px;vertical-align:top;position:relative}
.mail-rte .rte-ed th{background:var(--bg-surface-2);font-weight:600;text-align:left}
.mail-rte .rte-ed td p,.mail-rte .rte-ed th p{margin:0}
.mail-rte .rte-ed .selectedCell:after{content:'';position:absolute;inset:0;background:rgba(14,165,233,.12);pointer-events:none}
.mail-rte .rte-ed .ProseMirror-gapcursor:after{border-top:1px solid var(--fg-1)}
</style>
@endonce
<div {{ $attributes->merge(['class' => 'rte mail-rte']) }} wire:ignore wire:key="{{ $editorKey }}"
     x-data="mailEditor({
         wireId: @js($wireId),
         initialHtml: @js($html),
         uploadUrl: @js($uploadUrl),
         csrf: @js(csrf_token()),
         placeholder: @js($placeholder),
     })"
     @mail-editor-flush.window="flush()"
     {{-- Вставка шаблона / перевод меняют bodyHtml на сервере — редактор
          живёт под wire:ignore и сам об этом не узнает. --}}
     @mail-editor-set-html.window="setContent($event.detail.html)"
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
        @isset($tools)
            <span class="sep"></span>
            {{ $tools }}
        @endisset
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
