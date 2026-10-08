/**
 * Сигнал о новой почте в любом разделе CRM.
 *
 * Опрашивает GET mail.signal (NewMailSignalService): видимая вкладка — раз в
 * 30 с, фоновая — раз в минуту. Что показывает:
 *   - число непрочитанных на пункте «Почта» левого rail и в заголовке вкладки;
 *   - бейдж на иконке приложения, если почта установлена как приложение (PWA);
 *   - тост «Новое письмо — от кого / тема» со ссылкой на письмо (кроме самой «Почты»,
 *     там список обновляется сам);
 *   - системное уведомление ОС, когда вкладка в фоне и уведомления разрешены.
 *
 * Курсор (последний id входящего) хранится в localStorage на пользователя:
 * первый заход только запоминает его, а вкладки делят его между собой, так
 * что о письме сигналит одна вкладка, а не все открытые.
 *
 * Включение уведомлений ОС — кнопка [data-mail-notify-toggle] в меню профиля.
 * В самой «Почте» — кнопка «Открыть почту отдельным окном» [data-mail-install]:
 * своё окно и значок в панели задач с числом непрочитанных (public/mail-manifest.json, sw.js).
 */

const VISIBLE_MS = 30000;
const HIDDEN_MS = 60000;
const TOAST_MS = 10000;

