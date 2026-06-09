<?php
/**
 * generate_billing_report.php — Office: generate / archive / view the FIA Billing Report
 *
 * GET /office/generate_billing_report.php?fia=345931[&save=1]
 *
 * Auth required: office session.
 *
 * Default behavior streams the PDF inline for review. Pass &save=1 to
 * (re)generate and overwrite the archived copy at
 *   {PRIVATE_PATH}/billing_reports/{fia_number}/FIA_Report_{fia_number}.pdf
 * — this is the action billing should take when the report is finalized,
 * since these must be retained for at least one year.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
require_once WEB_ROOT . '/includes/billing_pdf.php';

init_session();
require_office();

// PDF generation with large photo sets can exceed default limits.
// Both overrides apply to this request only — no php.ini change needed.
set_time_limit(0);
ini_set('memory_limit', '512M');

$db  = get_db();
$fia = (int)($_GET['fia'] ?? 0);

if (!$fia) {
    http_response_code(400);
    exit('Missing FIA number.');
}

$chk = $db->prepare("SELECT fia_number FROM inspections WHERE fia_number = ? LIMIT 1");
$chk->bind_param('i', $fia);
$chk->execute();
$chk->store_result();
if ($chk->num_rows === 0) {
    $chk->close();
    http_response_code(404);
    exit('Inspection not found.');
}
$chk->close();

if (!empty($_GET['save'])) {
    $path = billing_pdf_save($fia, $db);
    if ($path === false) {
        http_response_code(500);
        exit('Failed to generate or save the Billing Report.');
    }
    header("Location: /office/inspection.php?fia={$fia}&tab=billing&report_saved=1");
    exit;
}

$opts = [];
if (!empty($_GET['nophotos'])) $opts['no_photos'] = true;
billing_pdf_stream($fia, $db, '', $opts);
