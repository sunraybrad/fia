<?php
/**
 * email_test.php — SMTP send test for the office portal
 * Access: /office/email_test.php
 * Remove or restrict this file after confirming email works.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

require_once __DIR__ . '/../PHPMailer6/autoload.php';

$result  = null;
$to_addr = '';
$debug_log = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $to_addr = trim($_POST['to'] ?? '');

    if (!filter_var($to_addr, FILTER_VALIDATE_EMAIL)) {
        $result = ['ok' => false, 'msg' => 'Invalid email address.'];
    } else {
        $mail = new PHPMailer(true);

        // Capture SMTP debug output
        ob_start();

        try {
            $mail->SMTPDebug  = SMTP::DEBUG_SERVER;   // verbose for testing
            $mail->Debugoutput = 'echo';

            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = SMTP_PASS;
            $mail->SMTPSecure = SMTP_SECURE;
            $mail->Port       = SMTP_PORT;

            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->addAddress($to_addr);
            if (defined('MAIL_BCC_ADMIN') && MAIL_BCC_ADMIN) {
                $mail->addBCC(MAIL_BCC_ADMIN);
            }

            $mail->Subject = 'FIA Email Test — ' . date('Y-m-d H:i:s');
            $mail->Body    = "This is a test email from the FIA office portal.\n\n"
                           . "SMTP Host : " . SMTP_HOST . "\n"
                           . "SMTP Port : " . SMTP_PORT . "\n"
                           . "SMTP User : " . SMTP_USER . "\n"
                           . "Secure    : " . SMTP_SECURE . "\n"
                           . "Sent at   : " . date('Y-m-d H:i:s') . "\n";

            $mail->send();
            $result = ['ok' => true, 'msg' => 'Email sent successfully to ' . h($to_addr) . '.'];

        } catch (Exception $e) {
            $result = ['ok' => false, 'msg' => 'Send failed: ' . h($mail->ErrorInfo)];
        }

        $debug_log = ob_get_clean();
    }
}

$page_title = 'Email Test';
$active_nav = '';
require_once __DIR__ . '/includes/header.php';
?>

<div class="fia-card" style="max-width:680px; margin:0 auto;">
    <div class="fia-page-header">
        <i class="bi bi-envelope-check"></i> SMTP Email Test
    </div>
    <div class="fia-card-body">

        <div class="fia-legend mb-3" style="font-size:.82rem;">
            <strong>Current SMTP config</strong><br>
            Host: <code><?= h(SMTP_HOST) ?></code> &nbsp;
            Port: <code><?= h(SMTP_PORT) ?></code> &nbsp;
            Secure: <code><?= h(SMTP_SECURE) ?></code><br>
            From: <code><?= h(SMTP_FROM_EMAIL) ?></code> &nbsp;
            BCC admin: <code><?= h(MAIL_BCC_ADMIN) ?></code>
        </div>

        <?php if ($result): ?>
        <div class="alert alert-<?= $result['ok'] ? 'success' : 'danger' ?> py-2">
            <i class="bi bi-<?= $result['ok'] ? 'check-circle' : 'x-circle' ?>"></i>
            <?= $result['msg'] ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/office/email_test.php">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="row g-2 align-items-end">
                <div class="col-8">
                    <label class="form-label fw-semibold">Send test email to</label>
                    <input type="email" name="to" class="form-control form-control-sm"
                           value="<?= h($to_addr ?: MAIL_BCC_ADMIN) ?>"
                           placeholder="recipient@example.com" required>
                </div>
                <div class="col-4">
                    <button type="submit" class="btn btn-fia btn-sm w-100">
                        <i class="bi bi-send"></i> Send Test
                    </button>
                </div>
            </div>
        </form>

        <?php if ($debug_log): ?>
        <div class="mt-3">
            <strong style="font-size:.82rem;">SMTP Debug Log</strong>
            <pre class="mt-1 p-2" style="font-size:.72rem; background:#1e1e1e; color:#d4d4d4;
                 border-radius:4px; max-height:300px; overflow-y:auto; white-space:pre-wrap;"><?= h($debug_log) ?></pre>
        </div>
        <?php endif; ?>

        <p class="text-muted mt-3 mb-0" style="font-size:.78rem;">
            <i class="bi bi-info-circle"></i>
            Remove or restrict <code>email_test.php</code> once email is confirmed working.
        </p>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
