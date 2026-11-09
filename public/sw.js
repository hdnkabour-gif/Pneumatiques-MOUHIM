// Minimal service worker: makes the app installable on the phone home screen.
// No offline cache: business data must always come fresh from the server.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
self.addEventListener('fetch', () => {});
