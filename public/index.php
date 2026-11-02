<?php
/** Entry point: send visitors to their home page, or to sign-in. Shows no content itself. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/init.php';

$user = current_user();
redirect($user !== null ? home_path_for_role($user['role_name']) : 'login.php');
