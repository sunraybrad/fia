<?php
/**
 * auth.php — Office portal session authentication
 *
 * Include at the top of every protected office page:
 *
 *   require_once 'C:\inetpub\fia_private\config.php';
 *   require_once 'C:\inetpub\fia_private\db.php';
 *   require_once __DIR__ . '/includes/auth.php';
 *   require_office();
 *
 * Session variables set at login:
 *   $_SESSION['office_id']    — office_users.id
 *   $_SESSION['office_name']  — display name
 *   $_SESSION['office_email'] — email address
 */

define('OFFICE_LOGIN_URL', '/office/login.php');

/**
 * Verify the user is logged into the office portal.
 * Redirects to the login page on any failure.
 */
function require_office(): void
{
    if (empty($_SESSION['office_id'])) {
        _office_redirect('not_logged_in');
    }

    // Bind session to the browser that created it; destroys session if cookie
    // is replayed from a different User-Agent (e.g. stolen cookie).
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!isset($_SESSION['office_ua'])) {
        $_SESSION['office_ua'] = $ua;
    } elseif (!hash_equals($_SESSION['office_ua'], $ua)) {
        _office_destroy('session_mismatch');
    }

    // Confirm the account still exists and is active in the database.
    // Catches deactivated accounts that still hold a valid session cookie.
    $db   = get_db();
    $stmt = $db->prepare('SELECT id FROM office_users WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('i', $_SESSION['office_id']);
    $stmt->execute();
    $stmt->store_result();
    $active = $stmt->num_rows > 0;
    $stmt->close();
    if (!$active) {
        _office_destroy('account_inactive');
    }

    // Idle timeout — reuses SESSION_LIFETIME from config.php
    $timeout = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 7200;
    if (!empty($_SESSION['office_start'])) {
        if ((time() - $_SESSION['office_start']) > $timeout) {
            _office_destroy('session_expired');
        }
    }

    $_SESSION['office_start'] = time();
}

function office_logout(): never
{
    _office_destroy('logged_out');
}

function _office_redirect(string $reason): never
{
    header('Location: ' . OFFICE_LOGIN_URL . '?reason=' . urlencode($reason));
    exit;
}

function _office_destroy(string $reason): never
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    _office_redirect($reason);
}
