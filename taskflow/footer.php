</main>
<?php if (current_user()): ?>
<nav class="tabbar">
  <a href="index.php">🗒️<span>Tasks</span></a>
  <a href="task_form.php">➕<span>New</span></a>
  <form class="logout-form" method="post" action="logout.php"
        onsubmit="return confirm('Are you sure you want to log out?')">
    <?= csrf_field() ?><button type="submit">🚪<span>Logout</span></button>
  </form>
</nav>
<?php endif; ?>
<?php /* The enable-notifications bell is rendered by tf_push_bell() up in the
         header now — as a fixed pill down here it covered the bottom-right of
         whatever was on the page, the desktop table worst of all. */ ?>
<?php if (current_user()): ?>
<script>
window.TF = {
  vapidPublic: <?= json_encode(VAPID_PUBLIC) ?>,
  csrf: <?= json_encode(csrf_token()) ?>,
  pushReady: <?= VAPID_PUBLIC !== '' ? 'true' : 'false' ?>
};
</script>
<?php endif; ?>
<script src="<?= tf_asset('app.js') ?>"></script>
</body>
</html>
