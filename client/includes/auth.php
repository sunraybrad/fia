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
