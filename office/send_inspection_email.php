<?php
/**
 * send_inspection_email.php — Email send handler for inspection detail page
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

$db  = get_db();
$fia = (int)($_POST['fia'] ?? 0);

if (!$fia) {
    header('Location: /office/index.php');
    exit;
}

$recipient_type = $_POST['recipient_type'] ?? 'inspector';  // 'inspector' or 'warco'
$cc             = trim($_POST['cc']      ?? '');
$subject        = trim($_POST['subject'] ?? '');
$body           = trim($_POST['body']    ?? '');

// Resolve To address based on recipient type
$to = $recipient_type === 'warco'
    ? trim($_POST['to_warco']    ?? '')
    : trim($_POST['to_inspector'] ?? '');

// Basic validation
if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !$subject || !$body) {
    header("Location: /office/inspection.php?fia={$fia}&tab=emails&compose=1&err=invalid");
    exit;
}

// Look up inspector_id and warranty_co_id
$stmt = $db->prepare("SELECT inspector_id, warranty_co_id FROM inspections WHERE fia_number = ? LIMIT 1");
$stmt->bind_param('i', $fia);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    header('Location: /office/index.php');
    exit;
}

$inspector_id   = $recipient_type === 'inspector' ? ((int)($row['inspector_id']   ?? 0) ?: null) : null;
$warranty_co_id = $recipient_type === 'warco'     ? ((int)($row['warranty_co_id'] ?? 0) ?: null) : null;

$result = fia_send_email($db, [
    'to'             => $to,
    'cc'             => $cc,
    'subject'        => $subject,
    'body'           => $body,
    'sent_by_email'  => $_SESSION['office_email'] ?? '',
    'sent_by_name'   => $_SESSION['office_name']  ?? '',
    'fia_number'     => $fia,
    'inspector_id'   => $inspector_id,
    'warranty_co_id' => $warranty_co_id,
]);

if ($result['ok']) {
    header("Location: /office/inspection.php?fia={$fia}&tab=emails&saved=1");
} else {
    // Log the error but still redirect — the failed send was logged to DB
    header("Location: /office/inspection.php?fia={$fia}&tab=emails&err=sendfail");
}
exit;
