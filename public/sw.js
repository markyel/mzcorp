/*
 * Service worker приложения «mzCorp Почта» (public/mail.webmanifest).
 *
 * Ничего не кэширует и запросы не перехватывает: данные CRM всегда свежие
 * с сервера, без офлайна. Нужен для установки почты как приложения и для
 * клика по системному уведомлению — фокус на открытое окно или новое окно
 * на письме (уведомления создаёт resources/js/mail-signal.js).
 */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/dashboard/mail/inbox', self.location.origin).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        const client = windows.find((c) => new URL(c.url).origin === self.location.origin);
        if (client) {
            await client.focus();
            return client.navigate(url);
        }
        return self.clients.openWindow(url);
    })());
});
