<?php
/**
 * TaskFlow ▸ MagDyn chrome bridge.
 *
 * Lets a TaskFlow page wear MagDyn's real page chrome — the sidebar nav from
 * includes/header.php and the script tail from includes/footer.php — instead
 * of TaskFlow's own topbar/tabbar, so the two apps read as one system. The nav
 * is the genuine article, rendered from MagDyn's module tree against the
 * signed-in user's permissions, so it cannot drift from the rest of the app.
 *
 * Usage (see desktop.php):
 *
 *     require __DIR__ . '/db.php';
 *     $me = require_login();
 *     require __DIR__ . '/magdyn_chrome.php';
 *     $page_title = '…';
 *     require MAGDYN_INCLUDES . '/header.php';
 *     …page…
 *     require MAGDYN_INCLUDES . '/footer.php';
 *
 * ── Why the load order below is not negotiable ──────────────────────────────
 *
 * The two apps independently declare eight of the same function names:
 * current_user, require_login, db, is_admin, redirect, csrf_token, csrf_field
 * and csrf_check. PHP has no last-one-wins for functions — the second
 * declaration is a fatal error — so MagDyn's copies are wrapped in
 * function_exists() guards (includes/db.php, auth.php, csrf.php,
 * permissions.php, helpers.php). Whoever declares FIRST is the one in force.
 *
 * TaskFlow has to be first, for two reasons that would otherwise break it:
 *
 *   1. require_login(). TaskFlow's returns the user row and enforces the
 *      taskflow.view grant; MagDyn's returns void and bounces to MagDyn's
 *      login. Callers do `$me = require_login();`.
 *   2. CSRF. TaskFlow signs with $_SESSION['csrf'] under its own field name,
 *      MagDyn with $_SESSION['_csrf'] under $APP['csrf_field']. TaskFlow's
 *      POST endpoints (task_action.php, comment_action.php, logout.php) do NOT
 *      load MagDyn, so they validate with TaskFlow's csrf_check(). A form on
 *      this page must therefore carry a TaskFlow token.
 *
 * Requiring db.php here rather than trusting the caller is what pins that
 * order down. The rest of the overlap is safe either way: both db() open the
 * same database with the same credentials and PDO attributes, both is_admin()
 * resolve the same 'admin' role, and both redirect() are a Location + exit.
 *
 * Known divergence: TaskFlow's current_user() keys off $_SESSION['uid'] and
 * does not follow MagDyn's "view as" impersonation, so while impersonating an
 * admin sees a sidebar built for the target user but a task list for their own
 * real account, and header.php's banner names the real user twice. TaskFlow
 * pages act as the real user by design — its task rules assume it — so this is
 * left alone rather than papered over.
 */

// TaskFlow's core, FIRST — see above. require_once so a caller that already
// loaded it (every page does) doesn't re-run its session bootstrap.
require_once __DIR__ . '/db.php';
// tf_push_bell() / tf_user_nav(): normally pulled in by TaskFlow's own
// header.php, which a chrome-borrowing page doesn't include.
require_once __DIR__ . '/nav.php';

// MagDyn's bootstrap: config, PDO, helpers, permissions, and the module tree
// the sidebar is built from. Its session block is skipped because db.php above
// already started the session — it is the same session either way, since both
// apps read session_name from the same config/app.config.php.
require_once dirname(__DIR__) . '/includes/bootstrap.php';

/** Where MagDyn's header.php / footer.php live. */
define('MAGDYN_INCLUDES', dirname(__DIR__) . '/includes');

// ---------------------------------------------------------------------------
// Defaults for the header/footer hooks. A page may override any of them after
// including this file.
// ---------------------------------------------------------------------------

// Highlights the TaskFlow entry in the sidebar (modules.code = 'taskflow',
// registered by sql/migration_20260715_181500_IST.sql).
$page_module = 'taskflow';

// TaskFlow's stylesheet loads BEFORE MagDyn's two, so that where a class name
// exists in both — .brand, .btn, .card, .pill, .muted, .small, .empty — the
// chrome's own rules win and the sidebar looks like the sidebar. TaskFlow's
// table styles no longer collide at all: they carry a tfdt- prefix, which also
// keeps MagDyn's datatable.js (it hydrates every .dt-wrap it finds) away from a
// table that already has its own client.
$page_css_first = ['/taskflow/style.css'];

// TaskFlow is a separately installable PWA. Keep ITS manifest, icon and colour
// on this page — pointing at MagDyn's would re-identify the installed app.
// Relative, so they resolve inside /taskflow/ exactly as they do elsewhere.
$page_manifest_url = 'manifest.webmanifest';
$page_icon_url     = 'icon.svg';
$page_theme_color  = '#E11F26';

// No SPA on a borrowed-chrome page: spa.js swaps sidebar destinations into
// <main> in place, which would leave MagDyn's markup in a document served from
// /taskflow/, still carrying TaskFlow's <head> and service-worker scope. The
// sidebar's links navigate out of TaskFlow properly instead.
$page_no_spa = true;

// TaskFlow's own client: service-worker registration, web-push opt-in, Alt+N.
// MagDyn's footer runs it through asset_url(), which cache-busts on mtime just
// as TaskFlow's tf_asset() does.
$extra_js = ['/taskflow/app.js'];

/**
 * The window.TF globals TaskFlow's app.js reads, as a <head> script.
 *
 * Normally emitted by TaskFlow's footer.php, which a chrome-borrowing page
 * doesn't include. In <head> it is guaranteed to run before app.js, which
 * MagDyn's footer loads at the end of <body>.
 *
 * The csrf token here is TaskFlow's (see the load-order note above), which is
 * what push_subscribe.php validates against.
 */
function tf_chrome_globals_script(): string
{
    if (!current_user()) {
        return '';
    }
    return '<script>window.TF = ' . json_encode([
        'vapidPublic' => VAPID_PUBLIC,
        'csrf'        => csrf_token(),
        'pushReady'   => VAPID_PUBLIC !== '',
    ], JSON_UNESCAPED_SLASHES) . ';</script>';
}
