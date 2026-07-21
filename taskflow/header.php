<?php
/** Shared page top. Expects db.php to be included already; every var is optional:
 *  $pageTitle, $headHtml, $wrapClass (extra class on <main>), $bodyClass,
 *  $hideTopbar (skip the topbar entirely — desktop.php carries its own nav
 *  inside the datatable toolbar instead, so a second bar above it is just
 *  vertical space the grid could be using). */
require_once __DIR__ . '/nav.php';
$__u = current_user();
$__flash = flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#E11F26">
<title><?= isset($pageTitle) ? e($pageTitle) . ' · TaskFlow' : 'TaskFlow' ?></title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="icon.svg">
<link rel="stylesheet" href="<?= tf_asset('style.css') ?>">
<?= $headHtml ?? '' ?>
</head>
<body<?= !empty($bodyClass) ? ' class="' . e($bodyClass) . '"' : '' ?>>
<?php if (empty($hideTopbar)): ?>
<header class="topbar">
  <a class="brand" href="index.php"><img src="logo.svg" alt="Mag Dyn"><span>TaskFlow</span></a>
  <?php if ($__u): ?>
    <?php /* No "Table" link: the card and table views are no longer a choice —
             index.php routes to whichever one fits the viewport, so this link
             would only ever send a phone to a view it bounces straight out of. */ ?>
    <nav class="topnav">
      <a href="index.php">Tasks</a>
      <a href="task_form.php" title="New task (Alt+N)">＋ New</a>
    </nav>
    <?php /* Deliberately OUTSIDE .topnav, which collapses on phones: the
             profile menu and the bell are the two things a phone still needs
             up here, and this keeps them in the top right at every width. */ ?>
    <?= tf_user_nav() ?>
    <?= tf_push_bell() ?>
  <?php endif; ?>
</header>
<?php endif; ?>
<main class="wrap<?= !empty($wrapClass) ? ' ' . e($wrapClass) : '' ?>">
<?php if ($__flash): ?><div class="flash"><?= e($__flash) ?></div><?php endif; ?>
