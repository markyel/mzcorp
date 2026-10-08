/**
 * Адресная книга почты — для обоих композеров (почта и карточка заявки).
 *
 *   recipientField — подсказки под полем «Кому / Копия / Скрытая копия» при вводе
 *                    (Blade: <x-mail.recipient-input>), стрелки + Enter/Tab, Esc;
 *   addressBook    — окно выбора адресатов (Blade: <x-mail.address-book-modal>,
 *                    один на страницу), вкладки: недавние, мои, коллеги,
 *                    клиенты, поставщики; личные контакты добавляются и удаляются.
 *
 * Работаем с DOM-полем, а не с $wire: композер почты телепортирован в body,
 * и $wire там указывает не на тот компонент. Значение пишем в input и
 * стреляем 'input' — дальше штатный wire:model и автосохранение черновика.
 * Источник данных — AddressBookController (адреса в <meta name="address-book">).
 */

const SOURCE_LABELS = {
    recent: 'недавние',
    mine: 'мои',
    colleague: 'коллеги',
    client: 'клиент',
    supplier: 'поставщик',
};

function config() {
    const meta = document.querySelector('meta[name="address-book"]');
    if (!meta) return null;
    return {
        suggest: meta.content,
        browse: meta.dataset.browse,
        store: meta.dataset.store,
        destroy: meta.dataset.destroy, // …/contacts/0 — 0 заменяется на id
        csrf: document.querySelector('meta[name="csrf-token"]')?.content ?? '',
    };
}

