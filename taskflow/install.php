<?php
/**
 * No installer needed: this TaskFlow build shares MagDyn's database and its
 * existing user accounts. Sign in with your MagDyn inventory credentials.
 */
require __DIR__ . '/db.php';
redirect('login.php');
