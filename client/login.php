<?php
/**
 * login.php — Warranty company portal login
 *
 * Auth: login_username + legacy_pin from warranty_co table.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
init_session();

if (!empty($_SESSION['warco_id'])) {
    header('Location: /client/index.php');
    exit;
}

$error  = '';
$reason = $_GET['reason'] ?? '';

$reason_messages = [
    'session_expired' => 'Your session expired. Please log in again.',
    'not_logged_in'   => 'Please log in to access this portal.',
    'logged_out'      => 'You have been logged out.',
    'account_inactive'=> 'Your account is no longer active. Please contact FIA.',
    'session_mismatch'=> 'Your session was invalid. Please log in again.',
];
$info = $reason_messages[$reason] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    if (is_rate_limited('warco_login', 5, 900)) {
        $error = 'Too many failed attempts. Please try again in 15 minutes.';
    } else {

        $username = trim($_POST['username'] ?? '');
        $pin      = trim($_POST['pin']      ?? '');

        if ($username === '' || $pin === '') {
            $error = 'Please enter your username and PIN.';
        } else {
            $db   = get_db();
            $stmt = $db->prepare(
                "SELECT warranty_co_id, company_name, login_username, legacy_pin
                   FROM warranty_co
                  WHERE login_username = ?
                    AND is_archived    = FALSE
                  LIMIT 1"
            );
            $stmt->bind_param('s', $username);
            if (!$stmt->execute()) {
                error_log('Query failed [client/login.php]: ' . $db->error);
                $warco = null;
            } else {
                $warco = $stmt->get_result()->fetch_assoc();
            }
            $stmt->close();

            $valid = false;
            if ($warco) {
                // Support both plaintext legacy_pin and future bcrypt hash
                if (!empty($warco['legacy_pin'])) {
                    if (str_starts_with($warco['legacy_pin'], '$2y$')) {
                        $valid = password_verify($pin, $warco['legacy_pin']);
                    } else {
                        $valid = ($pin === $warco['legacy_pin']);
                    }
                }
            }

            if ($valid) {
                regenerate_session();
                $_SESSION['warco_id']    = $warco['warranty_co_id'];
                $_SESSION['warco_name']  = $warco['company_name'];
                $_SESSION['warco_start'] = time();

                log_audit('warco.login.success');
                header('Location: /client/index.php');
                exit;
            } else {
                record_attempt('warco_login', 5, 900, 1800);
                log_audit('warco.login.fail', null, null, ['username' => $username]);
                $error = 'Invalid username or PIN.';
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
    <title>Client Login | FIA</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet" href="/css/fia.css">
</head>
<body>

<div class="login-wrapper">
    <div class="login-card">

        <img src="/images/logo_horiz_600.jpg" alt="FIA" class="login-logo">
        <h1>Client Portal</h1>

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

        <form method="POST" action="/client/login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="mb-3">
                <label for="username" class="form-label fw-semibold">Username</label>
                <input type="text" id="username" name="username" class="form-control"
                       value="<?= h($_POST['username'] ?? '') ?>"
                       autocomplete="username" required autofocus>
            </div>
            <div class="mb-3">
                <label for="pin" class="form-label fw-semibold">PIN</label>
                <input type="password" id="pin" name="pin"
                       class="form-control" autocomplete="current-password" required>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-fia">Log In</button>
            </div>
        </form>

        <p class="text-center mt-3" style="font-size:.8rem; color:#888;">
            Need access? Contact Florida Inspection Associates.
        </p>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmB4ffpfAFqef6oe76k0b2o1RhwC"
        crossorigin="anonymous"></script>
</body>
</html>
