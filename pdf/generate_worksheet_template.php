<?php
error_reporting(E_ALL & ~E_DEPRECATED);
/**
 * generate_worksheet_template.php
 *
 * Generates the In-Shop Worksheet PDF (Page 1 of 2) by overlaying data
 * onto the shell template using FPDI + TCPDF.
 * 
 * Shell template: templates/worksheet-template-shell.pdf (2 pages)
 * CorelDraw source with yellow highlight zones for field coordinates: templates/worksheet-template-fields.pdf
 * Blank shell PDF generated from CorelDraw source: templates/worksheet-template-blank.pdf
 *
 * Usage:
 *   require_once('pdf/generate_worksheet_template.php');
 *   $pdfBytes = generateWorksheet($data);
 *   // Then either output inline or save to file
 *
 * All coordinates are in PDF points (pt), measured from top-left of page.
 * Page size: 612 x 792 pt (US Letter).
 * Coordinates extracted from worksheet-template-fields.pdf yellow zones.
 *
 * DATA GAP: $data['tire_size'] must come from inspection_tires table,
 * not inspections. Query that separately and merge before calling this function.
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * @param array $data  Keys documented below. All values should be pre-formatted strings.
 *
 * Required keys:
 *   From inspections table (unless noted):
 *     fia_number, called_in_by, created_date, created_time, company_name
 *     warranty_co_inspector_phone, warranty_co_inspector_phone_ext, verbal_to
 *     repair_shop, shop_address, shop_city, shop_state_code, shop_zip
 *     shop_phone_number, shop_contact
 *     claim_number, contract_number, insured, year, make, model
 *     ro_no, ro_date
 *     reason_for_inspection
 *     full_name as inspector_full_name (inspectors table)
 *     customer_complaint, mileage, vin
 *     color, tag_state
 *     engine_size, transmission_type
 *     warranty_co_special_instructions, warranty_co_photo_instructions
 *     tire_size  *** FROM inspections_tire table — query separately ***
 *     inspector_base_fee, additional_mileage, total_pix, special_charges, quoted_fee
 *     inspector_company, inspector_address, inspector_city,
 *     inspector_state_code, inspector_zip,
 *     inspector_phone_cell, inspector_phone_primary
 *
 * @return string  Raw PDF bytes
 */
