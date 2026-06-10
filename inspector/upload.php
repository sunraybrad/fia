<?php
/**
 * upload.php — Inspector photo upload
 * Auth and ownership checks only; upload logic is in the shared handler.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_inspector();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /inspector/jobs.php');
    exit;
}

verify_csrf();

$db           = get_db();
$inspector_id = (int)$_SESSION['inspector_id'];
$fia          = (int)($_POST['fia'] ?? 0);

if (!$fia) {
    header('Location: /inspector/jobs.php');
    exit;
}

// Verify the inspection belongs to this inspector and is not complete
$own = $db->prepare(
    "SELECT status FROM inspections
      WHERE fia_number = ? AND inspector_id = ? AND is_archived = FALSE LIMIT 1"
);
$own->bind_param('ii', $fia, $inspector_id);
$own->execute();
$own_row = $own->get_result()->fetch_assoc();
$own->close();

if (!$own_row) {
    header('Location: /inspector/jobs.php');
    exit;
}
if (!in_array($own_row['status'], ['Unassigned', 'Assigned'], true)) {
    header("Location: /inspector/job.php?fia={$fia}&locked=1");
    exit;
}

$redirect_base = "/inspector/job.php?fia={$fia}";
$upload_source = 'inspector';

require_once 'C:\inetpub\fia_private\upload_handler.php';
