// Register the service worker for PWA/offline + push behaviour.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('service-worker.js')
      .then(() => tfPushInit())
      .catch(() => {});
  });
}

/* ---------------- Web Push ---------------- */
function tfB64ToUint8(b64) {
  const pad = '='.repeat((4 - (b64.length % 4)) % 4);
  const s = (b64 + pad).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(s);
  const out = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
  return out;
}

function tfPushSupported() {
  return 'serviceWorker' in navigator && 'PushManager' in window &&
    window.TF && window.TF.pushReady && window.TF.vapidPublic;
}

async function tfSubscribe() {
  if (!tfPushSupported()) return { ok: false, reason: 'unsupported' };
  const perm = await Notification.requestPermission();
  if (perm !== 'granted') return { ok: false, reason: 'denied' };
  const reg = await navigator.serviceWorker.ready;
  let sub = await reg.pushManager.getSubscription();
  if (!sub) {
    sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: tfB64ToUint8(window.TF.vapidPublic),
    });
  }
  const res = await fetch('push_subscribe.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'subscribe', csrf: window.TF.csrf, subscription: sub }),
  });
  const j = await res.json().catch(() => ({}));
  return { ok: !!j.ok };
}

/* Rewrite the bell's icon + label.
   The button is two spans (icon, label) rather than a text node, because it
   collapses to the icon and slides the label out on hover — so a plain
   btn.textContent = '...' would replace both spans with a bare string and the
   bell would vanish. Callers that set a STATUS (blocked / try again) also pin
   the label open with .push-open: a message nobody hovers is a message nobody
   reads. */
function tfPushLabel(btn, icon, text) {
  const i = btn.querySelector('.push-ico');
  const l = btn.querySelector('.push-lbl');
  if (i) i.textContent = icon;
  if (l) l.textContent = text;
  btn.setAttribute('aria-label', text);
  btn.title = text;
}

function tfPushInit() {
  const btn = document.getElementById('enable-push');
  if (!tfPushSupported()) { if (btn) btn.hidden = true; return; }

  // Already granted: make sure the server has this device's subscription, silently.
  if (Notification.permission === 'granted') {
    tfSubscribe().catch(() => {});
    if (btn) btn.hidden = true;
    return;
  }
  // Otherwise show the opt-in button (unless the user blocked notifications).
  if (btn && Notification.permission === 'default') {
    btn.hidden = false;
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      const r = await tfSubscribe().catch(() => ({ ok: false }));
      btn.disabled = false;
      if (r.ok) { btn.hidden = true; return; }
      if (r.reason === 'denied') tfPushLabel(btn, '🔕', 'Notifications blocked');
      else tfPushLabel(btn, '🔔', 'Try again');
      btn.classList.add('push-open');
    });
  }
}

/* ---------------- Profile menu ----------------
   The menu is a native <details>, so it opens and closes with no JS at all
   (and keeps working offline). These two only add what <details> lacks:
   dismissing it by clicking away or pressing Escape. */
document.addEventListener('click', (ev) => {
  document.querySelectorAll('details.usermenu[open]').forEach((d) => {
    if (!d.contains(ev.target)) d.open = false;
  });
});
document.addEventListener('keydown', (ev) => {
  if (ev.key !== 'Escape') return;
  document.querySelectorAll('details.usermenu[open]').forEach((d) => { d.open = false; });
});

/* ---------------- Keyboard shortcuts ----------------
   Alt+N  new task (anywhere)
   Alt+S  save the task form
   Bound in JS rather than via accesskey= because browsers disagree on the
   modifier (Firefox uses Alt+Shift), and these need to be exactly Alt+key.
   window.TF only exists for a signed-in page, so this stays off the login screen. */
document.addEventListener('keydown', (ev) => {
  // altKey alone: on many layouts AltGr reports as Ctrl+Alt, so a bare Ctrl or
  // Meta means the user is typing a character, not reaching for a shortcut.
  if (!ev.altKey || ev.ctrlKey || ev.metaKey || ev.shiftKey) return;
  if (!window.TF) return;

  // ev.code (physical key) not ev.key — with Alt held, some layouts report a
  // dead key or an accented character in ev.key.
  if (ev.code === 'KeyN') {
    ev.preventDefault();
    window.location.href = 'task_form.php';
    return;
  }

  if (ev.code === 'KeyS') {
    const form = document.querySelector('form[data-hotkey-save]');
    if (!form) return;                  // no task form on this page — let Alt+S through
    ev.preventDefault();
    // requestSubmit() runs "required" validation and fires submit handlers,
    // exactly as clicking the button does; form.submit() would skip both.
    if (form.requestSubmit) form.requestSubmit();
    else form.querySelector('[type="submit"]').click();
  }
});

/* ------ WhatsApp import picker: keep the radio choice in sync ------ */
document.addEventListener('change', (ev) => {
  const t = ev.target;
  if (t && t.name === 'task_id') {
    const r = document.querySelector('input[name="mode"][value="existing"]');
    if (r) r.checked = true;
  }
});
document.addEventListener('input', (ev) => {
  const t = ev.target;
  if (t && t.name === 'title') {
    const r = document.querySelector('input[name="mode"][value="new"]');
    if (r) r.checked = true;
  }
});
