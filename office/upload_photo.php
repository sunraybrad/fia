<?php
/**
 * upload_photo.php — Office photo upload
 * Auth and ownership checks only; upload logic is in the shared handler.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
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

// Verify the inspection exists and is still open for uploads
$chk = $db->prepare(
    "SELECT status FROM inspections WHERE fia_number = ? AND is_archived = FALSE LIMIT 1"
);
$chk->bind_param('i', $fia);
$chk->execute();
$chk_row = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$chk_row) {
    header('Location: /office/index.php');
    exit;
}
if (!in_array($chk_row['status'], ['Unassigned', 'Assigned'], true)) {
    header("Location: /office/inspection.php?fia={$fia}&tab=photos&locked=1");
    exit;
}

$redirect_base = "/office/inspection.php?fia={$fia}&tab=photos";
$upload_source = 'office';

require_once 'C:\inetpub\fia_private\upload_handler.php';
