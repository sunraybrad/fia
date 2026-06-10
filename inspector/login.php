<?php
/**
 * login.php — Inspector portal login
 * Authenticates against inspectors table using email + legacy_pin.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();

// Already logged in → go to dashboard
if (!empty($_SESSION['inspector_id'])) {
    header('Location: /inspector/index.php');
    exit;
}

$error  = '';
$reason = $_GET['reason'] ?? '';

$reason_messages = [
    'not_logged_in'   => 'Please log in to continue.',
    'session_expired' => 'Your session has expired. Please log in again.',
    'logged_out'      => 'You have been logged out.',
    'account_inactive'=> 'Your account is no longer active. Please contact FIA.',
    'session_mismatch'=> 'Your session was invalid. Please log in again.',
];

if ($reason && isset($reason_messages[$reason])) {
    $error = $reason_messages[$reason];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $pin   = trim($_POST['pin'] ?? '');

    if ($email === '' || $pin === '') {
        $error = 'Please enter your email address and PIN.';
    } elseif (is_rate_limited('inspector_login')) {
        $error = 'Too many failed login attempts. Please try again in 30 minutes.';
    } else {
        $db   = get_db();
        $stmt = $db->prepare(
            "SELECT inspector_id, full_name, email, legacy_pin, status
               FROM inspectors
              WHERE email       = ?
                AND is_archived = FALSE
              LIMIT 1"
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $insp = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$insp) {
            $error = 'Email address not found or account is inactive.';
        } elseif (!in_array($insp['status'], ['Active', 'Prospective'], true)) {
            $error = 'Your account is not active. Please contact FIA.';
        } elseif ($pin !== (string)($insp['legacy_pin'] ?? '')) {
            $error = 'Incorrect PIN. Please try again.';
        }

        if ($error) {
            record_attempt('inspector_login');
        }

        if (!$error) {
            regenerate_session();
            $_SESSION['inspector_id']    = (int)$insp['inspector_id'];
            $_SESSION['inspector_name']  = $insp['full_name'];
            $_SESSION['inspector_email'] = $insp['email'] ?? '';
            $_SESSION['insp_start']      = time();
            header('Location: /inspector/index.php');
            exit;
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspector Login | FIA</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet" href="/inspector/css/inspector.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-box">
        <img src="/images/logo_horiz_600.jpg" alt="Florida Inspection Associates" class="logo">
        <h1>Inspector Login</h1>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2" style="font-size:.85rem;"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/inspector/login.php">
            <div class="mb-3">
                <label class="form-label fw-semibold">Email Address</label>
                <input type="email" name="email" class="form-control"
                       value="<?= h($_POST['email'] ?? '') ?>"
                       autofocus required autocomplete="username">
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">PIN</label>
                <input type="password" name="pin" class="form-control" required autocomplete="current-password">
                <div class="form-text">Your PIN was provided by FIA. If you don't know it, contact the office.</div>
            </div>
            <button type="submit" class="btn btn-fia w-100">Log In</button>
        </form>

        <p class="text-center text-muted mt-3 mb-0" style="font-size:.78rem;">
            &copy; <?= date('Y') ?> Florida Inspection Associates
        </p>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
</body>
</html>
