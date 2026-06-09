<?php
/**
 * send_inspection_email.php — Email send handler for inspection detail page
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/worksheet_pdf.php';
require_once __DIR__ . '/../includes/billing_pdf.php';
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
$template       = $_POST['template']       ?? '';
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

// Attach worksheet PDF for assignment emails
$attachments = [];
if ($template === 'assignment') {
    $pdf_bytes = worksheet_pdf_bytes($fia, $db);
    if ($pdf_bytes !== false) {
        $attachments[] = [
            'name'  => 'FIA_Worksheet_' . $fia . '.pdf',
            'bytes' => $pdf_bytes,
        ];
    }
}

// Attach the archived Billing Report for billing emails to the warranty co.
// Use the on-disk archive copy if it's been generated/saved already (so the
// client receives exactly what's on file); otherwise generate on the fly.
if ($template === 'billing') {
    $archived_path = billing_pdf_path($fia);
    $pdf_bytes = is_file($archived_path)
        ? file_get_contents($archived_path)
        : billing_pdf_bytes($fia, $db);

    if ($pdf_bytes !== false && $pdf_bytes !== null) {
        $attachments[] = [
            'name'  => 'FIA_Report_' . $fia . '.pdf',
            'bytes' => $pdf_bytes,
        ];
    }
}

// Manual file attachments — read bytes now; store to disk after we have email_id
$manual_files = [];
if (!empty($_FILES['manual_attachments']['tmp_name'])) {
    foreach ($_FILES['manual_attachments']['tmp_name'] as $i => $tmp) {
        if ($_FILES['manual_attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $bytes = file_get_contents($tmp);
        if ($bytes === false) continue;
        $name = basename($_FILES['manual_attachments']['name'][$i]);
        $manual_files[] = ['name' => $name, 'bytes' => $bytes];
        $attachments[]  = ['name' => $name, 'bytes' => $bytes];
    }
}

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
    'attachments'    => $attachments,
]);

// Log attachment records once we have the email_id
if ($result['email_id']) {
    $email_id = $result['email_id'];

    $att_stmt = $db->prepare(
        "INSERT INTO email_attachments (email_id, filename, file_ext, mime_type, legacy_path)
         VALUES (?, ?, ?, ?, ?)"
    );

    // Auto-generated worksheet — log metadata only, no stored file
    if ($template === 'assignment') {
        $fn   = 'FIA_Worksheet_' . $fia . '.pdf';
        $ext  = 'pdf';
        $mime = 'application/pdf';
        $path = null;
        $att_stmt->bind_param('issss', $email_id, $fn, $ext, $mime, $path);
        $att_stmt->execute();
    }

    // Manual files — store to disk and log path
    foreach ($manual_files as $file) {
        $dir = ATTACH_PATH . '/' . $email_id;
        if (!is_dir($dir)) {
            $mkdir_ok = mkdir($dir, 0755, true);
            if (!$mkdir_ok) {
                error_log('send_inspection_email: mkdir failed for ' . $dir . ' — check IIS permissions on ' . ATTACH_PATH);
                continue;
            }
        }
        $safe_name = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $file['name']);
        $dest      = $dir . '/' . $safe_name;
        $write_ok  = file_put_contents($dest, $file['bytes']);
        if ($write_ok === false) {
            error_log('send_inspection_email: file_put_contents failed for ' . $dest);
            continue;
        }
        $ext  = strtolower(pathinfo($safe_name, PATHINFO_EXTENSION));
        $mime = mime_content_type($dest) ?: 'application/octet-stream';
        $rel  = 'attachments/' . $email_id . '/' . $safe_name;
        $att_stmt->bind_param('issss', $email_id, $safe_name, $ext, $mime, $rel);
        $att_stmt->execute();
    }

    $att_stmt->close();
}

if ($result['ok']) {
    header("Location: /office/inspection.php?fia={$fia}&tab=emails&saved=1");
} else {
    header("Location: /office/inspection.php?fia={$fia}&tab=emails&err=sendfail");
}
exit;
