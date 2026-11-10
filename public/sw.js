// Service worker: installable app + phone notifications (Web Push).
// No offline cache: business data must always come fresh from the server.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: event.data ? event.data.text() : '' }; }
    event.waitUntil(self.registration.showNotification(data.title || 'PNEUMATIQUE MOUHIM', {
        body: data.body || '',
        icon: '/pwa/icon-192.png',
        badge: '/pwa/icon-192.png',
        data: { url: data.url || '/admin' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/admin';
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
        for (const client of list) { if ('focus' in client) { client.navigate(url); return client.focus(); } }
        return self.clients.openWindow(url);
    }));
});
