<?php
/**
 * auth.php — Warranty company portal session guard
 *
 * Include at the top of every protected warco page:
 *   require_once __DIR__ . '/includes/auth.php';
 *   require_warco();
 *
 * Session variables set at login:
 *   $_SESSION['warco_id']    — warranty_co.warranty_co_id
 *   $_SESSION['warco_name']  — warranty_co.company_name
 *   $_SESSION['warco_start'] — login timestamp (for idle timeout)
 */

define('WARCO_LOGIN_URL', '/client/login.php');

function require_warco(): void
{
    if (empty($_SESSION['warco_id'])) {
        _warco_redirect('not_logged_in');
    }

    // Bind session to the browser that created it
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!isset($_SESSION['warco_ua'])) {
        $_SESSION['warco_ua'] = $ua;
    } elseif (!hash_equals($_SESSION['warco_ua'], $ua)) {
        _warco_destroy('session_mismatch');
    }

    // Confirm account still exists and is not archived
    $db   = get_db();
    $stmt = $db->prepare(
        'SELECT warranty_co_id FROM warranty_co
          WHERE warranty_co_id = ? AND is_archived = FALSE LIMIT 1'
    );
    $stmt->bind_param('i', $_SESSION['warco_id']);
    $stmt->execute();
    $stmt->store_result();
    $active = $stmt->num_rows > 0;
    $stmt->close();
    if (!$active) {
        _warco_destroy('account_inactive');
    }

    $timeout = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 28800; // 8 hrs
    if (!empty($_SESSION['warco_start'])) {
        if ((time() - $_SESSION['warco_start']) > $timeout) {
            _warco_destroy('session_expired');
        }
    }
    $_SESSION['warco_start'] = time();
}

function warco_logout(): never
{
    _warco_destroy('logged_out');
}

function _warco_redirect(string $reason): never
{
    header('Location: ' . WARCO_LOGIN_URL . '?reason=' . urlencode($reason));
    exit;
}

function _warco_destroy(string $reason): never
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    _warco_redirect($reason);
}