export function initMailSignal() {
    const meta = document.querySelector('meta[name="mail-signal"]');
    if (!meta) return;

    const url = meta.content;
    const userId = meta.dataset.user;
    const onMailPage = meta.dataset.mailPage === '1';
    const cursorKey = `mzc:mail-signal:cursor:${userId}`;
    const hintKey = `mzc:mail-signal:notify-hint:${userId}`;
    const baseTitle = document.title;

    let timer = null;
    let inflight = false;
    let enabled = true;
    let swRegistration = null;
    let installPrompt = null;

    const store = {
        get(key) {
            try { return localStorage.getItem(key); } catch { return null; }
        },
        set(key, value) {
            try { localStorage.setItem(key, value); } catch { /* приватный режим — без курсора */ }
        },
    };

    function readCursor() {
        const v = parseInt(store.get(cursorKey) ?? '', 10);
        return v > 0 ? v : null;
    }

    function schedule(ms) {
        clearTimeout(timer);
        if (!enabled) return;
        timer = setTimeout(poll, ms ?? (document.hidden ? HIDDEN_MS : VISIBLE_MS));
    }

    async function poll() {
        if (inflight || !enabled) return;
        inflight = true;
        try {
            const after = readCursor();
            const res = await fetch(after ? `${url}?after=${after}` : url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) return;
            const data = await res.json();
            if (!data.enabled) {
                enabled = false;
                hideNotifyToggle();
                return;
            }
            render(data.unread);

            // Курсор перечитываем: другая вкладка могла уже объявить эти письма.
            const current = readCursor();
            if (current === null) {
                store.set(cursorKey, String(data.cursor));
                return;
            }
            if (data.cursor > current) {
                store.set(cursorKey, String(data.cursor));
                if (after !== null && after === current) announce(data);
            }
        } catch {
            /* сеть/сессия — молча, следующий опрос догонит */
        } finally {
            inflight = false;
            schedule();
        }
    }

    function render(unread) {
        const n = Number(unread) || 0;
        const label = n > 99 ? '99+' : String(n);
        document.querySelectorAll('[data-mail-signal-badge]').forEach((el) => {
            el.textContent = label;
            el.hidden = n === 0;
            el.closest('a')?.setAttribute('title', n > 0 ? `Почта — непрочитанных: ${n}` : 'Почта');
        });
        document.title = n > 0 ? `(${label}) ${baseTitle}` : baseTitle;
        if ('setAppBadge' in navigator) {
            (n > 0 ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(() => {});
        }
    }

    function announce(data) {
        const fresh = data.fresh ?? [];
        if (fresh.length === 0) return; // всё новое уже прочитано (например, с телефона)

        const first = fresh[0];
        const count = Math.max(Number(data.fresh_count) || 0, fresh.length);
        const many = count > 1;
        const title = many ? `Новых писем: ${count}` : `Новое письмо — ${first.from}`;
        const body = many ? `Последнее — ${first.from}: ${first.subject}` : first.subject;
        const href = many ? data.inbox_url : first.url;

        if (!(onMailPage && !document.hidden)) {
            window.dispatchEvent(new CustomEvent('toast', {
                detail: { message: `${title}\n${body}`, type: 'info', href, duration: TOAST_MS },
            }));
        }

        if ((document.hidden || !document.hasFocus()) && notifyPermission() === 'granted') {
            showOsNotification(title, body, href);
        }

        maybeHintNotifications();
    }

    // ── Уведомления ОС ──────────────────────────────────────────────

    /**
     * Через service worker (public/sw.js): уведомление приходит от имени
     * установленного приложения, клик открывает письмо. Без SW — из страницы.
     */
    function showOsNotification(title, body, href) {
        const options = { body, tag: 'mzc-new-mail', icon: '/images/pwa/icon-192.png', data: { url: href } };
        const fromPage = () => {
            try {
                const n = new Notification(title, options);
                n.onclick = () => {
                    window.focus();
                    window.location.href = href;
                    n.close();
                };
            } catch {
                /* браузер без конструктора Notification в странице — только тост */
            }
        };
        if (!swRegistration) {
            fromPage();
            return;
        }
        swRegistration.showNotification(title, options).catch(fromPage);
    }

    function notifyPermission() {
        return 'Notification' in window ? Notification.permission : 'unsupported';
    }

    function maybeHintNotifications() {
        if (notifyPermission() !== 'default' || store.get(hintKey)) return;
        store.set(hintKey, '1');
        window.dispatchEvent(new CustomEvent('toast', {
            detail: {
                message: 'Чтобы видеть новые письма поверх других окон, включите уведомления: меню профиля → «Включить уведомления о почте».',
                type: 'info',
                duration: TOAST_MS,
            },
        }));
    }

    function hideNotifyToggle() {
        document.querySelectorAll('[data-mail-notify-toggle]').forEach((el) => { el.hidden = true; });
    }

    function syncNotifyToggle() {
        const state = notifyPermission();
        document.querySelectorAll('[data-mail-notify-toggle]').forEach((el) => {
            el.hidden = state === 'unsupported';
            el.textContent = {
                default: 'Включить уведомления о почте',
                granted: 'Уведомления о почте включены',
                denied: 'Уведомления о почте запрещены в браузере',
            }[state] ?? '';
            el.title = state === 'denied'
                ? 'Разрешите уведомления для этого сайта в настройках браузера (значок слева от адреса)'
                : '';
        });
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-mail-notify-toggle]');
        if (!btn) return;
        e.preventDefault();
        if (notifyPermission() !== 'default') return;
        Notification.requestPermission().then(syncNotifyToggle).catch(() => {});
    });

    // ── Почта как приложение (PWA, public/mail-manifest.json) ──────────

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/sw.js')
            .then((reg) => { swRegistration = reg; })
            .catch(() => { /* без SW уведомления идут из страницы, установка недоступна */ });
    }

    // Кнопка «Открыть почту отдельным окном» в «Почте» ([data-mail-install-box]).
    // Видна везде, кроме самого окна приложения и браузера, где оно уже
    // установлено. Браузер готов установить сам — системный диалог установки;
    // не готов (Яндекс.Браузер, уже установлено, Firefox) — подсказка, где это в меню.
    const installedKey = `mzc:mail-app:installed:${userId}`;
    const isInstalledApp = () => window.matchMedia('(display-mode: standalone)').matches;

    function syncInstallButton() {
        const hide = isInstalledApp() || store.get(installedKey) === '1';
        document.querySelectorAll('[data-mail-install-box]').forEach((el) => { el.hidden = hide; });
    }

    function markInstalled() {
        store.set(installedKey, '1');
        installPrompt = null;
        syncInstallButton();
    }

    function installHint() {
        const ua = navigator.userAgent;
        const how = /YaBrowser/.test(ua)
            ? 'нажмите значок установки справа в адресной строке'
            : /Edg\//.test(ua)
                ? 'меню «…» → «Приложения» → «Установить этот сайт как приложение»'
                : /Firefox\//.test(ua)
                    ? 'Firefox не умеет устанавливать сайты — откройте CRM в Chrome, Edge или Яндекс.Браузере'
                    : 'значок «монитор со стрелкой» справа в адресной строке или меню ⋮ → «Трансляция, сохранение и отправка» → «Установить страницу как приложение»';
        window.dispatchEvent(new CustomEvent('toast', {
            detail: {
                message: `Чтобы открыть почту отдельным окном: ${how}. Если уже установлено — запустите «mzCorp Почта» из меню «Пуск» или панели задач.`,
                type: 'info',
                duration: 15000,
            },
        }));
    }

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        installPrompt = e;
        store.set(installedKey, '0'); // браузер предлагает установку — значит, приложения сейчас нет
        syncInstallButton();
    });
    window.addEventListener('appinstalled', markInstalled);
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-mail-install]');
        if (!btn) return;
        e.preventDefault();
        if (!installPrompt) {
            installHint();
            return;
        }
        installPrompt.prompt();
        installPrompt.userChoice
            .then((choice) => { if (choice.outcome === 'accepted') markInstalled(); })
            .finally(() => { installPrompt = null; });
    });
    syncInstallButton();

    // ── Запуск ──────────────────────────────────────────────────────

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) schedule(0);
    });
    // Прочтение в «Почте» (Mail\Client::forgetUnreadBadges) — пересчитать сразу.
    window.addEventListener('mail-unread-changed', () => schedule(300));

    syncNotifyToggle();
    poll();
}
