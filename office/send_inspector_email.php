<?php
/**
 * send_inspector_email.php — Send email from inspector detail page
 * Tags email with inspector_id only (no fia_number).
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /office/inspectors.php');
    exit;
}

verify_csrf();

$db           = get_db();
$inspector_id = (int)($_POST['inspector_id'] ?? 0);

if (!$inspector_id) {
    header('Location: /office/inspectors.php');
    exit;
}

$to      = trim($_POST['to']      ?? '');
$cc      = trim($_POST['cc']      ?? '');
$subject = trim($_POST['subject'] ?? '');
$body    = trim($_POST['body']    ?? '');

if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !$subject || !$body) {
    header("Location: /office/inspector.php?id={$inspector_id}&tab=emails&err=invalid");
    exit;
}

$result = fia_send_email($db, [
    'to'           => $to,
    'cc'           => $cc,
    'subject'      => $subject,
    'body'         => $body,
    'inspector_id' => $inspector_id,
]);

if ($result['ok']) {
    header("Location: /office/inspector.php?id={$inspector_id}&tab=emails&saved=1");
} else {
    header("Location: /office/inspector.php?id={$inspector_id}&tab=emails&err=sendfail");
}
exit;
