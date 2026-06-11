<?php
/**
 * includes/invoice_pdf.php
 *
 * Hub for the FIA Invoice PDF — the cover page (or standalone invoice) sent
 * to warranty companies. Mirrors the pattern of includes/billing_pdf.php.
 *
 * Two use cases share this hub:
 *
 *   CNA (warranty_co_id = 1074) — individual inspection invoice, prepended to
 *   the Billing Report before emailing. One line item.
 *
 *   All others — batch invoice covering multiple inspections for one warranty
 *   company. Multiple line items (up to 42 per page).
 *
 * In both cases the caller is responsible for:
 *   1. Creating the QBO Invoice and retrieving the invoice metadata.
 *   2. Building the $invoice_data array (see below).
 *   3. Calling the appropriate function here.
 *
 * Renderer: pdf/generate_invoice_template.php  → generateInvoice(array $data)
 *
 * Storage:
 *   Invoice PDFs are archived outside the web root alongside Billing Reports:
 *       {PRIVATE_PATH}/billing_reports/FIA_Invoice_{qb_invoice_number}.pdf
 *   Re-generating overwrites the existing file. No DB tracking; filesystem is
 *   authoritative.
 *
 * $invoice_data structure:
 *   [
 *     // Header — from QBO and warranty_co table
 *     'qb_invoice'     => '1042',          // QBO invoice number (DocNumber)
 *     'qb_inv_date'    => '06/10/2026',    // invoice date, formatted M/D/Y
 *     'qb_inv_duedate' => '07/10/2026',    // due date, formatted M/D/Y
 *     'terms'          => 'Net 30',        // from QBO Customer TermsRef
 *     'company_name'   => 'GEICO',
 *     'address'        => '123 Main St',
 *     'city'           => 'Chevy Chase',
 *     'state_code'     => 'MD',
 *     'zip'            => '20815',
 *     'qb_inv_total'   => '1,625.00',      // formatted total
 *     // Line items — one per inspection
 *     'line_items'     => [
 *       [
 *         'date_of_inspection' => '05/19/2026',
 *         'inspection_type'    => 'Mechanical',
 *         'claim_number'       => '0302794180101089',
 *         'contract_number'    => 'MD124649',
 *         'insured'            => 'Johnson',
 *         'fia_number'         => '345851',
 *         'inspection_fee'     => '135.00',
 *       ],
 *       ...
 *     ],
 *   ]
 *
 * Public API:
 *   invoice_pdf_bytes(array $invoice_data): string
 *   invoice_pdf_path(string $invoice_number): string
 *   invoice_pdf_save(array $invoice_data): string|false
 *   invoice_pdf_stream(array $invoice_data, string $filename = ''): void
 *
 * Helper:
 *   invoice_data_for_inspection(int $fia, array $qbo_meta, mysqli $db): array|false
 *       Builds a single-inspection $invoice_data array from the DB + QBO meta.
 *       Useful for CNA individual flow.
 *
 *   invoice_data_for_batch(int $warranty_co_id, array $fia_numbers, array $qbo_meta, mysqli $db): array|false
 *       Builds a multi-inspection $invoice_data array from the DB + QBO meta.
 *       Useful for batch flow.
 */

if (!defined('WEB_ROOT')) {
    require_once 'C:\inetpub\fia_private\config.php';
}
require_once WEB_ROOT . '/vendor/autoload.php';

if (!defined('BILLING_REPORT_PATH')) {
    define('BILLING_REPORT_PATH', PRIVATE_PATH . '/billing_reports');
}

// ---------------------------------------------------------------------------
// Path helper
// ---------------------------------------------------------------------------

/**
 * Absolute path to the archived Invoice PDF for a given QBO invoice number.
 */
function invoice_pdf_path(string $invoice_number): string
{
    // Sanitise the invoice number for use in a filename
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $invoice_number);
    return BILLING_REPORT_PATH . '/FIA_Invoice_' . $safe . '.pdf';
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Generate raw Invoice PDF bytes from a pre-built $invoice_data array.
 */
function invoice_pdf_bytes(array $invoice_data): string
{
    require_once WEB_ROOT . '/pdf/generate_invoice_template.php';
    return generateInvoice($invoice_data);
}

/**
 * Generate and archive the Invoice PDF. Overwrites any existing file.
 * Returns the absolute path written, or false on failure.
 */
function invoice_pdf_save(array $invoice_data): string|false
{
    $bytes          = invoice_pdf_bytes($invoice_data);
    $invoice_number = $invoice_data['qb_invoice'] ?? 'unknown';
    $path           = invoice_pdf_path($invoice_number);

    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        error_log("invoice_pdf_save: failed to create directory {$dir}");
        return false;
    }
    if (file_put_contents($path, $bytes) === false) {
        error_log("invoice_pdf_save: failed to write {$path}");
        return false;
    }
    return $path;
}

/**
 * Stream the Invoice PDF directly to the browser.
 */
