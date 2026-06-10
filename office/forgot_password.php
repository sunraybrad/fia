<?php
/**
 * forgot_password.php
 * Accepts an email address, generates a reset token, and sends a link.
 *
 * Intentionally gives the same confirmation message whether or not the
 * email exists — prevents user enumeration.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/../PHPMailer6/autoload.php';
init_session();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;

$submitted = false;
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (is_rate_limited('pwd_reset', 5, 900)) {
        $error = 'Too many reset requests. Please wait 15 minutes and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
        record_attempt('pwd_reset', 5, 900);
        $db   = get_db();
        $stmt = $db->prepare(
            "SELECT id FROM office_users WHERE email = ? AND is_active = 1 LIMIT 1"
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            try {
                // Generate a cryptographically secure token
                $raw_token  = bin2hex(random_bytes(32));          // 64-char hex string
                $token_hash = hash('sha256', $raw_token);         // store hash, email the raw
                $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour

                // Invalidate any existing unused tokens for this user
                $del = $db->prepare(
                    "DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL"
                );
                $del->bind_param('i', $user['id']);
                $del->execute();
                $del->close();

                // Store the new token
                $ins = $db->prepare(
                    "INSERT INTO password_resets (user_id, token_hash, expires_at)
                     VALUES (?, ?, ?)"
                );
                $ins->bind_param('iss', $user['id'], $token_hash, $expires_at);
                $ins->execute();
                $ins->close();

                log_audit('password_reset.request');

                // Send the reset email
                $reset_url = SITE_URL . '/office/reset_password.php?token=' . $raw_token;

                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Host       = SMTP_HOST;
                    $mail->SMTPAuth   = true;
                    $mail->Port       = SMTP_PORT;
                    $mail->Username   = SMTP_USER;
                    $mail->Password   = SMTP_PASS;
                    $mail->CharSet    = 'UTF-8';

                    $mail->setFrom(SMTP_FROM_EMAIL, 'FIA Office Portal');
                    $mail->addAddress($email);
                    $mail->Subject = 'FIA Office — Password Reset Request';
                    $mail->isHTML(true);
                    $mail->Body    = '
                        <p>A password reset was requested for your FIA Office account.</p>
                        <p>Click the link below to set a new password. This link expires in 1 hour.</p>
                        <p><a href="' . $reset_url . '">' . $reset_url . '</a></p>
                        <p>If you did not request this, you can safely ignore this email.</p>
                    ';
                    $mail->AltBody = "A password reset was requested for your FIA Office account.\n\n"
                                   . "Reset link (expires in 1 hour):\n" . $reset_url . "\n\n"
                                   . "If you did not request this, ignore this email.";

                    $mail->send();
                } catch (MailerException $e) {
                    error_log('Password reset mail failed: ' . $mail->ErrorInfo);
                    // We don't expose mail failures to the user
                }
            } catch (Throwable $e) {
                error_log('Password reset token generation failed: ' . $e->getMessage());
                $error = 'A server error occurred. Please try again later.';
            }
        }

        // Always show the same message (anti-enumeration), unless a server error occurred
        if (!$error) {
            $submitted = true;
        }
    }
    } // end rate-limit else
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Forgot Password | FIA Office</title>
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
        <h1>Reset Password</h1>

        <?php if ($submitted): ?>

        <div class="alert alert-success py-2 px-3" style="font-size:.85rem;">
            If that email is associated with an office account, a reset link has been sent.
            Check your inbox — the link expires in 1 hour.
        </div>
        <div class="text-center mt-3">
            <a href="/office/login.php" style="font-size:.85rem; color:#6699CC;">
                &larr; Back to login
            </a>
        </div>

        <?php else: ?>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 px-3" style="font-size:.85rem;">
            <?= htmlspecialchars($error, ENT_QUOTES) ?>
        </div>
        <?php endif; ?>

        <p style="font-size:.85rem; color:#555;">
            Enter your office email address and we'll send you a password reset link.
        </p>

        <form method="POST" action="/office/forgot_password.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="mb-3">
                <label for="email" class="form-label fw-semibold">Email</label>
                <input type="email" id="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>"
                       autocomplete="email" required autofocus>
            </div>
            <div class="d-grid mb-3">
                <button type="submit" class="btn btn-fia">Send Reset Link</button>
            </div>
            <div class="text-center">
                <a href="/office/login.php" style="font-size:.83rem; color:#6699CC;">
                    &larr; Back to login
                </a>
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
