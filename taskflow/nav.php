<?php
/**
 * Shared header controls. Two pieces, rendered in two different places:
 *
 *  - tf_push_bell()  the "enable notifications" opt-in. Collapsed to a bare
 *                    bell so it fits in a header row without crowding it;
 *                    hover (or keyboard focus) slides the full label out. It
 *                    used to be a position:fixed pill floating over the
 *                    bottom-right of the viewport, where it sat on top of the
 *                    desktop table.
 *  - tf_user_nav()   a link back to the MagDyn inventory, plus the profile
 *                    menu (account + logout).
 *
 * The card pages render both in the topbar. desktop.php has no topbar, so it
 * drops them into the datatable toolbar instead. Keeping the markup here means
 * the two placements can't drift apart.
 *
 * Both return '' when nobody is signed in, so the login screen stays bare.
 */

/**
 * The notifications opt-in button. Ships hidden: app.js unhides it only when
 * this browser can actually subscribe and hasn't already. Exactly one per page
 * — app.js addresses it by id.
 */
function tf_push_bell(): string
{
    if (!current_user()) {
        return '';
    }
    return '<button id="enable-push" class="push-cta" type="button" hidden'
        . ' aria-label="Enable notifications" title="Enable notifications">'
        . '<span class="push-ico" aria-hidden="true">🔔</span>'
        . '<span class="push-lbl">Enable notifications</span>'
        . '</button>';
}

/**
 * Inventory link + profile menu.
 *
 * The menu is a plain <details>/<summary>, so it opens with no JavaScript and
 * keeps working offline; app.js only adds the click-outside/Escape niceties.
 */
function tf_user_nav(): string
{
    $u = current_user();
    if (!$u) {
        return '';
    }
    ob_start();
    ?>
<div class="usernav">
  <a class="usernav-link" href="../index.php" title="Back to MagDyn inventory">
    <span aria-hidden="true">↩</span> <span class="usernav-lbl">Inventory</span>
  </a>
  <details class="usermenu">
    <summary title="<?= e($u['email']) ?>">
      <span aria-hidden="true">👤</span>
      <span class="usernav-lbl"><?= e($u['name']) ?></span>
      <span class="usermenu-caret" aria-hidden="true">▾</span>
    </summary>
    <div class="usermenu-pop">
      <span class="usermenu-mail"><?= e($u['email']) ?></span>
      <a href="../account.php">My account</a>
      <form class="usermenu-logout" method="post" action="logout.php"
            onsubmit="return confirm('Are you sure you want to log out?')">
        <?= csrf_field() ?><button type="submit">Logout</button>
      </form>
    </div>
  </details>
</div>
<?php
    return (string)ob_get_clean();
}