async function getJson(url, params) {
    const u = new URL(url, window.location.origin);
    Object.entries(params).forEach(([k, v]) => u.searchParams.set(k, v));
    const res = await fetch(u, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
}

/** «Имя <email>» или просто email. */
export function formatRecipient(item) {
    return item.name ? `${item.name} <${item.email}>` : item.email;
}

function splitRecipients(value) {
    return value.split(/[,;\n]+/).map((s) => s.trim()).filter(Boolean);
}

function emailsIn(value) {
    return splitRecipients(value).map((part) => {
        const m = part.match(/<([^>]+)>\s*$/);
        return (m ? m[1] : part).trim().toLowerCase();
    });
}

function fireInput(input) {
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

/** Дописать адресата в поле, если его там ещё нет. @returns {boolean} добавлен ли */
export function appendRecipient(input, item) {
    if (emailsIn(input.value).includes(item.email.toLowerCase())) return false;
    const parts = splitRecipients(input.value);
    parts.push(formatRecipient(item));
    input.value = parts.join(', ');
    fireInput(input);
    return true;
}

/** Подпись источника под адресом. */
function sourceLabel(item) {
    const src = SOURCE_LABELS[item.source] ?? '';
    return item.org ? `${item.org} · ${src}` : src;
}

/* ------------------------------------------------------------------ */

function recipientField() {
    return {
        open: false,
        items: [],
        active: 0,
        seq: 0,
        timer: null,
        skipNext: false,

        label: sourceLabel,

        /** Текущий (последний) адресат в поле — то, что сейчас набирают. */
        token() {
            const v = this.$refs.input.value;
            const i = Math.max(v.lastIndexOf(','), v.lastIndexOf(';'));
            return { start: i + 1, text: v.slice(i + 1).trim() };
        },

        onFocus() {
            if (this.token().text === '') this.lookup('');
        },

        onInput() {
            if (this.skipNext) {
                this.skipNext = false;
                return;
            }
            const t = this.token().text;
            clearTimeout(this.timer);
            if (t.length < 2) {
                if (t === '') this.lookup('');
                else this.close();
                return;
            }
            this.timer = setTimeout(() => this.lookup(t), 200);
        },

        async lookup(q) {
            const cfg = config();
            if (!cfg) return;
            const mySeq = ++this.seq;
            try {
                const data = await getJson(cfg.suggest, { q });
                if (mySeq !== this.seq) return; // пришёл ответ на устаревший ввод
                const already = emailsIn(this.$refs.input.value);
                this.items = (data.items ?? []).filter((it) => !already.includes(it.email));
                this.active = 0;
                this.open = this.items.length > 0 && document.activeElement === this.$refs.input;
            } catch {
                this.close();
            }
        },

        choose(item) {
            const input = this.$refs.input;
            const { start } = this.token();
            const head = input.value.slice(0, start).replace(/\s+$/, '');
            input.value = (head ? `${head} ` : '') + formatRecipient(item) + ', ';
            this.skipNext = true;
            fireInput(input);
            this.close();
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
        },

        onKeydown(e) {
            if (!this.open || this.items.length === 0) return;
            if (e.code === 'ArrowDown') {
                e.preventDefault();
                this.active = (this.active + 1) % this.items.length;
            } else if (e.code === 'ArrowUp') {
                e.preventDefault();
                this.active = (this.active - 1 + this.items.length) % this.items.length;
            } else if (e.code === 'Enter' || e.code === 'NumpadEnter' || e.code === 'Tab') {
                e.preventDefault();
                this.choose(this.items[this.active]);
            } else if (e.code === 'Escape') {
                e.stopPropagation();
                this.close();
            }
        },

        onBlur() {
            setTimeout(() => this.close(), 150);
        },

        close() {
            this.open = false;
        },

        /** Окно адресной книги для полей этой формы письма. */
        openBook(field) {
            const group = this.$el.closest('[data-rcpt-group]') ?? document;
            window.dispatchEvent(new CustomEvent('address-book-open', { detail: { group, field } }));
        },
    };
}

/* ------------------------------------------------------------------ */

function addressBook() {
    return {
        open: false,
        group: null,
        tab: 'recent',
        q: '',
        items: [],
        loading: false,
        error: '',
        added: {},
        form: { email: '', name: '', organization: '' },
        formError: '',
        seq: 0,
        tabs: [
            { key: 'recent', label: 'Недавние' },
            { key: 'mine', label: 'Мои контакты' },
            { key: 'colleague', label: 'Коллеги' },
            { key: 'client', label: 'Клиенты' },
            { key: 'supplier', label: 'Поставщики' },
        ],
        fields: [
            { key: 'to', label: 'Кому' },
            { key: 'cc', label: 'Копия' },
            { key: 'bcc', label: 'Скрытая' },
        ],

        label: sourceLabel,

        show(detail) {
            this.group = detail?.group ?? document;
            this.added = {};
            this.error = '';
            this.open = true;
            this.load();
            this.$nextTick(() => this.$refs.search?.focus());
        },

        close() {
            this.open = false;
        },

        setTab(key) {
            this.tab = key;
            this.load();
        },

        async load() {
            const cfg = config();
            if (!cfg) return;
            const mySeq = ++this.seq;
            this.loading = true;
            this.error = '';
            try {
                const data = await getJson(cfg.browse, { source: this.tab, q: this.q.trim() });
                if (mySeq !== this.seq) return;
                this.items = data.items ?? [];
            } catch {
                if (mySeq === this.seq) this.error = 'Не удалось загрузить адреса. Попробуйте ещё раз.';
            } finally {
                if (mySeq === this.seq) this.loading = false;
            }
        },

        inputFor(field) {
            return this.group?.querySelector(`[data-rcpt="${field}"]`) ?? null;
        },

        hasField(field) {
            return this.inputFor(field) !== null;
        },

        add(item, field) {
            const input = this.inputFor(field);
            if (!input) return;
            appendRecipient(input, item);
            this.added = { ...this.added, [`${field}:${item.email}`]: true };
        },

        isAdded(item, field) {
            return !!this.added[`${field}:${item.email}`];
        },

        async request(method, url, body) {
            const cfg = config();
            const res = await fetch(url, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': cfg.csrf,
                },
                body: body ? JSON.stringify(body) : undefined,
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                const first = data.errors ? Object.values(data.errors)[0]?.[0] : null;
                throw new Error(first || data.message || 'Ошибка сохранения');
            }
            return data;
        },

        /** Сохранить в «Мои контакты» — из формы или из строки другой вкладки. */
        async saveContact(item) {
            const cfg = config();
            const payload = item
                ? { email: item.email, name: item.name ?? '', organization: item.org ?? '' }
                : { ...this.form };
            this.formError = '';
            try {
                await this.request('POST', cfg.store, payload);
                if (item) {
                    item.saved = true;
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message: `${item.email} — в «Моих контактах»`, type: 'success' } }));
                } else {
                    this.form = { email: '', name: '', organization: '' };
                    this.load();
                }
            } catch (e) {
                if (item) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { message: e.message, type: 'error' } }));
                } else {
                    this.formError = e.message;
                }
            }
        },

        async removeContact(item) {
            const cfg = config();
            try {
                await this.request('DELETE', cfg.destroy.replace(/\/0$/, `/${item.id}`));
                this.items = this.items.filter((it) => it !== item);
            } catch (e) {
                window.dispatchEvent(new CustomEvent('toast', { detail: { message: e.message, type: 'error' } }));
            }
        },
    };
}

// Регистрация — как у mailEditor (app.js): и по alpine:init, и сразу, если
// Alpine уже стартовал (порядок livewire.js и Vite-модуля не гарантирован).
const register = () => {
    if (!window.Alpine || window.__addressBookRegistered) return;
    window.Alpine.data('recipientField', recipientField);
    window.Alpine.data('addressBook', addressBook);
    window.__addressBookRegistered = true;
};
document.addEventListener('alpine:init', register);
document.addEventListener('livewire:init', register);
register();
