<?php
/** Sign out. POST + CSRF only, so a link or image on another site cannot sign a user out. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/init.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('login.php'); // GET does nothing
}
require_csrf();
auth_logout();
flash_set('success', 'You have been signed out.');
redirect('login.php');
