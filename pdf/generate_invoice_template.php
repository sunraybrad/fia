<?php
error_reporting(E_ALL & ~E_DEPRECATED);
/**
 * generate_invoice_template.php
 *
 * Generates the FIA Invoice PDF by overlaying invoice and inspection data
 * onto the invoice shell template, with multi-page support for batch invoices.
 *
 * Shell template:  templates/invoice-template-shell.pdf  (1 page, US Letter)
 * Field reference: templates/invoice-template-fields.pdf (yellow zones mark
 *                  each {placeholder}'s position — coordinates below were
 *                  extracted programmatically from that reference via pdfplumber)
 *
 * Usage:
 *   require_once 'pdf/generate_invoice_template.php';
 *   $pdfBytes = generateInvoice($data);
 *
 * $data keys (all pre-formatted strings unless noted):
 *   Header:
 *     qb_invoice      — QBO invoice number
 *     qb_inv_date     — invoice date (formatted)
 *     qb_inv_duedate  — due date (formatted)
 *     terms           — payment terms string (from QBO Customer)
 *     company_name    — warranty company name
 *     address         — billing address line 1
 *     city            — city
 *     state_code      — state abbreviation
 *     zip             — zip code
 *     qb_inv_total    — total amount (formatted, e.g. "1,625.00")
 *   Line items ($data['line_items'] — array of rows):
 *     date_of_inspection  — formatted date
 *     inspection_type     — e.g. "Mechanical Inspection"
 *     claim_number
 *     contract_number
 *     insured
 *     fia_number
 *     inspection_fee      — formatted dollar amount
 *
 * Multi-page: up to INV_ROWS_PER_PAGE (42) line items per page.
 * All pages share the same shell template and header data.
 * The footer shows "Page N of M".
 *
 * All coordinates are in PDF points (pt) measured from top-left of a
 * 612 × 792 pt (US Letter) page.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use setasign\Fpdi\Tcpdf\Fpdi;

// ---------------------------------------------------------------------------
// Layout constants
// ---------------------------------------------------------------------------
const INV_PAGE_W        = 612.0;
const INV_PAGE_H        = 792.0;
const INV_ROWS_PER_PAGE = 42;

// Line-item column x-positions and widths (extracted from fields PDF)
const INV_COL_DATE      = [22.9,  60.1];   // [x, w]
const INV_COL_TYPE      = [76.0,  52.6];
const INV_COL_CLAIM     = [146.3, 99.1];
const INV_COL_CONTRACT  = [252.3, 64.6];
const INV_COL_INSURED   = [323.0, 159.3];
const INV_COL_FIA       = [489.2, 39.1];
const INV_COL_FEE       = [532.7, 51.0];

// First data row y-position and row height/spacing
const INV_ROW_1_TOP     = 161.1;
const INV_ROW_STEP      = 13.6;
const INV_ROW_H         = 11.0;

/**
 * Generate the Invoice PDF.
 *
 * @param  array  $data  See file header for key list.
 * @return string        Raw PDF bytes.
 */
function generateInvoice(array $data): string
{
    $templatePath = __DIR__ . '/../templates/invoice-template-shell.pdf';

    $lineItems = $data['line_items'] ?? [];
    $totalRows = count($lineItems);
    $totalPages = max(1, (int)ceil($totalRows / INV_ROWS_PER_PAGE));

    $pdf = new Fpdi('P', 'pt', 'LETTER');
    $pdf->SetAutoPageBreak(false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // -------------------------------------------------------------------------
    // Helper closures (capture $pdf by reference)
    // -------------------------------------------------------------------------
    $cell = function (
        float $x, float $y, float $w, float $h,
        string $text, string $align = 'L'
    ) use ($pdf): void {
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, $text, 0, 0, $align);
    };

    // -------------------------------------------------------------------------
    // Build pages
    // -------------------------------------------------------------------------
    for ($page = 1; $page <= $totalPages; $page++) {
        $pdf->AddPage();
        $pdf->setSourceFile($templatePath);
        $tpl = $pdf->importPage(1);
        $pdf->useTemplate($tpl, 0, 0, INV_PAGE_W, INV_PAGE_H);

        // ---- HEADER BLOCK (same on every page) --------------------------------

        // Invoice number (large, bold, top-right)
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor(0, 62, 128);
        $cell(523.3, 34.8, 62.8, 14.3, $data['qb_invoice'] ?? '', 'C');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(0, 62, 128);

        // Invoice date / due date / terms (right column)
        $cell(518.5, 58.3, 72.2,  9.6, $data['qb_inv_date']    ?? '', 'L');
        $cell(518.5, 86.8, 72.2,  9.6, $data['terms']          ?? '', 'L');
        $cell(518.5, 101.8, 72.2, 9.6, $data['qb_inv_duedate'] ?? '', 'L');

        // Bill-to block (left): company name on first line, address lines below
        $cityLine = trim(
            ($data['city']       ?? '') . ', ' .
            ($data['state_code'] ?? '') . '  ' .
            ($data['zip']        ?? '')
        );
        $pdf->SetFont('helvetica', 'B', 12);
        $cell(83.0, 85.2,  202.0, 9.6, $data['company_name'] ?? '', 'L');
        $pdf->SetFont('helvetica', '', 10);
        $cell(83.0, 100.8, 202.0, 9.6, $data['address']      ?? '', 'L');
        $cell(83.0, 114.0, 202.0, 9.6, $cityLine,                   'L');

        // ---- LINE ITEMS -------------------------------------------------------
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(40, 40, 40);

        $startIdx = ($page - 1) * INV_ROWS_PER_PAGE;
        $pageItems = array_slice($lineItems, $startIdx, INV_ROWS_PER_PAGE);

        foreach ($pageItems as $i => $row) {
            $y = INV_ROW_1_TOP + $i * INV_ROW_STEP;

            $cell(INV_COL_DATE[0],     $y, INV_COL_DATE[1],     INV_ROW_H, $row['date_of_inspection'] ?? '', 'L');
            $cell(INV_COL_TYPE[0],     $y, INV_COL_TYPE[1],     INV_ROW_H, $row['inspection_type']    ?? '', 'L');
            $cell(INV_COL_CLAIM[0],    $y, INV_COL_CLAIM[1],    INV_ROW_H, $row['claim_number']       ?? '', 'L');
            $cell(INV_COL_CONTRACT[0], $y, INV_COL_CONTRACT[1], INV_ROW_H, $row['contract_number']    ?? '', 'L');
            $cell(INV_COL_INSURED[0],  $y, INV_COL_INSURED[1],  INV_ROW_H, $row['insured']            ?? '', 'L');
            $cell(INV_COL_FIA[0],      $y, INV_COL_FIA[1],      INV_ROW_H, $row['fia_number']         ?? '', 'R');
            $cell(INV_COL_FEE[0],      $y, INV_COL_FEE[1],      INV_ROW_H, $row['inspection_fee']     ?? '', 'R');
        }

        // ---- TOTAL (only on the last page - actually only 1 page) ------------------------------------
        // if ($page === $totalPages) {
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetTextColor(0, 62, 128);
            $cell(520.7, 721.0, 63.0, 11.0, '$' . ($data['qb_inv_total'] ?? ''), 'R');
        // }

        // ---- PAGE FOOTER ------------------------------------------------------
/*         $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(100, 100, 100);
        $cell(539.9, 760.0, 51.0, 11.0, "Page {$page} of {$totalPages}", 'C'); 
        omitted per client request
*/
    }

    return $pdf->Output('', 'S');
}
