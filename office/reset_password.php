<?php
/**
 * reset_password.php
 * Validates a password reset token and allows the user to set a new password.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
init_session();

$db          = get_db();
$raw_token   = trim($_GET['token'] ?? '');
$token_hash  = $raw_token !== '' ? hash('sha256', $raw_token) : '';
$error       = '';
$success     = false;
$token_valid = false;
$reset_user  = null;

// ── Validate token ────────────────────────────────────────────────────────
if ($token_hash !== '') {
    $stmt = $db->prepare(
        "SELECT pr.id AS reset_id, pr.user_id, ou.email
           FROM password_resets pr
           JOIN office_users ou ON ou.id = pr.user_id
          WHERE pr.token_hash = ?
            AND pr.used_at IS NULL
            AND pr.expires_at > NOW()
          LIMIT 1"
    );
    $stmt->bind_param('s', $token_hash);
    $stmt->execute();
    $reset_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $token_valid = (bool)$reset_user;
}

// ── Handle new password submission ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valid) {
    verify_csrf();

    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($password) < 10) {
        $error = 'Password must be at least 10 characters.';
    } elseif ($password !== $password2) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // Update password
        $upd = $db->prepare(
            "UPDATE office_users SET password_hash = ? WHERE id = ?"
        );
        $upd->bind_param('si', $hash, $reset_user['user_id']);
        $upd->execute();
        $upd->close();

        // Mark token used
        $mark = $db->prepare(
            "UPDATE password_resets SET used_at = NOW() WHERE id = ?"
        );
        $mark->bind_param('i', $reset_user['reset_id']);
        $mark->execute();
        $mark->close();

        log_audit('password_reset.complete');
        $success = true;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Reset Password | FIA Office</title>
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
        <h1>Set New Password</h1>

        <?php if ($success): ?>

        <div class="alert alert-success py-2 px-3" style="font-size:.85rem;">
            Your password has been updated. You can now log in.
        </div>
        <div class="text-center mt-3">
            <a href="/office/login.php" class="btn btn-fia px-4">Go to Login</a>
        </div>

        <?php elseif (!$token_valid): ?>

        <div class="alert alert-danger py-2 px-3" style="font-size:.85rem;">
            This reset link is invalid or has expired. Please request a new one.
        </div>
        <div class="text-center mt-3">
            <a href="/office/forgot_password.php" style="font-size:.85rem; color:#6699CC;">
                Request a new link
            </a>
        </div>

        <?php else: ?>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 px-3" style="font-size:.85rem;">
            <?= htmlspecialchars($error, ENT_QUOTES) ?>
        </div>
        <?php endif; ?>

        <form method="POST"
              action="/office/reset_password.php?token=<?= urlencode($raw_token) ?>"
              novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="mb-3">
                <label for="password" class="form-label fw-semibold">New Password</label>
                <input type="password" id="password" name="password"
                       class="form-control" autocomplete="new-password"
                       minlength="10" required autofocus>
                <div class="form-text">Minimum 10 characters.</div>
            </div>
            <div class="mb-3">
                <label for="password2" class="form-label fw-semibold">Confirm Password</label>
                <input type="password" id="password2" name="password2"
                       class="form-control" autocomplete="new-password"
                       minlength="10" required>
            </div>
            <div class="d-grid">
                <button type="submit" class="btn btn-fia">Set New Password</button>
            </div>
        </form>

        <?php endif; ?>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmCqEsyX4BX/2cMT6MAgGXVhVTck"
        crossorigin="anonymous"></script>
</body>
</html>
