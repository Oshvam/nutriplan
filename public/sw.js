/* NutriPlan service worker: estáticos con caché, HTML stale-while-revalidate,
   API siempre en red y push propias. */
const CACHE = 'nutriplan-v4';
const SHELL = ['/', '/manifest.webmanifest', '/icons/icon-192.png', '/icons/icon-512.png'];

const OFFLINE_HTML = `<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>Sin conexión · NutriPlan</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f8fafc;color:#0f172a;
font:16px/1.5 system-ui,sans-serif;text-align:center;padding:24px}div{max-width:320px}
h1{font-size:20px;margin:0 0 8px}p{margin:0;color:#64748b}a{display:inline-block;margin-top:16px;padding:10px 18px;
border-radius:999px;background:#059669;color:#fff;text-decoration:none;font-weight:700}</style></head>
<body><div><h1>Sin conexión</h1><p>No hemos podido cargar NutriPlan. Revisa tu conexión y vuelve a intentarlo.</p>
<a href="/">Reintentar</a></div></body></html>`;

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => Promise.all(SHELL.map((url) => cache.add(url).catch(() => {}))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Solo mismo origen y GET. La API (datos autenticados) nunca se cachea.
  if (request.method !== 'GET' || url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/api/')) return;

  // Estáticos con nombre versionado: caché primero.
  if (/^\/(assets|icons)\//.test(url.pathname)) {
    event.respondWith(
      caches.match(request).then((hit) => {
        if (hit) return hit;
        return fetch(request).then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then((cache) => cache.put(request, copy));
          }
          return res;
        });
      })
    );
    return;
  }

  // HTML y navegación: sirve lo cacheado al instante y refresca en segundo plano.
  const isHtml = request.mode === 'navigate' || (request.headers.get('accept') || '').includes('text/html');
  if (isHtml) {
    event.respondWith(
      (async () => {
        const cache = await caches.open(CACHE);
        const cached = await cache.match(request);
        const network = fetch(request)
          .then((res) => {
            if (res.ok) cache.put(request, res.clone());
            return res;
          })
          .catch(() => null);
        const res = cached || (await network);
        if (res) return res;
        return new Response(OFFLINE_HTML, {
          status: 503,
          headers: { 'Content-Type': 'text/html; charset=utf-8' },
        });
      })()
    );
    return;
  }

  // Resto: red con respaldo en caché.
  event.respondWith(fetch(request).catch(() => caches.match(request)));
});

// Push Web Push (cifradas por el propio servidor, sin terceros).
self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    try {
      data = { body: event.data.text() };
    } catch {
      data = {};
    }
  }
  const title = data.title || 'NutriPlan';
  const options = {
    body: data.body || 'Tienes un aviso de NutriPlan',
    icon: '/icons/icon-512.png',
    badge: '/icons/icon-512.png',
    data: { url: data.url || '/', ts: data.ts || Date.now() },
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      for (const w of windows) {
        if (new URL(w.url).pathname === new URL(url, self.location.origin).pathname) return w.focus();
      }
      return self.clients.openWindow(url);
    })
  );
});
