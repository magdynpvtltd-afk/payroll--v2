# TaskFlow

A mobile-first task-management PWA in plain PHP + MySQL. Users log in, assign
tasks to each other, comment with attachments, and share/import attachments via
WhatsApp. No framework, no Composer — drop the files on any PHP host and go.

## Features

- **Login & sessions** — secure cookies, hashed passwords (`password_hash`), CSRF protection on every form.
- **User administration** (admin only) — create users, set role (admin/user), disable/enable, reset passwords.
- **Tasks** — create, describe, set priority & due date, and **assign to another user**.
- **Permission rule** — only the user who *assigned* the task or the user it's *assigned to* can update its status or finish it (admins may also manage). Enforced server-side on every action.
- **Comments with attachments** — post updates and attach photos, docs, PDFs, audio, video, or CAD/3D models (DXF, DWG, STL, OBJ, STEP, IGES, 3DS…).
- **In-app file preview** — clicking an attachment opens it in an overlay instead of downloading, reusing MagDyn's Running-Notes preview: PDFs/images/text render inline, CAD/3D models open in the shared `../cad_viewer.php` (WebGL). See `tf_attachment_preview_assets()` in `uploads.php`.
- **WhatsApp share** — one tap sends a task or an attachment link to WhatsApp (`wa.me`), pre-addressed to the other participant when a number is on file.
- **WhatsApp import** — the app registers as a PWA *share target*: on a phone, open WhatsApp → Share a photo/file → pick **TaskFlow**, and the file is staged to attach to a task (or create one). You can also just download from WhatsApp and use the normal attach button.
- **Push notifications** — when a task is **created** or **completed**, the app sends a Web Push notification to the two people involved (the assigner and the assignee), never to anyone else. The person who performed the action isn't notified of their own action.
- **WhatsApp notifications** — the same two events are also POSTed to a WhatsApp webhook you configure, so the assigner/assignee get a WhatsApp message.
- **PWA** — installable, works offline (app shell), bottom tab bar on mobile, responsive up to desktop.

## Requirements

- PHP **8.0+** with `pdo_mysql` and `fileinfo` extensions
- MySQL 5.7+ / MariaDB 10.3+ / MySQL 8
- Apache with `mod_rewrite`-style `.htaccess` support (the included `.htaccess` sets headers and protects the uploads folder). On Nginx, replicate the rules — most importantly, deny direct access to `/uploads/`.
- **HTTPS** in production. Service workers, PWA install, and the WhatsApp share target only work over `https://` (or `http://localhost` for testing).

## Setup

1. **Copy all files** into a folder served by your web server (e.g. `htdocs/taskflow/`). Keep them together — the app is intentionally flat.
2. **Create the database.** Import the schema:
   ```
   mysql -u root -p < schema.sql
   ```
   (or paste `schema.sql` into phpMyAdmin).
