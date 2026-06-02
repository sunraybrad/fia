<?php
/**
 * logout.php — Destroy the office session and redirect to login.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
init_session();

log_audit('logout');

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();

header('Location: /office/login.php?reason=logged_out');
exit;
