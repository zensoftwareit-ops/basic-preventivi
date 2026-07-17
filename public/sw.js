const ICON = 'assets/icons/icon-192.png';
const BADGE = 'assets/icons/badge-96.png';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (error) {
    payload = { title: 'Basic Preventivi', body: event.data?.text() || 'Nuova scadenza da controllare.' };
  }
  const title = payload.title || 'Basic Preventivi';
  const options = {
    body: payload.body || 'Nuova scadenza da controllare.',
    icon: payload.icon || ICON,
    badge: payload.badge || BADGE,
    tag: payload.tag || 'basic-preventivi',
    renotify: true,
    data: { url: payload.url || 'index.php' },
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL(event.notification.data?.url || 'index.php', self.location.href).href;
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of windows) {
      if ('navigate' in client) {
        await client.navigate(target);
      }
      if ('focus' in client) {
        return client.focus();
      }
    }
    return self.clients.openWindow(target);
  })());
});
