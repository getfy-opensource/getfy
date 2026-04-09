/* Service worker for panel PWA */
self.addEventListener('fetch', function (event) {
  // Necessário para o Chrome Android considerar o app instalável como PWA (não só atalho).
  if (event.request.method !== 'GET') return;
  let url;
  try {
    url = new URL(event.request.url);
  } catch (_) {
    return;
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return;
  // Não intercepte requisições cross-origin (pixels, CDNs, gateways). Isso pode mascarar erros e quebrar scripts.
  if (url.origin !== self.location.origin) return;
  // Service worker do painel só deve atuar no painel.
  if (!url.pathname.startsWith('/painel/')) return;
  event.respondWith(
    fetch(event.request).catch(function () {
      return Response.error();
    })
  );
});

self.addEventListener('install', function () {
  self.skipWaiting();
});
self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
  if (!event.data) return;
  let payload = { title: 'Notificação', body: '', url: null };
  try {
    const data = event.data.json();
    payload = { title: data.title ?? payload.title, body: data.body ?? payload.body, url: data.url ?? null };
  } catch (_) {
    try {
      payload.body = event.data.text();
    } catch (_) {}
  }
  const icon = '/icons/notification.png';
  event.waitUntil(
    self.registration.showNotification(payload.title, {
      body: payload.body,
      icon: icon,
      badge: icon,
      tag: payload.url || 'panel-push',
      data: { url: payload.url },
    })
  );
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  const url = event.notification.data?.url;
  if (!url) return;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      for (let i = 0; i < clientList.length; i++) {
        const base = url.split('?')[0];
        if (clientList[i].url === url || clientList[i].url.startsWith(base)) {
          return clientList[i].focus();
        }
      }
      if (self.clients.openWindow) return self.clients.openWindow(url);
    })
  );
});
