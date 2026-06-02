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

// Verify the inspection exists (office can upload to any active inspection)
$chk = $db->prepare(
    "SELECT fia_number FROM inspections WHERE fia_number = ? AND is_archived = FALSE LIMIT 1"
);
$chk->bind_param('i', $fia);
$chk->execute();
if (!$chk->get_result()->fetch_assoc()) {
    header('Location: /office/index.php');
    exit;
}
$chk->close();

$redirect_base = "/office/inspection.php?fia={$fia}&tab=photos";
$upload_source = 'office';

require_once 'C:\inetpub\fia_private\upload_handler.php';
