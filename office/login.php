<?php
/**
 * login.php — Office portal login
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
init_session();

// Already logged in — send to dashboard
if (!empty($_SESSION['office_id'])) {
    header('Location: /office/index.php');
    exit;
}

$error  = '';
$reason = $_GET['reason'] ?? '';

$reason_messages = [
    'session_expired' => 'Your session expired. Please log in again.',
    'not_logged_in'   => 'Please log in to access the office portal.',
    'logged_out'      => 'You have been logged out.',
];
$info = $reason_messages[$reason] ?? '';

// ── Handle form submission ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (is_rate_limited('office_login', 5, 900)) {
        $error = 'Too many failed attempts. Please try again in 15 minutes.';
    } else {

        $email    = trim($_POST['email']    ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Please enter your email and password.';
        } else {
            $db   = get_db();
            $stmt = $db->prepare(
                "SELECT id, name, email, password_hash
                   FROM office_users
                  WHERE email = ? AND is_active = 1
                  LIMIT 1"
            );
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Valid — create session
                regenerate_session();
                $_SESSION['office_id']    = $user['id'];
                $_SESSION['office_name']  = $user['name'];
                $_SESSION['office_email'] = $user['email'];
                $_SESSION['office_start'] = time();

                $upd = $db->prepare("UPDATE office_users SET last_login = NOW() WHERE id = ?");
                $upd->bind_param('i', $user['id']);
                $upd->execute();
                $upd->close();

                log_audit('login.success');
                header('Location: /office/index.php');
                exit;
            } else {
                record_attempt('office_login', 5, 900, 1800);
                log_audit('login.fail', null, null, ['email' => $email]);
                $error = 'Invalid email or password.';
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Office Login | FIA</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet" href="/office/css/office.css">
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">

        <img src="/images/logo_horiz_600.jpg" alt="FIA" class="login-logo">
        <h1>Office Portal</h1>

        <?php if ($info): ?>
        <div class="alert alert-info py-2 px-3" style="font-size:.85rem;">
            <?= h($info) ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 px-3" style="font-size:.85rem;">
            <?= h($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/office/login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="mb-3">
                <label for="email" class="form-label fw-semibold">Email</label>
                <input type="email" id="email" name="email" class="form-control"
                       value="<?= h($_POST['email'] ?? '') ?>"
                       autocomplete="email" required autofocus>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label fw-semibold">Password</label>
                <input type="password" id="password" name="password"
                       class="form-control" autocomplete="current-password" required>
            </div>
            <div class="d-grid mb-3">
                <button type="submit" class="btn btn-fia">Log In</button>
            </div>
            <div class="text-center">
                <a href="/office/forgot_password.php" style="font-size:.83rem; color:#6699CC;">
                    Forgot your password?
                </a>
            </div>
        </form>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmCqEsyX4BX/2cMT6MAgGXVhVTck"
        crossorigin="anonymous"></script>
</body>
</html>
