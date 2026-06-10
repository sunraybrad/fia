<?php
/**
 * auth.php — Inspector portal session guard
 *
 * Include at the top of every protected inspector page:
 *   require_once __DIR__ . '/includes/auth.php';
 *   require_inspector();
 *
 * Session variables set at login:
 *   $_SESSION['inspector_id']    — inspectors.inspector_id
 *   $_SESSION['inspector_name']  — inspectors.full_name
 *   $_SESSION['inspector_email'] — inspectors.email
 *   $_SESSION['insp_start']      — login timestamp (for idle timeout)
 */

define('INSPECTOR_LOGIN_URL', '/inspector/login.php');

function require_inspector(): void
{
    if (empty($_SESSION['inspector_id'])) {
        _insp_redirect('not_logged_in');
    }

    // Bind session to the browser that created it
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!isset($_SESSION['insp_ua'])) {
        $_SESSION['insp_ua'] = $ua;
    } elseif (!hash_equals($_SESSION['insp_ua'], $ua)) {
        _insp_destroy('session_mismatch');
    }

    // Confirm account still exists, is not archived, and has an active status
    $db   = get_db();
    $stmt = $db->prepare(
        "SELECT inspector_id FROM inspectors
          WHERE inspector_id = ? AND is_archived = FALSE
            AND status IN ('Active', 'Prospective') LIMIT 1"
    );
    $stmt->bind_param('i', $_SESSION['inspector_id']);
    $stmt->execute();
    $stmt->store_result();
    $active = $stmt->num_rows > 0;
    $stmt->close();
    if (!$active) {
        _insp_destroy('account_inactive');
    }

    $timeout = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 28800; // 8 hrs
    if (!empty($_SESSION['insp_start'])) {
        if ((time() - $_SESSION['insp_start']) > $timeout) {
            _insp_destroy('session_expired');
        }
    }
    $_SESSION['insp_start'] = time();
}

function inspector_logout(): never
{
    _insp_destroy('logged_out');
}

function _insp_redirect(string $reason): never
{
    header('Location: ' . INSPECTOR_LOGIN_URL . '?reason=' . urlencode($reason));
    exit;
}

function _insp_destroy(string $reason): never
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    _insp_redirect($reason);
}
