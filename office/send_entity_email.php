<?php
/**
 * send_entity_email.php — Unified email handler for inspector and warranty company pages.
 * Consolidates send_inspector_email.php and send_warco_email.php.
 *
 * POST params:
 *   type       — 'inspector' or 'warco'
 *   entity_id  — inspector_id or warranty_co_id
 *   to, cc, subject, body
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /office/index.php');
    exit;
}

verify_csrf();

$type = $_POST['type']      ?? '';
$id   = (int)($_POST['entity_id'] ?? 0);

if (!$id || !in_array($type, ['inspector', 'warco'], true)) {
    header('Location: /office/index.php');
    exit;
}

if ($type === 'inspector') {
    $list_url    = '/office/inspectors.php';
    $detail_base = "/office/inspector.php?id={$id}";
    $email_param = ['inspector_id' => $id];
} else {
    $list_url    = '/office/warranty_cos.php';
    $detail_base = "/office/warranty_co.php?id={$id}";
    $email_param = ['warranty_co_id' => $id];
}

$to      = trim($_POST['to']      ?? '');
$cc      = trim($_POST['cc']      ?? '');
$subject = trim($_POST['subject'] ?? '');
$body    = trim($_POST['body']    ?? '');

if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !$subject || !$body) {
    header("Location: {$detail_base}&tab=emails&err=invalid");
    exit;
}

$db     = get_db();
$result = fia_send_email($db, array_merge([
    'to'            => $to,
    'cc'            => $cc,
    'subject'       => $subject,
    'body'          => $body,
    'sent_by_email' => $_SESSION['office_email'] ?? '',
    'sent_by_name'  => $_SESSION['office_name']  ?? '',
], $email_param));

if ($result['ok']) {
    header("Location: {$detail_base}&tab=emails&saved=1");
} else {
    header("Location: {$detail_base}&tab=emails&err=sendfail");
}
exit;
