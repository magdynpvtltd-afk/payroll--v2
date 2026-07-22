/* TaskFlow service worker: app-shell cache + offline fallback.
   Network-first for pages so data stays fresh; cache-first for static assets. */
// v10: list-page attachment quick-view popover + single searchable "Assign to"
// (combobox native-select hidden) — both add rules to style.css. Assets are
// stale-while-revalidate, so bumping re-fetches the SHELL (incl. style.css) on
// install and pushes the new CSS to already-installed clients next visit.
// v9: added the attachment preview modal — its styles live in style.css.
// style.css is stale-while-revalidate, so a fresh client could paint the modal
// unstyled for one visit; bumping re-fetches the SHELL (incl. style.css) on
// install so already-installed clients get the modal CSS on their next visit.
// v8: icon.svg changed (red "MD" tile -> the Mag Dyn logo with a T under it).
// Assets are stale-while-revalidate, so a bump isn't strictly required to
// un-stick an edit any more — but the SHELL list is re-fetched on install, so
// bumping is what pushes the new icon out to already-installed clients on their
// next visit instead of one visit later.
const CACHE = 'taskflow-v10';
// PHP pages link style.css / app.js with an ?v=<mtime> stamp (tf_asset), and
// those stamped URLs cache themselves on first fetch. The bare 'style.css' here
// is solely for offline.html, which is static and can't stamp its own link;
// install re-fetches it on every CACHE bump, so it can't drift far.
// app.js needs no bare copy — offline.html doesn't load it.
const SHELL = ['offline.html', 'style.css', 'icon.svg', 'logo.svg', 'manifest.webmanifest'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return; // never cache POSTs (incl. share_target)

  const url = new URL(req.url);
  const isAsset = /\.(css|js|svg|png|webmanifest)$/.test(url.pathname);

  // Assets: stale-while-revalidate. Serve the cached copy for speed, but always
  // re-fetch in the background so the next load has the current file. Plain
  // cache-first pinned assets to their first-ever copy, which is how an edited
  // style.css stayed invisible until a hard reload.
  if (isAsset) {
    e.respondWith(
      caches.match(req).then((hit) => {
        const fresh = fetch(req).then((res) => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy));
          }
          return res;
        }).catch(() => hit);   // offline → whatever we have
        return hit || fresh;
      })
    );
    return;
  }

  // Pages: network-first, fall back to cache, then offline page.
  e.respondWith(
    fetch(req).then((res) => {
      const copy = res.clone();
      caches.open(CACHE).then((c) => c.put(req, copy));
      return res;
    }).catch(() => caches.match(req).then((hit) => hit || caches.match('offline.html')))
  );
});

/* ---- Push notifications ---- */

// Tell the server a notification was read/cleared so it is never shown again.
// Best-effort: same-origin so the session cookie rides along; failures ignored.
function tfMarkRead(nid) {
  if (!nid) return Promise.resolve();
  return fetch('notif_action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ nid: nid }),
    credentials: 'same-origin',
    keepalive: true,
  }).catch(() => {});
}

self.addEventListener('push', (e) => {
  let data = { title: 'TaskFlow', body: '', url: 'index.php', tag: 'taskflow', nid: 0 };
  try {
    if (e.data) data = Object.assign(data, e.data.json());
  } catch (_) {
    if (e.data) data.body = e.data.text();
  }
  e.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      tag: data.tag,                 // per-notification tag ('tf-<id>') → each event shows once
      icon: 'icon.svg',
      badge: 'icon.svg',
      data: { url: data.url, nid: data.nid || 0 },
    })
  );
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const nid = e.notification.data && e.notification.data.nid;
  const target = (e.notification.data && e.notification.data.url) || 'index.php';
  e.waitUntil((async () => {
    await tfMarkRead(nid);           // reading it clears it for good
    const wins = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const w of wins) {
      if ('focus' in w) { w.navigate(target); return w.focus(); }
    }
    if (clients.openWindow) return clients.openWindow(target);
  })());
});

// User swiped/cleared the notification without opening it → also mark read,
// so any future in-app surfacing won't pop it again.
self.addEventListener('notificationclose', (e) => {
  const nid = e.notification.data && e.notification.data.nid;
  if (nid) e.waitUntil(tfMarkRead(nid));
});
