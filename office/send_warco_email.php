<?php
/**
 * send_warco_email.php — Send email from warranty company detail page
 * Tags email with warranty_co_id only (no fia_number).
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /office/warranty_cos.php');
    exit;
}

verify_csrf();

$db    = get_db();
$wc_id = (int)($_POST['warranty_co_id'] ?? 0);

if (!$wc_id) {
    header('Location: /office/warranty_cos.php');
    exit;
}

$to      = trim($_POST['to']      ?? '');
$cc      = trim($_POST['cc']      ?? '');
$subject = trim($_POST['subject'] ?? '');
$body    = trim($_POST['body']    ?? '');

if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !$subject || !$body) {
    header("Location: /office/warranty_co.php?id={$wc_id}&tab=emails&err=invalid");
    exit;
}

$result = fia_send_email($db, [
    'to'             => $to,
    'cc'             => $cc,
    'subject'        => $subject,
    'body'           => $body,
    'warranty_co_id' => $wc_id,
]);

if ($result['ok']) {
    header("Location: /office/warranty_co.php?id={$wc_id}&tab=emails&saved=1");
} else {
    header("Location: /office/warranty_co.php?id={$wc_id}&tab=emails&err=sendfail");
}
exit;
