<?php
/**
 * generate_worksheet.php — Inspector portal: stream the FIA In-Shop Worksheet PDF
 *
 * GET /inspector/generate_worksheet.php?fia=345931
 *
 * Auth required: inspector session.
 * Ownership enforced: inspector can only print worksheets assigned to them.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
require_once WEB_ROOT . '/includes/worksheet_pdf.php';

init_session();
require_inspector();

$db           = get_db();
$inspector_id = (int)$_SESSION['inspector_id'];
$fia          = (int)($_GET['fia'] ?? 0);

if (!$fia) {
    http_response_code(400);
    exit('Missing FIA number.');
}

// Verify the inspection belongs to this inspector
$chk = $db->prepare(
    "SELECT fia_number FROM inspections
      WHERE fia_number   = ?
        AND inspector_id = ?
        AND is_archived  = FALSE
      LIMIT 1"
);
$chk->bind_param('ii', $fia, $inspector_id);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    $chk->close();
    http_response_code(403);
    exit('Inspection not found or not assigned to you.');
}
$chk->close();

worksheet_pdf_stream($fia, $db);