function invoice_pdf_stream(array $invoice_data, string $filename = ''): void
{
    $bytes = invoice_pdf_bytes($invoice_data);
    if (!$filename) {
        $inv = preg_replace('/[^A-Za-z0-9_\-]/', '_', $invoice_data['qb_invoice'] ?? 'invoice');
        $filename = 'FIA_Invoice_' . $inv . '.pdf';
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
}

// ---------------------------------------------------------------------------
// Data-builder helpers
// ---------------------------------------------------------------------------

/**
 * Build an $invoice_data array for a single inspection (CNA / individual flow).
 *
 * @param int    $fia      The FIA number of the inspection to invoice.
 * @param array  $qbo_meta Keys: qb_invoice, qb_inv_date, qb_inv_duedate, terms.
 *                         All are strings (formatted dates, invoice number, terms label).
 * @param mysqli $db
 * @return array|false     Returns false if the inspection is not found.
 */
function invoice_data_for_inspection(int $fia, array $qbo_meta, mysqli $db): array|false
{
    $stmt = $db->prepare("
        SELECT
            i.fia_number,
            i.date_of_inspection,
            i.inspection_type,
            i.claim_number,
            i.contract_number,
            i.insured,
            i.inspection_fee,
            i.base_fee,
            i.fuel_surcharge,
            i.special_charges,
            w.company_name,
            w.address,
            w.city,
            w.state_code,
            w.zip
        FROM inspections i
        LEFT JOIN warranty_co w ON w.warranty_co_id = i.warranty_co_id
        WHERE i.fia_number = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param('i', $fia);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return false;

    $fee = _invoice_compute_fee($row);

    return [
        'qb_invoice'     => $qbo_meta['qb_invoice']     ?? '',
        'qb_inv_date'    => $qbo_meta['qb_inv_date']    ?? '',
        'qb_inv_duedate' => $qbo_meta['qb_inv_duedate'] ?? '',
        'terms'          => $qbo_meta['terms']           ?? '',
        'company_name'   => $row['company_name'] ?? '',
        'address'        => $row['address']      ?? '',
        'city'           => $row['city']         ?? '',
        'state_code'     => $row['state_code']   ?? '',
        'zip'            => $row['zip']          ?? '',
        'qb_inv_total'   => number_format((float)$fee, 2),
        'line_items'     => [
            [
                'date_of_inspection' => $row['date_of_inspection'] ?? '',
                'inspection_type'    => $row['inspection_type']    ?? '',
                'claim_number'       => $row['claim_number']       ?? '',
                'contract_number'    => $row['contract_number']    ?? '',
                'insured'            => $row['insured']            ?? '',
                'fia_number'         => (string)$row['fia_number'],
                'inspection_fee'     => number_format((float)$fee, 2),
            ],
        ],
    ];
}

/**
 * Build an $invoice_data array for a batch of inspections (non-CNA flow).
 *
 * @param int    $warranty_co_id
 * @param int[]  $fia_numbers    Array of FIA numbers to include as line items.
 * @param array  $qbo_meta       Keys: qb_invoice, qb_inv_date, qb_inv_duedate, terms.
 * @param mysqli $db
 * @return array|false           Returns false if warranty company not found or
 *                               none of the FIA numbers resolve.
 */
function invoice_data_for_batch(int $warranty_co_id, array $fia_numbers, array $qbo_meta, mysqli $db): array|false
{
    if (empty($fia_numbers)) return false;

    // Fetch warranty company address
    $wstmt = $db->prepare("
        SELECT company_name, address, city, state_code, zip
        FROM warranty_co WHERE warranty_co_id = ? LIMIT 1
    ");
    if (!$wstmt) return false;
    $wstmt->bind_param('i', $warranty_co_id);
    $wstmt->execute();
    $warco = $wstmt->get_result()->fetch_assoc();
    $wstmt->close();

    if (!$warco) return false;

    // Fetch all inspections — use placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($fia_numbers), '?'));
    $istmt = $db->prepare("
        SELECT
            fia_number,
            date_of_inspection,
            inspection_type,
            claim_number,
            contract_number,
            insured,
            inspection_fee,
            base_fee,
            fuel_surcharge,
            special_charges
        FROM inspections
        WHERE fia_number IN ({$placeholders})
        ORDER BY date_of_inspection, fia_number
    ");
    if (!$istmt) return false;

    $types = str_repeat('i', count($fia_numbers));
    $istmt->bind_param($types, ...$fia_numbers);
    $istmt->execute();
    $rows = $istmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $istmt->close();

    if (empty($rows)) return false;

    $line_items = [];
    $total      = 0.0;

    foreach ($rows as $row) {
        $fee   = _invoice_compute_fee($row);
        $total += $fee;
        $line_items[] = [
            'date_of_inspection' => $row['date_of_inspection'] ?? '',
            'inspection_type'    => $row['inspection_type']    ?? '',
            'claim_number'       => $row['claim_number']       ?? '',
            'contract_number'    => $row['contract_number']    ?? '',
            'insured'            => $row['insured']            ?? '',
            'fia_number'         => (string)$row['fia_number'],
            'inspection_fee'     => number_format($fee, 2),
        ];
    }

    return [
        'qb_invoice'     => $qbo_meta['qb_invoice']     ?? '',
        'qb_inv_date'    => $qbo_meta['qb_inv_date']    ?? '',
        'qb_inv_duedate' => $qbo_meta['qb_inv_duedate'] ?? '',
        'terms'          => $qbo_meta['terms']           ?? '',
        'company_name'   => $warco['company_name'] ?? '',
        'address'        => $warco['address']      ?? '',
        'city'           => $warco['city']         ?? '',
        'state_code'     => $warco['state_code']   ?? '',
        'zip'            => $warco['zip']          ?? '',
        'qb_inv_total'   => number_format($total, 2),
        'line_items'     => $line_items,
    ];
}

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

/**
 * Return the effective inspection fee for one row.
 * Uses inspection_fee if set; otherwise sums base_fee + fuel_surcharge + special_charges.
 */
function _invoice_compute_fee(array $row): float
{
    if (!empty($row['inspection_fee']) && (float)$row['inspection_fee'] > 0) {
        return (float)$row['inspection_fee'];
    }
    return (float)($row['base_fee']       ?? 0)
         + (float)($row['fuel_surcharge'] ?? 0)
         + (float)($row['special_charges'] ?? 0);
}