3. **Set your DB credentials** at the top of `db.php` (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`).
4. **Open `install.php`** in your browser and create the first administrator account.
5. Delete `install.php` (optional but recommended), then go to `login.php`.
6. The `uploads/` folder is created automatically on first use and self-protected with its own `.htaccess`. Ensure PHP can write to the app folder.

## Install on a phone

Open the site in Chrome/Edge (Android) or Safari (iOS) over HTTPS, then "Add to
Home Screen". Launched from the home screen it runs full-screen. On Android it
will then appear in WhatsApp's share sheet for importing files.

## Push & WhatsApp notifications

Notifications fire on exactly two events — **task created** and **task completed** — and go only to the task's assigner and assignee (minus whoever triggered it). Both are optional; if unconfigured, the app simply skips them.

**New database table.** For an existing install, run once:

```
mysql -u root -p taskflow < migrate_push.sql
```

(The bundled `schema.sql` already includes it for fresh installs.)

### Web Push (browser/PWA notifications)

1. Open `genvapid.php` in your browser once. It generates a VAPID key pair and prints three constants.
2. Paste them over the matching `VAPID_*` lines near the top of `db.php`, and set `VAPID_SUBJECT` to your real `mailto:` address.
3. Set `APP_URL` in `db.php` to your site's public URL (used for links inside notifications).
4. Delete `genvapid.php`.
5. Users click the **🔔** bell at the top right (hover it to see the full "Enable notifications" label) once per device and accept the browser prompt. Requires HTTPS.

Web Push encryption (VAPID + aes128gcm) is implemented in `webpush.php` using only PHP's `openssl` + `hash_hkdf` — no Composer packages. Needs PHP 7.3+ with the openssl extension.

### WhatsApp (webhook)

Set `WHATSAPP_WEBHOOK_URL` (and optionally `WHATSAPP_WEBHOOK_TOKEN`) in `db.php`. For each notification, TaskFlow makes a `POST` to that URL with a JSON body:

```json
{
  "event": "task_created",
  "to": "919876543210",
  "to_name": "Asha",
  "task_id": 42,
  "task_title": "Ship the invoice",
  "task_url": "https://tasks.example.com/task_view.php?id=42",
  "actor": "Ravi",
  "message": "📋 New task from Ravi: Ship the invoice\nhttps://tasks.example.com/task_view.php?id=42"
}
```

`event` is `task_created` or `task_completed`; `to` is the recipient's phone (digits only, from their profile). Point the URL at your own gateway (n8n, a cloud function, a self-hosted WhatsApp bridge, etc.) which forwards `message` to `to` over WhatsApp. If the token is set, it's sent as `Authorization: Bearer <token>` so your endpoint can verify the call. Recipients only get a WhatsApp message if they have a phone number saved on their user profile.

## File map

| File | Purpose |
|------|---------|
| `db.php` | Config, PDO connection, session, CSRF, auth & permission helpers |
| `uploads.php` | Attachment validation/storage helpers + the attachment preview modal (`tf_attachment_preview_assets()`) |
| `schema.sql` | Database schema |
| `install.php` | First-run setup (creates admin) |
| `login.php` / `logout.php` | Authentication |
| `index.php` | Task dashboard, card list — the **phone** view (forwards to `desktop.php` on a wide screen) |
| `desktop.php` | Task dashboard, sortable/filterable table — the **desktop** view (forwards to `index.php` on a phone) |
| `task_form.php` | Create / edit / assign a task — the **phone** view (forwards to `task_form_desktop.php` on a wide screen) |
| `task_form_desktop.php` | Same form in MagDyn's sidebar chrome — the **desktop** view (forwards to `task_form.php` on a phone) |
| `task_view.php` | Task detail, status controls, comments, attachments, WhatsApp share — the **phone** view (forwards to `task_view_desktop.php` on a wide screen) |
| `task_view_desktop.php` | Same detail in MagDyn's sidebar chrome — the **desktop** view (forwards to `task_view.php` on a phone) |
| `magdyn_chrome.php` | Bridge that lets a desktop view wear MagDyn's real sidebar header/footer |
| `task_action.php` | Status change / delete (permission-checked) |
| `comment_action.php` | Add comment + attachments |
| `attachment.php` | Permission-checked serve / delete of a file |
| `share_target.php` | Receives files shared from WhatsApp (PWA share target) |
| `notify.php` | Dispatches push + WhatsApp notifications to task participants |
| `webpush.php` | Dependency-free Web Push (VAPID + aes128gcm) sender |
| `push_subscribe.php` | Saves/removes a browser's push subscription |
| `genvapid.php` | One-time VAPID key generator (run then delete) |
| `migrate_push.sql` | Adds the `push_subscriptions` table to an existing DB |
| `users.php` | Admin user management |
| `manifest.webmanifest`, `service-worker.js`, `offline.html`, `icon.svg` | PWA |
| `style.css`, `app.js` | UI |
| `header.php`, `footer.php` | Shared layout |
| `nav.php` | Shared header controls: the notifications bell + the inventory link / profile menu |
| `.htaccess` | Security headers, index protection |

## Security notes

- All queries use prepared statements; all output is escaped.
- Uploaded files are stored with randomized names, validated by real MIME type,
  size-limited (15 MB default, editable in `db.php`), and served only through
  `attachment.php` after a participant/admin check — never linked directly.
- CAD/3D files sniff only as `text/plain` (ASCII DXF/STL/STEP) or
  `application/octet-stream` (binary DWG/3DS), so `resolve_cad_mime()` names them
  from the extension — but **only** when the sniff was one of those ambiguous
  catch-alls, so a precise non-CAD sniff always wins. Same extension-guarded trust
  model as `resolve_container_mime()` for legacy `.doc`/`.xls`.
- WhatsApp "share" uses `wa.me` links (no third-party API or credentials).
  WhatsApp "import" uses the standard browser share target — files come in as
  ordinary uploads and are validated the same way.

## Customizing

- Attachment size / allowed types: `MAX_UPLOAD_BYTES` and `ALLOWED_MIME` in `db.php`.
  CAD/3D formats additionally need an entry in `CAD_EXT_MIME` (extension → canonical
  type) and, to preview, in `CAD_EXTS` in `uploads.php` — keep the two in step.
- Colors: CSS variables at the top of `style.css`.
- Replace `icon.svg` with your own logo (PNG icons can be added to the manifest if you prefer).
