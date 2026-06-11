<?php
/**
 * generate_billing_report.php — Office: per-inspection invoicing + billing deliverable
 *
 * GET /office/generate_billing_report.php?fia=345931[&save=1][&send=1][&nophotos=1]
 *                                                   [&preview_only=1][&report_only=1]
 *
 * Auth required: office session.
 *
 * Billing is per-inspection for ALL clients. The deliverable differs:
 *
 *   CNA (warranty_co_id = 1074):
 *     Invoice page + full Billing Report + photo appendix, merged into one PDF.
 *
 *   All other clients:
 *     Invoice PDF only — no report, no photos.
 *
 * In both cases the real flow is:
 *   1. Post the invoice to QBO (qbo_create_invoice) to get the invoice number.
 *   2. Render the deliverable PDF.
 *   3. On &save=1:
 *        - archive the deliverable to {PRIVATE_PATH}/billing_reports/FIA_Report_{fia}.pdf
 *          (this is the file the email compose flow attaches),
 *        - archive the invoice page separately as FIA_Invoice_{inv_number}.pdf,
 *        - mark the inspection Invoiced (invoice_no / inv_qb_no written back),
 *        - silently create the QBO Vendor Bill for the inspector
 *          (qbo/qbo_bill.php — bill_qb_no / bill_qb_id written back).
 *          Bill failure does NOT block the invoice: it is error-logged,
 *          and surfaced as a warning flash via &bill_warn=1 on the redirect.
 *   4. Without &save: the deliverable is streamed inline.
 *
 * Modifiers:
 *   &preview_only=1  Layout check: render with placeholder invoice data,
 *                    no QBO calls, nothing archived, nothing marked.
 *   &report_only=1   Stream the plain Billing Report (any client) with no
 *                    invoice and no QBO calls — internal review only.
 *   &archived=1      Stream the archived FIA_Report_{fia}.pdf as-is — no QBO,
 *                    no regeneration. This is the "view what was sent" path.
 *   &nophotos=1      Omit the photo appendix (CNA report).
 *
 * Already-Invoiced guard: once an inspection is Invoiced (or has inv_qb_no),
 * the real flow is refused server-side (redirects with &already_invoiced=1) —
 * regeneration would create a duplicate QBO invoice. The UI offers only
 * Re-send (email compose with the archived PDF) and View Archived.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
require_once WEB_ROOT . '/includes/billing_pdf.php';
require_once WEB_ROOT . '/includes/invoice_pdf.php';
require_once WEB_ROOT . '/vendor/autoload.php';

use setasign\Fpdi\Tcpdf\Fpdi;

init_session();
require_office();

// PDF generation with large photo sets can exceed default limits.
set_time_limit(0);
ini_set('memory_limit', '512M');

$db  = get_db();
$fia = (int)($_GET['fia'] ?? 0);

if (!$fia) {
    http_response_code(400);
    exit('Missing FIA number.');
}

// Fetch inspection + warranty company id
$chk = $db->prepare("
    SELECT i.fia_number, i.warranty_co_id, i.status, i.inv_qb_no
    FROM inspections i
    WHERE i.fia_number = ?
    LIMIT 1
");
$chk->bind_param('i', $fia);
$chk->execute();
$inspection_row = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$inspection_row) {
    http_response_code(404);
    exit('Inspection not found.');
}

$warranty_co_id  = (int)($inspection_row['warranty_co_id'] ?? 0);
$is_cna          = ($warranty_co_id === 1074);
$preview_only    = !empty($_GET['preview_only']);   // suppress QBO for layout check
$report_only     = !empty($_GET['report_only']);    // plain report, no invoice/QBO
$save            = !empty($_GET['save']);
$send            = !empty($_GET['send']);           // after save, redirect to email compose
$opts            = [];
if (!empty($_GET['nophotos'])) $opts['no_photos'] = true;

// ===========================================================================
// ARCHIVED — stream the already-archived deliverable, no QBO, no regeneration
// ===========================================================================
if (!empty($_GET['archived'])) {
    $archived_path = billing_pdf_path($fia);
    if (!is_file($archived_path)) {
        http_response_code(404);
        exit('No archived report found for this inspection.');
    }
    _stream_pdf((string)file_get_contents($archived_path), "FIA_Report_{$fia}.pdf");
    exit;
}

// ===========================================================================
// REPORT-ONLY — internal review of the Billing Report, no QBO involvement
// ===========================================================================
if ($report_only) {
    billing_pdf_stream($fia, $db, "FIA_Report_{$fia}_internal.pdf", $opts);
    exit;
}

// ===========================================================================
// ALREADY-INVOICED GUARD — a QBO invoice must never be created twice.
// Blocks the real flow (preview/report-only/archived are read-only and pass).
// Triggers on status OR a stored QBO invoice id, so a manual status change
// can't reopen the door while inv_qb_no still points at a live QBO invoice.
// ===========================================================================
$already_invoiced = ($inspection_row['status'] === 'Invoiced')
                 || trim($inspection_row['inv_qb_no'] ?? '') !== '';
if ($already_invoiced && !$preview_only) {
    header("Location: /office/inspection.php?fia={$fia}&tab=billing&already_invoiced=1");
    exit;
}

require_once WEB_ROOT . '/qbo/qbo_invoice.php';

// ===========================================================================
// PREVIEW — placeholder invoice data, no QBO calls, nothing archived
// ===========================================================================
if ($preview_only) {
    $qbo_meta = [
        'qb_invoice'     => 'PREVIEW',
        'qb_inv_date'    => date('n/j/Y'),
        'qb_inv_duedate' => '',
        'terms'          => '',
    ];
    $invoice_data = invoice_data_for_inspection($fia, $qbo_meta, $db);
    if ($invoice_data === false) {
        http_response_code(404);
        exit('Inspection not found.');
    }
    $bytes = $is_cna
        ? _merge_invoice_and_billing($invoice_data, $fia, $db, $opts)
        : invoice_pdf_bytes($invoice_data);
    _stream_pdf($bytes, "FIA_Report_{$fia}_preview.pdf");
    exit;
}

// ===========================================================================
// REAL FLOW — QBO invoice, then the per-client deliverable
// ===========================================================================
$invoice_line_items = _build_invoice_line_items($fia, $db);
if ($invoice_line_items === false) {
    http_response_code(404);
    exit('Inspection not found.');
}

$qbo_result = qbo_create_invoice($warranty_co_id, $invoice_line_items);
if (!$qbo_result['success']) {
    http_response_code(500);
    exit(h('Invoice creation failed: ' . $qbo_result['error']));
}

$qbo_meta = [
    'qb_invoice'     => $qbo_result['qb_invoice'],
    'qb_inv_date'    => $qbo_result['qb_inv_date'],
    'qb_inv_duedate' => $qbo_result['qb_inv_duedate'],
    'terms'          => $qbo_result['terms'],
];

$invoice_data = invoice_data_for_inspection($fia, $qbo_meta, $db);
if ($invoice_data === false) {
    http_response_code(500);
    exit('Failed to build invoice data.');
}

// CNA: invoice + report + photos merged. Everyone else: invoice only.
$deliverable = $is_cna
    ? _merge_invoice_and_billing($invoice_data, $fia, $db, $opts)
    : invoice_pdf_bytes($invoice_data);

if ($save) {
    // Archive the deliverable under the FIA_Report_{fia} name — the email
    // compose flow and the ≥1-year retention rule both key off this path.
    $report_path = billing_pdf_path($fia);
    $dir = dirname($report_path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($report_path, $deliverable);

    // Also archive the invoice page on its own (FIA_Invoice_{inv_number}.pdf).
    invoice_pdf_save($invoice_data);

    // Mark inspection as Invoiced and record the QBO invoice number
    _mark_inspection_invoiced($fia, $qbo_result['qb_invoice'], $qbo_result['qb_invoice_id'], $db);

    // Silently create the inspector's QBO Vendor Bill. Failure never blocks
    // the invoice — warn and let staff retry or enter the bill manually.
    $bill_warn = _create_vendor_bill_quietly($fia) ? '' : '&bill_warn=1';

    if ($send) {
        header("Location: /office/inspection.php?fia={$fia}&tab=emails&compose=billing{$bill_warn}");
    } else {
        header("Location: /office/inspection.php?fia={$fia}&tab=billing&report_saved=1{$bill_warn}");
    }
    exit;
}

_stream_pdf($deliverable, "FIA_Report_{$fia}.pdf");
exit;

// ===========================================================================
// Helpers
// ===========================================================================

/**
 * Build the line-items array for qbo_create_invoice() from a single inspection.
 * Returns false if the inspection is not found.
 */
