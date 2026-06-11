<?php
/**
 * generate_billing_report.php — Office: generate / archive / view the FIA Billing Report
 *
 * GET /office/generate_billing_report.php?fia=345931[&save=1][&nophotos=1]
 *
 * Auth required: office session.
 *
 * Default behavior streams the PDF inline for review.
 * Pass &save=1 to (re)generate and overwrite the archived copy at
 *   {PRIVATE_PATH}/billing_reports/FIA_Report_{fia_number}.pdf
 * — this is the action billing should take when the report is finalised,
 * since these must be retained for at least one year.
 *
 * CNA (warranty_co_id = 1074):
 *   The billing report is automatically prepended with a single-page invoice.
 *   The invoice is first posted to QBO to obtain the invoice number, then
 *   rendered from the invoice shell template and merged with the billing report.
 *   The merged PDF is what gets streamed and/or archived.
 *
 *   On preview (no &save): the merged PDF is streamed. The QBO invoice IS
 *   created on preview — use &preview_only=1 to suppress QBO creation and
 *   render without a real invoice number (layout check only).
 *
 *   On &save=1: merged PDF is archived to
 *     {PRIVATE_PATH}/billing_reports/FIA_Report_{fia_number}.pdf
 *   and the invoice PDF is also archived separately to
 *     {PRIVATE_PATH}/billing_reports/FIA_Invoice_{inv_number}.pdf
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
    SELECT i.fia_number, i.warranty_co_id
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
$save            = !empty($_GET['save']);
$send            = !empty($_GET['send']);          // after save, redirect to email compose
$opts            = [];
if (!empty($_GET['nophotos'])) $opts['no_photos'] = true;

// ===========================================================================
// CNA FLOW — invoice page prepended to the billing report
// ===========================================================================
if ($is_cna) {
    require_once WEB_ROOT . '/qbo/qbo_invoice.php';

    if ($preview_only) {
        // Layout preview: render with placeholder invoice data, no QBO call
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
        $merged = _merge_invoice_and_billing($invoice_data, $fia, $db, $opts);
        _stream_pdf($merged, "FIA_Report_{$fia}_preview.pdf");
        exit;
    }

    // Real flow: create QBO invoice first
    $invoice_line_items = _build_cna_line_items($fia, $db);
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

    $merged = _merge_invoice_and_billing($invoice_data, $fia, $db, $opts);

    if ($save) {
        // Archive the merged report only (invoice page is already prepended)
        $report_path = billing_pdf_path($fia);
        $dir = dirname($report_path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($report_path, $merged);

        // Mark inspection as Invoiced and record the QBO invoice number
        _mark_inspection_invoiced($fia, $qbo_result['qb_invoice'], $qbo_result['qb_invoice_id'], $db);

        if ($send) {
            header("Location: /office/inspection.php?fia={$fia}&tab=emails&compose=billing");
        } else {
            header("Location: /office/inspection.php?fia={$fia}&tab=billing&report_saved=1");
        }
        exit;
    }

    _stream_pdf($merged, "FIA_Report_{$fia}.pdf");
    exit;
}

// ===========================================================================
// STANDARD FLOW — billing report only (all non-CNA clients)
// ===========================================================================
if ($save) {
    $path = billing_pdf_save($fia, $db);
    if ($path === false) {
        http_response_code(500);
        exit('Failed to generate or save the Billing Report.');
    }
    if ($send) {
        header("Location: /office/inspection.php?fia={$fia}&tab=emails&compose=billing");
    } else {
        header("Location: /office/inspection.php?fia={$fia}&tab=billing&report_saved=1");
    }
    exit;
}

billing_pdf_stream($fia, $db, '', $opts);

// ===========================================================================
// Helpers
// ===========================================================================

/**
 * Build the line-items array for qbo_create_invoice() from a single inspection.
 * Returns false if the inspection is not found.
 */
function _build_cna_line_items(int $fia, mysqli $db): array|false
{
    $stmt = $db->prepare("
        SELECT fia_number, inspection_type, claim_number, contract_number,
               insured, inspection_fee, base_fee, fuel_surcharge, special_charges
        FROM inspections WHERE fia_number = ? LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param('i', $fia);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;

    $fee = !empty($row['inspection_fee']) && (float)$row['inspection_fee'] > 0
        ? (float)$row['inspection_fee']
        : (float)($row['base_fee'] ?? 0)
          + (float)($row['fuel_surcharge'] ?? 0)
          + (float)($row['special_charges'] ?? 0);

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
