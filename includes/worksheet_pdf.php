<?php
/**
 * includes/worksheet_pdf.php
 *
 * Hub for the FIA In-Shop Worksheet PDF. Fetches data and delegates generation
 * to the template-based renderer (preferred approach).
 *
 * Active renderer: pdf/generate_worksheet_template.php
 *   Overlays data onto the CorelDraw-sourced shell PDF using FPDI + TCPDF.
 *
 * Legacy renderer: pdf/worksheet_build_legacy.php
 *   Builds the full worksheet entirely in code via TCPDF primitives.
 *   Not active — kept as a fallback if template-based generation ever needs
 *   to be bypassed. To switch, replace the generateWorksheet() call in
 *   worksheet_pdf_bytes() with _worksheet_build().
 *
 * Public API:
 *   worksheet_pdf_bytes(int $fia_number, mysqli $db): string|false
 *       Returns raw PDF bytes, or false if the inspection is not found.
 *
 *   worksheet_pdf_stream(int $fia_number, mysqli $db, string $filename = ''): void
 *       Streams the PDF directly to the browser as an inline download.
 *       Calls exit on completion.
 */

if (!defined('WEB_ROOT')) {
    require_once 'C:\inetpub\fia_private\config.php';
}
require_once WEB_ROOT . '/vendor/autoload.php';

// ---------------------------------------------------------------------------
// Internal: fetch all data needed for the worksheet
// ---------------------------------------------------------------------------
function _worksheet_fetch(int $fia, mysqli $db): array|false
{
    $stmt = $db->prepare("
        SELECT
            i.fia_number,
            i.claim_number,
            i.contract_number,
            i.insured,
            i.year, i.make, i.model, i.vin,
            i.color,
            i.tag_state,
            i.mileage,
            i.repair_shop,
            i.address           AS shop_address,
            i.city              AS shop_city,
            i.state_code        AS shop_state_code,
            i.zip               AS shop_zip,
            i.phone_number      AS shop_phone_number,
            i.contact           AS shop_contact,
            i.reason_for_inspection,
            i.customer_complaint,
            i.engine_size,
            i.transmission_type,
            i.tire_size,
            i.called_in_by,
            i.verbal_to,
            i.ro_no,
            i.ro_date,
            i.base_fee          AS inspector_base_fee,
            i.additional_mileage,
            i.total_pix,
            i.special_charges,
            i.quoted_fee,
            i.created_at,
            w.company_name,
            w.special_instructions  AS warranty_co_special_instructions,
            w.photo_instructions    AS warranty_co_photo_instructions,
            w.inspector_phone       AS warranty_co_inspector_phone,
            w.inspector_phone_ext   AS warranty_co_inspector_phone_ext,
            ins.full_name           AS inspector_full_name,
            ins.company             AS inspector_company,
            ins.address             AS inspector_address,
            ins.city                AS inspector_city,
            ins.state_code          AS inspector_state_code,
            ins.zip                 AS inspector_zip,
            ins.phone_cell          AS inspector_phone_cell,
            ins.phone_primary       AS inspector_phone_primary
        FROM inspections i
        LEFT JOIN warranty_co  w   ON w.warranty_co_id  = i.warranty_co_id
        LEFT JOIN inspectors   ins ON ins.inspector_id  = i.inspector_id
        WHERE i.fia_number = ?
        LIMIT 1
    ");
    if (!$stmt) return false;
    $stmt->bind_param('i', $fia);
    if (!$stmt->execute()) { $stmt->close(); return false; }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return false;

    // Format date/time from created_at timestamp
    $row['created_date'] = $row['created_at'] ? date('n/j/Y',   strtotime($row['created_at'])) : '';
    $row['created_time'] = $row['created_at'] ? date('g:i:s A', strtotime($row['created_at'])) : '';

    return $row;
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Generate worksheet PDF bytes. Returns false if inspection not found.
 */
function worksheet_pdf_bytes(int $fia, mysqli $db): string|false
{
    $data = _worksheet_fetch($fia, $db);
    if (!$data) return false;
    require_once __DIR__ . '/../pdf/generate_worksheet_template.php';
    return generateWorksheet($data);
}

/**
 * Stream the worksheet PDF to the browser.
 */
function worksheet_pdf_stream(int $fia, mysqli $db, string $filename = ''): void
{
    $bytes = worksheet_pdf_bytes($fia, $db);
    if ($bytes === false) {
        http_response_code(404);
        echo 'Inspection not found.';
        exit;
    }
    if (!$filename) {
        $filename = 'FIA_Worksheet_' . $fia . '.pdf';
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $bytes;
    exit;
}
