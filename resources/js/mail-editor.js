/**
 * Богатый редактор письма (TipTap/ProseMirror) для почтового клиента.
 *
 * Alpine-компонент `mailEditor({...})`. Возможности: заголовки, жирный/курсив/
 * подчёркнутый/зачёркнутый, цвет текста, выравнивание, списки, цитата,
 * ссылки, таблицы (вставка, строки/столбцы, удаление), картинки в теле
 * письма (кнопка, вставка из буфера, drag&drop) — загружаются на сервер как
 * inline-вложения черновика и попадают в письмо как cid:, отмена/повтор.
 *
 * HTML синхронизируется в Livewire-свойство `bodyHtml` с debounce; перед
 * отправкой вызывается flush() (см. composer.blade.php).
 */
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import Table from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableCell from '@tiptap/extension-table-cell';
import TableHeader from '@tiptap/extension-table-header';
import TextAlign from '@tiptap/extension-text-align';
import TextStyle from '@tiptap/extension-text-style';
import { Color } from '@tiptap/extension-color';
import Placeholder from '@tiptap/extension-placeholder';

const IMAGE_TYPES = ['image/png', 'image/jpeg', 'image/gif'];

export default function mailEditor(opts = {}) {
    // Экземпляр TipTap держим в замыкании, а НЕ в состоянии Alpine: Alpine
    // оборачивает данные в глубокий reactive-Proxy, и ProseMirror внутри него
    // зависает (каждая транзакция трогает тысячи проксируемых объектов).
    let editor = null;

    return {
        tick: 0,
        uploading: 0,
        uploadError: '',
        linkOpen: false,
        linkUrl: '',
        tableOpen: false,
        tableRows: 3,
        tableCols: 3,
        colorOpen: false,
        colors: ['#0f1419', '#d32027', '#b45309', '#15803d', '#1d4ed8', '#6b21a8', '#64748b'],
        _last: null,
        _timer: null,

        init() {
            editor = new Editor({
                element: this.$refs.ed,
                extensions: [
                    StarterKit.configure({
                        heading: { levels: [2, 3] },
                        codeBlock: false,
                        code: false,
                    }),
                    Underline,
                    Link.configure({ openOnClick: false, autolink: true, linkOnPaste: true, defaultProtocol: 'https' }),
                    Image.configure({ inline: false, allowBase64: false }),
                    Table.configure({ resizable: false, HTMLAttributes: { class: 'mail-table' } }),
                    TableRow,
                    TableHeader,
                    TableCell,
                    TextAlign.configure({ types: ['heading', 'paragraph'] }),
                    TextStyle,
                    Color,
                    Placeholder.configure({ placeholder: opts.placeholder || 'Ваш ответ…' }),
                ],
                content: opts.initialHtml || '',
                editorProps: {
                    attributes: { class: 'rte-pm' },
                    handlePaste: (_view, event) => this.handleFiles(event.clipboardData?.files),
                    handleDrop: (_view, event) => this.handleFiles(event.dataTransfer?.files),
                },
                onUpdate: () => { this.tick++; this.scheduleSync(); },
                onSelectionUpdate: () => { this.tick++; },
                onTransaction: () => { this.tick++; },
            });
            this._last = editor.getHTML();
        },

        destroy() {
            clearTimeout(this._timer);
            editor?.destroy();
            editor = null;
        },

        /* ---------- синхронизация с Livewire ---------- */

        scheduleSync() {
            clearTimeout(this._timer);
            this._timer = setTimeout(() => this.sync(), 800);
        },

        sync() {
            if (! editor) return;
            const html = editor.isEmpty ? '' : editor.getHTML();
            if (html === this._last) return;
            this._last = html;
            this.$wire.set('bodyHtml', html);
        },

        flush() {
            clearTimeout(this._timer);
            this.sync();
        },

        /** Подставить HTML извне (шаблон, открытие другого черновика). */
        setContent(html) {
            if (! editor) return;
            editor.commands.setContent(html || '', false);
            this._last = editor.getHTML();
        },

        /* ---------- состояние кнопок ---------- */

        is(name, attrs) {
            this.tick; // зависимость для реактивности
            return editor ? editor.isActive(name, attrs) : false;
        },

        can(cmd) {
            this.tick;
            if (! editor) return false;
            try { return editor.can()[cmd](); } catch (e) { return false; }
        },

        run(fn) {
            if (! editor) return;
            fn(editor.chain().focus()).run();
            this.tick++;
            this.scheduleSync();
        },

        /* ---------- форматирование ---------- */

        setBlock(value) {
            if (value === 'p') this.run(c => c.setParagraph());
            else this.run(c => c.toggleHeading({ level: Number(value) }));
        },

        blockValue() {
            this.tick;
            if (! editor) return 'p';
            if (editor.isActive('heading', { level: 2 })) return '2';
            if (editor.isActive('heading', { level: 3 })) return '3';
            return 'p';
        },

        setColor(c) {
            this.colorOpen = false;
            if (c === null) this.run(ch => ch.unsetColor());
            else this.run(ch => ch.setColor(c));
        },

        clearFormat() {
            this.run(c => c.clearNodes().unsetAllMarks());
        },

        /* ---------- ссылки ---------- */

        openLink() {
            const prev = editor?.getAttributes('link')?.href || '';
            this.linkUrl = prev || 'https://';
            this.linkOpen = true;
            this.$nextTick(() => this.$refs.linkInput?.focus());
        },

        applyLink() {
            const url = (this.linkUrl || '').trim();
            this.linkOpen = false;
            if (url === '' || url === 'https://') {
                this.run(c => c.extendMarkRange('link').unsetLink());
                return;
            }
            const href = /^(https?:|mailto:)/i.test(url) ? url : 'https://' + url;
            this.run(c => c.extendMarkRange('link').setLink({ href }));
        },

        removeLink() {
            this.linkOpen = false;
            this.run(c => c.extendMarkRange('link').unsetLink());
        },

        /* ---------- таблицы ---------- */

        insertTable() {
            const rows = Math.min(Math.max(1, Number(this.tableRows) || 3), 30);
            const cols = Math.min(Math.max(1, Number(this.tableCols) || 3), 12);
            this.tableOpen = false;
            this.run(c => c.insertTable({ rows, cols, withHeaderRow: true }));
        },

        /* ---------- картинки ---------- */

        pickImage() {
            this.$refs.imgInput?.click();
        },

        onImagePicked(event) {
            const files = event.target.files;
            this.handleFiles(files);
            event.target.value = '';
        },

        /** Возвращает true, если среди файлов были картинки (событие обработано). */
        handleFiles(files) {
            if (! files || files.length === 0) return false;
            const images = Array.from(files).filter(f => IMAGE_TYPES.includes(f.type));
            if (images.length === 0) return false;
            images.forEach(f => this.uploadImage(f));
            return true;
        },

        async uploadImage(file) {
            if (! opts.uploadUrl) {
                this.uploadError = 'Черновик ещё не создан — попробуйте через секунду.';
                return;
            }
            if (file.size > (opts.maxBytes || 10 * 1024 * 1024)) {
                this.uploadError = `Картинка «${file.name}» больше 10 МБ.`;
                return;
            }
            this.uploadError = '';
            this.uploading++;
            try {
                const fd = new FormData();
                fd.append('image', file, file.name || 'image');
                const res = await window.axios.post(opts.uploadUrl, fd, {
                    headers: { 'X-CSRF-TOKEN': opts.csrf || '' },
                });
                const url = res?.data?.url;
                if (url) {
                    this.run(c => c.setImage({ src: url, alt: file.name || '' }));
                    this.flush();
                }
            } catch (e) {
                const msg = e?.response?.data?.message || e?.message || 'не удалось загрузить';
                this.uploadError = `Картинка не загружена: ${msg}`;
            } finally {
                this.uploading--;
            }
        },
    };
}