function generateWorksheet(array $data): string
{
    $templatePath = __DIR__ . '/../templates/worksheet-template-shell.pdf';

    // -------------------------------------------------------------------------
    // PDF setup — units in points to match extracted coordinates directly
    // -------------------------------------------------------------------------
    $pdf = new Fpdi('P', 'pt', 'LETTER');
    $pdf->SetAutoPageBreak(false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();

    // Import shell template as background
    $pdf->setSourceFile($templatePath);
    $tpl = $pdf->importPage(1);
    $pdf->useTemplate($tpl, 0, 0, 612, 792); // Full page 8.5x11 inches at 72 pt/inch

    // -------------------------------------------------------------------------
    // Helper: place a single-line text field
    // $align: 'L' | 'C' | 'R'
    // -------------------------------------------------------------------------
    $cell = function(float $x, float $y, float $w, float $h, string $text, string $align = 'L') use ($pdf) {
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, $text, 0, 0, $align);
    };

    // Helper: place a multi-line text block (top-left origin)
    $multiCell = function(float $x, float $y, float $w, float $h, string $text) use ($pdf) {
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($w, 10, $text, 0, 'L', false, 1, $x, $y);
    };

    // -------------------------------------------------------------------------
    // Font defaults — adjust size per section as needed
    // -------------------------------------------------------------------------
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(0, 62, 128); // Dark blue for most fields, adjust to black for certain sections as needed

    // =========================================================================
    // HEADER
    // =========================================================================

    // FIA Number (blue box, top-left)
    $pdf->SetFont('helvetica', 'B', 14);
    $cell(27.7, 35.0, 62.9, 14.3, $data['fia_number'] ?? '', 'C');

    // Adjuster / Called In By (blue box, top-right)
    $pdf->SetFont('helvetica', '', 8);
    $cell(499.3, 33.0, 89.1, 18.4, $data['called_in_by'] ?? '', 'C');

    // Request Date / Time
    $datetime = trim(($data['created_date'] ?? '') . '  ' . ($data['created_time'] ?? ''));
    $cell(95.2, 61.5, 129.0, 9.6, $datetime, 'L');

    // "Report For: CompanyName" — right-justified
    $reportFor = 'Report For: ' . ($data['company_name'] ?? '');
    $cell(433.9, 61.5, 155.2, 9.6, $reportFor, 'R');

    // =========================================================================
    // CALL VERBAL INTO (top-right instructions block)
    // =========================================================================

    // Phone + ext stacked in the tall box (88.6 - 131.8)
    $phone = $data['warranty_co_inspector_phone'] ?? '';
    $ext   = $data['warranty_co_inspector_phone_ext'] ?? '';
    $phoneLines = $phone . ($ext ? "\nExt: $ext" : '');
    $multiCell(450.7, 88.6, 138.4, 43.2, $phoneLines);

    // Verbal To
    $cell(450.7, 145.7, 138.4, 12.4, $data['verbal_to'] ?? '', 'C');

    // =========================================================================
    // REPAIR SHOP BLOCK (left column)
    // =========================================================================

    $pdf->SetFont('helvetica', '', 7.5);
    $cell(86.0, 167.0, 166.5, 8.2, $data['repair_shop'] ?? '', 'L');
    $cell(86.0, 177.0, 166.5, 8.2, $data['shop_address'] ?? '', 'L');

    $shopCityLine = trim(
        ($data['shop_city'] ?? '') . ', ' .
        ($data['shop_state_code'] ?? '') . ' ' .
        ($data['shop_zip'] ?? '')
    );
    $cell(86.0, 187.0, 166.5, 8.2, $shopCityLine, 'L');
    $pdf->SetFont('helvetica', 'B', 9);
    $cell(86.0, 198.5, 166.5, 8.2, $data['shop_phone_number'] ?? '', 'L');
    $pdf->SetFont('helvetica', '', 7.5);
    $cell(86.0, 210.0, 166.5, 8.2, $data['shop_contact'] ?? '', 'L');

    // =========================================================================
    // CLAIM / CONTRACT / INSURED / VEHICLE BLOCK (center-right)
    // =========================================================================

    $cell(340.0, 167.8, 148.0, 8.2, $data['claim_number'] ?? '', 'L');
    $cell(340.0, 181.7, 148.0, 8.2, $data['contract_number'] ?? '', 'L');
    $cell(340.0, 195.6, 148.0, 8.2, $data['insured'] ?? '', 'L');

    $vehicleDesc = trim(
        ($data['year'] ?? '') . ' ' .
        ($data['make'] ?? '') . ' ' .
        ($data['model'] ?? '')
    );
    $cell(340.0, 208.8, 148.0, 8.2, $vehicleDesc, 'L');

    // =========================================================================
    // RO BLOCK (far right)
    // =========================================================================

    $cell(498.6, 176.7, 90.5, 12.4, $data['ro_no'] ?? '', 'L');
    $cell(498.6, 205.5, 90.5, 12.4, $data['ro_date'] ?? '', 'L');

    // =========================================================================
    // REASON FOR INSPECTION (full-width tall box)
    // =========================================================================

    $pdf->SetFont('helvetica', '', 7.5);
    $multiCell(66.6, 226.7, 523.8, 73.1, $data['reason_for_inspection'] ?? '');

    // =========================================================================
    // INSPECTOR NAME / INSPECTION DATE AREA
    // =========================================================================

    $cell(97.1, 305.3, 155.6, 12.4, $data['inspector_full_name'] ?? '', 'L');

    // VERIFY Mileage (Reported:)
    $cell(197.0, 348, 53.6, 12.4, $data['mileage'] ?? '', 'L');

    // =========================================================================
    // CUSTOMER COMPLAINT (right tall box)
    // =========================================================================

    $multiCell(303.9, 307.4, 287.4, 54.9, $data['customer_complaint'] ?? '');

    // =========================================================================
    // VIN
    // =========================================================================

    $pdf->SetFont('helvetica', 'B', 8);
    $cell(425.0, 371.0, 162.5, 12.4, $data['vin'] ?? '', 'L');

    // =========================================================================
    // VEHICLE COLOR / TAG / TAG STATE
    // =========================================================================

    $pdf->SetFont('helvetica', '', 8);
    $cell(72.0,  389.2, 86.4, 12.4, $data['color'] ?? '', 'L');
    $cell(181.4, 389.2, 53.6, 12.4, $data['tag'] ?? '', 'L'); 
    $cell(279.1, 389.2, 27.8, 12.4, $data['tag_state'] ?? '', 'L');

    // =========================================================================
    // ENGINE SIZE / TRANSMISSION TYPE
    // =========================================================================

    $cell(391.7, 427.4, 116.5, 12.4, $data['engine_size'] ?? '', 'L');
    $cell(391.7, 445.6, 196.8, 12.4, $data['transmission_type'] ?? '', 'L');

    // =========================================================================
    // SPECIAL INSTRUCTIONS / PHOTO INSTRUCTIONS
    // =========================================================================

    $pdf->SetFont('helvetica', '', 7.5);
    $multiCell(29.0,  492.1, 275.1, 31.2, $data['warranty_co_special_instructions'] ?? '');

    $photoText = 'Photos: ' . ($data['warranty_co_photo_instructions'] ?? '');
    $multiCell(314.7, 500.4, 276.6, 51.1, $photoText);

    // =========================================================================
    // TIRE SIZE
    // NOTE: tire_size comes from inspections_tire table, not inspections.
    //       Pass it in $data['tire_size'] after querying separately.
    // =========================================================================

    $pdf->SetFont('helvetica', '', 8);
    $cell(104.6, 543.2, 65.6, 10.6, $data['tire_size'] ?? '', 'L');

    // =========================================================================
    // PAYMENT BLOCK (bottom-left)
    // =========================================================================

    $cell(140.1, 658.2, 65.6, 10.6, $data['inspector_base_fee'] ?? '', 'L');
    $cell(140.1, 676.3, 65.6, 10.6, $data['additional_mileage'] ?? '', 'L');
    $cell(140.1, 694.4, 65.6, 10.6, $data['total_pix'] ?? '', 'L');
    $cell(140.1, 714.0, 65.6, 10.6, $data['special_charges'] ?? '', 'L');
    $cell(263.6, 732.8, 62.4, 10.6, $data['quoted_fee'] ?? '', 'L');

    // =========================================================================
    // INSPECTOR INFO BLOCK (bottom-right, 6 rows)
    // =========================================================================

    $inspCityLine = trim(
        ($data['inspector_city'] ?? '') . ', ' .
        ($data['inspector_state_code'] ?? '') . ' ' .
        ($data['inspector_zip'] ?? '')
    );

    $cell(450.4, 694.4, 142.0, 10.6, $data['inspector_full_name'] ?? '', 'L');
    $cell(450.4, 707.1, 142.0, 10.6, $data['inspector_company'] ?? '', 'L');
    $cell(450.4, 719.9, 142.0, 10.6, $data['inspector_address'] ?? '', 'L');
    $cell(450.4, 732.6, 142.0, 10.6, $inspCityLine, 'L');
    $cell(450.4, 745.4, 142.0, 10.6, $data['inspector_phone_cell'] ?? '', 'L');
    $cell(450.4, 758.1, 142.0, 10.6, $data['inspector_phone_primary'] ?? '', 'L');

    // =========================================================================
    // PAGE 2
    // =========================================================================

    $pdf->AddPage();
    $tpl2 = $pdf->importPage(2);
    $pdf->useTemplate($tpl2, 0, 0, 612, 792);

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(0, 62, 128); // Dark blue

    // HEADER — same fields as page 1
    $pdf->SetFont('helvetica', 'B', 14);
    $cell(27.7,  34.8,  62.9, 14.3, $data['fia_number'] ?? '', 'C');

    $pdf->SetFont('helvetica', '', 8);
    $cell(499.3, 33.0,  89.1, 19.7, $data['called_in_by'] ?? '', 'C');

    $datetime = trim(($data['created_date'] ?? '') . '  ' . ($data['created_time'] ?? ''));
    $cell(95.2,  61.5, 129.0,  9.6, $datetime, 'L');

    $reportFor = 'Report For: ' . ($data['company_name'] ?? '');
    $cell(433.9, 61.5, 155.2,  9.6, $reportFor, 'R');

    // REFERENCE ROW 1 (repair shop | insured | claim #)
    $pdf->SetFont('helvetica', '', 7.5);
    $cell( 70.6, 76.7, 180.0,  8.2, $data['repair_shop'] ?? '', 'L');
    $cell(293.0, 76.7, 148.6,  8.2, $data['insured'] ?? '', 'L');
    $cell(515.9, 76.7, 74.3,  8.2, $data['claim_number'] ?? '', 'L');

    // REFERENCE ROW 2 (shop address | year make model | contract #)
    $cell(70.6, 88.1, 180.0,  8.2, $data['shop_address'] ?? '', 'L');

    $vehicleDesc = trim(($data['year'] ?? '') . ' ' . ($data['make'] ?? '') . ' ' . ($data['model'] ?? ''));
    $cell(293.0, 90.1, 148.6,  8.2, $vehicleDesc, 'L');
    $cell(515.9, 90.1, 74.3,  8.2, $data['contract_number'] ?? '', 'L');

    // =========================================================================
    // Output
    // =========================================================================

    return $pdf->Output('', 'S');  // Return as string
}