function _build_invoice_line_items(int $fia, mysqli $db): array|false
{
    $stmt = $db->prepare("
        SELECT fia_number, inspection_type, claim_number, contract_number,
               insured, inspection_fee,
               fia_base_fee, fia_pix, fia_special_charges, fuel_surcharge
        FROM inspections WHERE fia_number = ? LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param('i', $fia);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;

    // inspection_fee if entered; otherwise the FM InspectionFee formula:
    // FIA Base Fee + FIA Pix + FIA Special Charges + FuelSurcharge
    $fee = !empty($row['inspection_fee']) && (float)$row['inspection_fee'] > 0
        ? (float)$row['inspection_fee']
        : (float)($row['fia_base_fee']        ?? 0)
          + (float)($row['fia_pix']             ?? 0)
          + (float)($row['fia_special_charges'] ?? 0)
          + (float)($row['fuel_surcharge']      ?? 0);

    return [[
        'inspection_type'    => $row['inspection_type']    ?? '',
        'fia_number'         => (string)$row['fia_number'],
        'claim_number'       => $row['claim_number']       ?? '',
        'contract_number'    => $row['contract_number']    ?? '',
        'insured'            => $row['insured']            ?? '',
        'inspection_fee'     => $fee,
    ]];
}

/**
 * Create the inspector's QBO Vendor Bill for this inspection.
 * Returns true on success (or if the bill already exists), false on failure.
 * Failures are error-logged here; the caller only signals the warning flash.
 */
function _create_vendor_bill_quietly(int $fia): bool
{
    require_once WEB_ROOT . '/qbo/qbo_bill.php';

    try {
        $r = qbo_create_vendor_bill($fia);
    } catch (Throwable $e) {
        error_log("Vendor bill creation threw for FIA {$fia}: " . $e->getMessage());
        set_flash('Invoice created, but the QBO vendor bill failed: ' . $e->getMessage(), 'warning');
        return false;
    }

    if (!$r['success']) {
        set_flash('Invoice created, but the QBO vendor bill failed: ' . $r['error']
            . ' Fix the cause and use Generate again, or enter the bill in QBO manually.', 'warning');
        return false;
    }
    return true;
}

/**
 * Render invoice PDF bytes prepended to billing report bytes.
 * Uses FPDI to merge: import all pages from both and output as one PDF.
 */
function _merge_invoice_and_billing(array $invoice_data, int $fia, mysqli $db, array $opts): string
{
    $invoice_bytes = invoice_pdf_bytes($invoice_data);
    $billing_bytes = billing_pdf_bytes($fia, $db, $opts);

    // Write bytes to temp files so FPDI can import them
    $inv_tmp  = tempnam(sys_get_temp_dir(), 'fia_inv_') . '.pdf';
    $bill_tmp = tempnam(sys_get_temp_dir(), 'fia_bill_') . '.pdf';

    file_put_contents($inv_tmp,  $invoice_bytes);
    file_put_contents($bill_tmp, $billing_bytes);

    $pdf = new Fpdi('P', 'pt', 'LETTER');
    $pdf->SetAutoPageBreak(false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Import invoice pages first
    $inv_count = $pdf->setSourceFile($inv_tmp);
    for ($p = 1; $p <= $inv_count; $p++) {
        $tpl = $pdf->importPage($p);
        $pdf->AddPage();
        $pdf->useTemplate($tpl, 0, 0, 612, 792);
    }

    // Then billing report pages
    $bill_count = $pdf->setSourceFile($bill_tmp);
    for ($p = 1; $p <= $bill_count; $p++) {
        $tpl = $pdf->importPage($p);
        $pdf->AddPage();
        $pdf->useTemplate($tpl, 0, 0, 612, 792);
    }

    $result = $pdf->Output('', 'S');

    @unlink($inv_tmp);
    @unlink($bill_tmp);

    return $result;
}

/**
 * Set status = 'Invoiced', store the QBO invoice number, and audit-log the change.
 * Called after PDF is successfully archived — only on confirmed saves, never on preview.
 */
function _mark_inspection_invoiced(int $fia, string $qb_invoice, string $qb_invoice_id, mysqli $db): void
{
    // Read current values for the audit diff
    $cur = $db->prepare("SELECT status, invoice_no, inv_qb_no FROM inspections WHERE fia_number = ? LIMIT 1");
    $cur->bind_param('i', $fia);
    $cur->execute();
    $old = $cur->get_result()->fetch_assoc() ?? [];
    $cur->close();

    $upd = $db->prepare("
        UPDATE inspections
        SET status     = 'Invoiced',
            invoice_no = ?,
            inv_qb_no  = ?
        WHERE fia_number = ?
    ");
    $upd->bind_param('ssi', $qb_invoice, $qb_invoice_id, $fia);
    $upd->execute();
    $upd->close();

    log_audit('inspection.invoiced', 'inspection', $fia, [
        'status'     => ['old' => $old['status']     ?? '', 'new' => 'Invoiced'],
        'invoice_no' => ['old' => $old['invoice_no'] ?? '', 'new' => $qb_invoice],
        'inv_qb_no'  => ['old' => $old['inv_qb_no']  ?? '', 'new' => $qb_invoice_id],
    ]);
}

/**
 * Stream raw PDF bytes to the browser.
 */
function _stream_pdf(string $bytes, string $filename): void
{
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
}
