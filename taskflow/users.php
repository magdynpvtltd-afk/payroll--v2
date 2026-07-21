<?php
/**
 * User administration is handled by the MagDyn inventory app — TaskFlow
 * shares its `users` table. This page just forwards admins to MagDyn's
 * Users screen, so accounts, roles and passwords are managed in one place.
 */
require __DIR__ . '/db.php';
require_admin();
redirect('../users.php');
