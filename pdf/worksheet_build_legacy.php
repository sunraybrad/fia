<?php
/**
 * worksheet_build_legacy.php
 *
 * LEGACY — Code-built worksheet PDF (no shell template).
 *
 * This is the original implementation that constructs the entire 2-page
 * In-Shop Worksheet PDF from scratch using TCPDF primitives (rectangles,
 * lines, cells, etc.). It is NOT part of the active workflow.
 *
 * The preferred approach overlays data onto the CorelDraw-sourced shell
 * template via FPDI: see pdf/generate_worksheet_template.php.
 *
 * This file is kept for reference and as a fallback if the template-based
 * approach ever needs to be bypassed. Nothing in the application calls
 * _worksheet_build() directly — to invoke it you would swap the call in
 * includes/worksheet_pdf.php :: worksheet_pdf_bytes().
 *
 * Data contract: same $data array shape as generateWorksheet().
 * See includes/worksheet_pdf.php :: _worksheet_fetch() for the full key list.
 */

if (!defined('WEB_ROOT')) {
    require_once 'C:\inetpub\fia_private\config.php';
}
require_once WEB_ROOT . '/vendor/autoload.php';

/**
 * Build the worksheet PDF entirely in code and return raw bytes.
 *
 * @param array $d  Inspection data — same keys as generateWorksheet().
 * @return string   Raw PDF bytes.
 */
function _worksheet_build(array $d): string
{
    // Safe string helper
    $s = fn(string $k): string => (string)($d[$k] ?? '');

    // Derived values
    $verbal_phone  = trim($s('inspector_phone') . ' ' . $s('inspector_phone_ext'));
    $vehicle       = trim($s('year') . ' ' . $s('make') . ' ' . $s('model'));
    $insp_cityst   = trim($s('insp_city') . ', ' . $s('insp_state'));
    $req_dt        = $d['created_at']
        ? date('n/j/Y; g:i:s A', strtotime($d['created_at']))
        : '';

    // ── TCPDF setup ───────────────────────────────────────────────────────
    $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(8, 8, 8);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetLineWidth(0.3);

    // Layout constants (mm)
    $lm = 8;    // left margin / content start x
    $pw = 200;  // content width  (215.9 - 8 - 8 ≈ 200)
    $rm = $lm + $pw;  // right content edge

    // ── Helper: draw a labelled line (____) ──────────────────────────────
    // Not a closure — inline where needed.

    // =====================================================================
    // PAGE 1
    // =====================================================================
    $pdf->AddPage();

    // ── HEADER ────────────────────────────────────────────────────────────
    // FIA Number box (left)
    $pdf->Rect($lm, 6, 32, 12, 'D');
    $pdf->SetXY($lm, 7.5);
    $pdf->SetFont('helvetica', 'B', 6);
    $pdf->Cell(32, 0, 'FIA  Number', 0, 1, 'C');
    $pdf->SetXY($lm, 10);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(0, 51, 204);   // dark blue
    $pdf->Cell(32, 0, $s('fia_number'), 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);      // restore black

    // Center title
    $pdf->SetXY($lm + 33, 4);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(135, 7, 'FIA In-Shop Worksheet', 0, 2, 'C');
    $pdf->SetX($lm + 33);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(135, 5, 'Page 1 (of 2)', 0, 2, 'C');
    $pdf->SetX($lm + 33);
    $pdf->SetFont('helvetica', '', 6.5);
    $pdf->Cell(135, 5,
        'PO Box 1308, Largo, FL 33779  •  888-342-4678 ph  •  727-588-0580 fax',
        0, 0, 'C');

    // ADJUSTER box (right)
    $adj_x = $rm - 40;
    $pdf->Rect($adj_x, 6, 40, 12, 'D');
    $pdf->SetXY($adj_x + 1, 7.5);
    $pdf->SetFont('helvetica', '', 6.5);
    $pdf->Cell(38, 0, 'ADJUSTER:', 0, 2, 'C');
    $pdf->SetX($adj_x + 1);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(38, 0, $s('called_in_by'), 0, 0, 'C');

    // ── REQUEST DATE / REPORT FOR ──────────────────────────────────────────
    $y = 20;
    $pdf->SetXY($lm, $y);
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell(120, 0, 'Request Date/Time: ' . $req_dt, 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell($pw - 120, 0, 'Report For: ' . $s('warranty_co'), 0, 0, 'R');

    // ── INSTRUCTIONS BOX ──────────────────────────────────────────────────
    $ib_y  = 24;   // top of instructions box
    $ib_h  = 32;
    $ib_lw = 120;  // left instructions column width
    $ib_rw = $pw - $ib_lw;

    $pdf->SetLineWidth(0.2);
    $pdf->Rect($lm, $ib_y, $pw, $ib_h, 'D');
    $pdf->Line($lm + $ib_lw, $ib_y, $lm + $ib_lw, $ib_y + $ib_h);

    // Left: instructions
    $pdf->SetXY($lm + 1, $ib_y + 1);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell($ib_lw - 2, 4.5,
        'INSTRUCTIONS  -  Inspections to be completed within 24 hours of assignment',
        0, 2, 'L');

    $instructions = [
        '1. VERY IMPORTANT: Call shop with Estimated Time of Arrival.',
        '2. Upon arrival at shop, locate and attach repair order, any related service history, or TSB\'s if available.',
        '3. Inspect vehicle taking photos (if requested) of:',
        '      a. Odometer      b. VIN plate      c. Rear shot that includes Tag      d. ALL failed parts.',
        '4. DO NOT LEAVE SHOP BEFORE THIS STEP IS COMPLETE - Call in verbal report as stated below in request.',
        '5. Upload digital images online. (www.fiainspectors.com, login, find this report, upload)',
        '6. Complete report online (www.fiainspectors.com, login, find this report, complete)',
    ];
    $pdf->SetFont('helvetica', '', 6.5);
    foreach ($instructions as $line) {
        $pdf->SetX($lm + 1);
        $pdf->Cell($ib_lw - 2, 3.5, $line, 0, 2, 'L');
    }

    // Right: verbal call info
    $rv_x = $lm + $ib_lw + 2;
    $rv_w = $ib_rw - 3;
    $pdf->SetXY($rv_x, $ib_y + 2);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell($rv_w, 5, 'Call Verbal Into:', 0, 2, 'L');
    $pdf->SetX($rv_x);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell($rv_w, 5, $verbal_phone, 0, 2, 'L');
    $pdf->SetX($rv_x); $pdf->Cell($rv_w, 5, '', 0, 2); // spacer
    $pdf->SetX($rv_x);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell($rv_w, 5, 'Verbal To:', 0, 2, 'L');
    $pdf->SetX($rv_x);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell($rv_w, 5, $s('verbal_to'), 0, 0, 'L');

    // ── SHOP / CLAIM ───────────────────────────────────────────────────────
    $sc_y  = $ib_y + $ib_h + 1;  // ~75
    $sc_lw = 75;
    $sc_rw = $pw - $sc_lw;

    // Shop (left, bordered) — labels right-aligned, values left-aligned
    $pdf->SetLineWidth(0.2);
    $pdf->Rect($lm, $sc_y, $sc_lw, 23, 'D');
    $slabel_w = 20;  // fixed label column width
    $sval_w   = $sc_lw - $slabel_w - 2;
    $sx       = $lm + 1;
    $sc_rh    = 4.0;  // ← row height for shop/claim blocks — increase to add spacing
    $cityline = implode('  ', array_filter([$s('shop_city'), $s('shop_state'), $s('shop_zip')]));
    $shop_rows = [
        ['Repair Shop:',   $s('repair_shop'), 'B', 8],
        ['',               $s('shop_address'), '', 7.5],
        ['',               $cityline,          '', 7.5],
        ['Phone Number:',  $s('shop_phone'),   '', 7.5],
        ['Contact:',       $s('shop_contact'), '', 7.5],
    ];
    $pdf->SetXY($sx, $sc_y + 1);
    foreach ($shop_rows as [$label, $val, $style, $size]) {
        if ($val === '') continue;
        $pdf->SetX($sx);
        $pdf->SetFont('helvetica', 'B', 6.5);
        $pdf->Cell($slabel_w, $sc_rh, $label, 0, 0, 'R');
        $pdf->SetFont('helvetica', $style, $size);
        $pdf->Cell($sval_w, $sc_rh, '  ' . $val, 0, 2, 'L');
    }

    // Claim/Contract/Insured/Vehicle (right, bordered)
    $pdf->Rect($lm + $sc_lw, $sc_y, $sc_rw, 23, 'D');
    $cx = $lm + $sc_lw + 2;
    $cw = $sc_rw - 3;
    $pdf->SetXY($cx, $sc_y + 1);
    // Fixed label column (right-aligned) + value column (left-aligned)
    $label_w = 26;  // wide enough for 'VERIFY Vehicle Desc:'
    $val_w   = $cw - $label_w;
    $pairs = [
        ['Claim #:',             'claim_number'],
        ['Contract #:',          'contract_number'],
        ['Insured:',             'insured'],
        ['VERIFY Vehicle Desc:', ''],
    ];
    foreach ($pairs as [$label, $field]) {
        $pdf->SetFont('helvetica', 'B', 6.5);
        $pdf->Cell($label_w, $sc_rh, $label, 0, 0, 'R');
        $pdf->SetFont('helvetica', '', 8);
        $val = $field ? $s($field) : $vehicle;
        $pdf->Cell($val_w, $sc_rh, '  ' . $val, 0, 2, 'L');
        $pdf->SetX($cx);
    }

    // ── REASON FOR INSPECTION ─────────────────────────────────────────────
    $r_y = $sc_y + 23 + 1;   // ~106
    $pdf->SetXY($lm, $r_y);
    $pdf->SetFont('helvetica', 'B', 6.5);
    $pdf->Cell($pw, 5, 'Reason for Inspection:', 0, 2, 'L');
    $pdf->SetFont('helvetica', '', 7);
    // Use MultiCell for wrapping; maxh controls max height — increase for longer text
    $pdf->MultiCell($pw, 5, $s('reason_for_inspection'), 0, 'L',
        false, 0, $lm, $r_y + 5, true, 0, false, true, 40);

    // ── INSPECTOR / VIN AREA ──────────────────────────────────────────────
    $ir_y  = $r_y + 46;  // leave room for expanded reason field
    $ir_lw = 90;
    $ir_rw = $pw - $ir_lw;

    // Left: inspector details
    $pdf->SetXY($lm, $ir_y);
    $pdf->SetFont('helvetica', 'B', 6.5);
    $pdf->Cell(26, 5, 'Inspection Date:', 0, 0, 'L');
    $pdf->Cell(28, 5, '________________', 0, 0, 'L');
    $pdf->Cell(10, 5, 'Time:', 0, 0, 'L');
    $pdf->Cell($ir_lw - 64, 5, '_____________', 0, 2, 'L');

    $pdf->SetX($lm);
    $pdf->SetFont('helvetica', 'B', 6.5);
    $pdf->Cell(26, 5, 'Labor Rate Posted:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(5, 5, '$', 0, 0, 'L');
    $pdf->Cell(22, 5, '___________', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(8, 5, 'NO', 0, 2, 'L');

    $pdf->SetX($lm);
    $pdf->SetFont('helvetica', 'B', 6.5);
    $pdf->Cell(26, 5, 'VERIFY Mileage:', 0, 0, 'L');
    $pdf->Cell(28, 5, '________________', 0, 0, 'L');
    if ($d['mileage']) {
        $pdf->SetFont('helvetica', '', 6.5);
        $pdf->Cell($ir_lw - 54, 5, '(Reported: ' . $d['mileage'] . ')', 0, 0, 'L');
    }

    // Right: Customer Complaint + RO
    $cr_x = $lm + $ir_lw + 2;
    $cr_w = $ir_rw - 3;
    $ro_date_fmt = ($d['ro_date'] && $d['ro_date'] !== '0000-00-00')
        ? date('m-d-Y', strtotime($d['ro_date'])) : '';

    // Line 1: label | RO # value | RO Date value — all on one row
    $pdf->SetXY($cr_x, $ir_y);
    $pdf->SetFont('helvetica', 'B', 6.5);
    $pdf->Cell(30, 5, 'Customer Complaint:', 0, 0, 'L');
    $pdf->Cell(10, 5, 'RO #:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(28, 5, $s('ro_no'), 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 6.5);
    $pdf->Cell(15, 5, 'RO Date:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell($cr_w - 83, 5, $ro_date_fmt, 0, 2, 'L');

    // Line 2+: customer complaint text (pre-filled)
    $pdf->SetFont('helvetica', '', 8);
    $pdf->MultiCell($cr_w, 5, $s('customer_complaint'), 0, 'L',
        false, 0, $cr_x, $ir_y + 5, true, 0, false, true, 15);

    // ── VIN ROW ────────────────────────────────────────────────────────────
    $vin_y  = $ir_y + 16;   // ~140
    $vin_bw = $pw - 50;     // grid area width
    $cell_w = ($vin_bw - 4) / 17;

    $pdf->Rect($lm, $vin_y, $vin_bw, 6, 'D');
    for ($v = 1; $v < 17; $v++) {
        $pdf->Line($lm + 2 + $v * $cell_w, $vin_y, $lm + 2 + $v * $cell_w, $vin_y + 6);
    }
    // Cells left blank — inspector records VIN at shop; value shown in VIN= box only

    // VIN label (right of grid)
    $pdf->SetXY($rm - 47, $vin_y);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(47, 6, 'VIN: ' . $s('vin'), 1, 0, 'C');

    // ── COLOR / TAG / TRAILER HITCH ────────────────────────────────────────
    $ct_y = $vin_y + 9;    // ~149
    $pdf->SetXY($lm, $ct_y);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetDrawColor(180, 180, 180);          // light grey
    $pdf->Cell(18, 5, 'Vehicle Color:', 0, 0, 'L');
    $pdf->Cell(22, 5, '', '1', 0, 'L');
    $pdf->Cell(7, 5, 'Tag:', 0, 0, 'L');
    $pdf->Cell(22, 5, '', '1', 0, 'L');
    $pdf->Cell(14, 5, 'Tag State:', 0, 0, 'L');
    $pdf->Cell(12, 5, '', '1', 0, 'L');
    $pdf->SetDrawColor(0, 0, 0);          // revert to black
    $pdf->Cell(18, 5, 'Trailer Hitch:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell(4, 4, '', 1, 0, 'L');
    $pdf->Cell(8, 5, 'YES', 0, 0, 'L');
    $pdf->Cell(4, 4, '', 1, 0, 'L');
    $pdf->Cell(10, 5, 'NO', 0, 0, 'L');

    // ── FLUIDS (left col) + DRIVE INFO (right col) ─────────────────────────
    $fl_y  = $ct_y + 6;    // ~155
    $fl_lw = 95;
    $fl_rw = $pw - $fl_lw;
    $fl_rh = 5.5;          // fluid table row height
    $dr_rh = 6.3;            // drive info row height — increase to add spacing
    $cb_h  = 4;            // checkbox border size — independent of row spacing

    // Fluid table header
    $pdf->SetXY($lm, $fl_y);
    $pdf->SetFont('helvetica', 'B', 7);
    $col1 = $fl_lw * 0.30;
    $col2 = $fl_lw * 0.35;
    $col3 = $fl_lw * 0.35;
    $pdf->SetFillColor(184, 210, 232);  // light blue
    $pdf->Cell($col1, $fl_rh, '', 1, 0, 'C');
    $pdf->Cell($col2, $fl_rh, 'Levels', 1, 0, 'C', true);
    $pdf->Cell($col3, $fl_rh, 'Condition', 1, 1, 'C', true);
    $pdf->SetFillColor(255, 255, 255);  // restore white

    $fluids = [
        'Engine Oil', 'Transmission Fluid', 'Engine Coolant',
        'Power Steering', 'Brake Fluid',
    ];
    $pdf->SetFont('helvetica', '', 6.5);
    foreach ($fluids as $fname) {
        $pdf->SetX($lm);
        $pdf->Cell($col1, $fl_rh, $fname, 1, 0, 'R');
        $pdf->Cell($col2, $fl_rh, 'full   good   low   drained', 1, 0, 'C');
        $pdf->Cell($col3, $fl_rh, 'new   good   fair   poor', 1, 1, 'C');
    }

    // Description box below fluid table — content from warranty_co.special_instructions
    $desc_y = $fl_y + $fl_rh * 6;   // 1 header + 5 rows
    $pdf->Rect($lm, $desc_y, $fl_lw, 14, 'D');
    $pdf->SetXY($lm + 1, $desc_y + 1);
    $pdf->SetFont('helvetica', 'B', 6.5);
    $pdf->Cell($fl_lw - 2, 4, 'Special Instructions:', 0, 2, 'L');
    $pdf->SetX($lm + 1);
    $pdf->SetFont('helvetica', '', 6.5);
    $pdf->MultiCell($fl_lw - 2, 4, $s('special_instructions'), 0, 'L',
        false, 0, $lm + 1, $desc_y + 5, true, 0, false, true, 9);

    // Tire Size + blanks on left, below description box
    $mi_lines_short = ['Tire Size:'];                                          // stays within left column
    $mi_lines_full  = ['Modifications:', 'Commercial Use:', 'Towing:', 'Impact Damage:'];  // spans full width
    $mi_y = $desc_y + 14 + 1;
    $pdf->SetFont('helvetica', 'B', 7);
    foreach ($mi_lines_short as $label) {
        $pdf->SetXY($lm, $mi_y);
        $pdf->Cell(27, 5.5, $label, 0, 0, 'L');
        $pdf->Cell($fl_lw - 27, 5.5, '', 'B', 0, 'L');
        $mi_y += 5.5;
    }
    foreach ($mi_lines_full as $label) {
        $pdf->SetXY($lm, $mi_y);
        $pdf->Cell(30, 5.5, $label, 0, 0, 'L');
        $pdf->Cell($pw - 30, 5.5, '', 'B', 0, 'L');
        $mi_y += 5.5;
    }

    // Right column: drive info
    $dr_x = $lm + $fl_lw + 2;
    $dr_w = $fl_rw - 3;
    $dr_y = $fl_y;

    $pdf->SetXY($dr_x, $dr_y);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(20, $dr_rh, 'Towed/Driven:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell(4, $cb_h, '', 1, 0, 'C');
    $pdf->Cell(10, $dr_rh, 'Towed', 0, 0, 'L');
    $pdf->Cell(4, $cb_h, '', 1, 0, 'C');
    $pdf->Cell(14, $dr_rh, 'Driven', 0, 2, 'L');

    $pdf->SetX($dr_x);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(12, $dr_rh, 'Eng size:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(30, $dr_rh, '_________________', '', 0, 'L');
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell(4, $cb_h, '', 1, 0, 'L');
    $pdf->Cell(10, $dr_rh, 'Diesel', 0, 2, 'C');

    $pdf->SetX($dr_x);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(14, $dr_rh, 'Trans type:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell($dr_w - 20, $dr_rh, '________________________________', 0, 2, 'L');

    $pdf->SetX($dr_x);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(16, $dr_rh, 'Drive Train:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 7);
    foreach (['FWD', 'RWD', 'AWD', '4x4', 'Dually'] as $dt) {
        $pdf->Cell(4, $cb_h, '', 1, 0, 'C');
        $pdf->Cell(9, $dr_rh, $dt, 0, 0, 'L');
    }
    $pdf->Ln($dr_rh);

    $pdf->SetX($dr_x);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(24, $dr_rh, 'Overall Condition:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 7);
    foreach (['Excellent', 'Good', 'Fair', 'Poor'] as $oc) {
        $pdf->Cell(4, $cb_h, '', 1, 0, 'C');
        $pdf->Cell(14, $dr_rh, $oc, 0, 0, 'L');
    }
    $pdf->Ln($dr_rh + 2);

    // PHOTOS instruction box (right col, below drive info)
    $ph_y = $pdf->GetY();
    $ph_h = 13.5;
    $pdf->Rect($dr_x - 2, $ph_y, $dr_w + 2, $ph_h, 'D');
    $pdf->SetXY($dr_x - 1, $ph_y + 1);
    $pdf->SetFont('helvetica', 'B', 6.5);
    $pdf->MultiCell($dr_w, 4.5, 'Photo Instructions: ' . $s('photo_instructions'),
        0, 'L', false, 1, $dr_x - 1, $ph_y + 1);

    // ── BOTTOM: FEE | NOTICE | INSPECTOR INFO ─────────────────────────────
    $bot_y  = $mi_y + 2;
    $fee_w  = 68;
    $note_w = 70;
    $ii_w   = $pw - $fee_w - $note_w;

    // Fee section (left)
    $pdf->SetFont('helvetica', 'B', 7);
    $fee_rows = [
        ['Base Fee: $', number_format((float)($d['base_fee'] ?? 0), 0)],
        ['Addt\'l Mileage _____ @ .25', '___________'],
        ['35mm pix:      @ $1/ =',      '___________'],
        ['Total Inspection $',          '_______________'],
    ];
    foreach ($fee_rows as [$label, $val]) {
        $pdf->SetXY($lm, $bot_y);
        $pdf->Cell($fee_w * 0.55, 5.5, $label, 0, 0, 'R');
        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell($fee_w * 0.45, 5.5, $val, 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 7);
        $bot_y += 5.5;
    }

    // Special Charges row with Description appended to the right
    $pdf->SetXY($lm, $bot_y);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell($fee_w * 0.55, 5.5, 'Special Charges:', 0, 0, 'R');
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell($fee_w * 0.45, 5.5, '_______________', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(8, 5.5, 'Description:', 0, 0, 'R');
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell($note_w - 12, 5.5, '________________________________', 0, 0, 'L');
    $bot_y += 5.5;

    $pdf->SetXY($lm, $bot_y);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell($fee_w * 0.55, 5.5, 'You Quoted:', 0, 0, 'R');
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell($fee_w * 0.45, 5.5,
        $d['base_fee'] ? '$' . number_format((float)$d['base_fee'], 0) : '', 0, 0, 'L');
    $bot_y += 5.5;

    $pdf->SetXY($lm, $bot_y);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell($fee_w * 0.55, 5.5, 'YOUR INVOICE #', 0, 0, 'R');
    $pdf->Cell($fee_w * 0.45, 5.5, '_______________', 0, 0, 'L');

    // Important notice (center, bordered)
    $note_x = $lm + $fee_w - 6;
    $note_top = $bot_y - (5.5 * 6);  // align top with fee section start
    $pdf->Rect($note_x, $note_top, $note_w + 20, 13, 'DF', [], [255, 153, 153]);  // filled with light red
    $pdf->SetXY($note_x + 1, $note_top + 2);
    $pdf->SetFont('helvetica', '', 6);
    $pdf->MultiCell($note_w + 16, 3.5,
        "IMPORTANT: If all steps are not completed, FIA reserves the right to refuse payment. " .
        "The pictures, including signature page, must be on the website by the following morning, " .
        "as well as the report itself typed into the website, in order to receive payment normally. " .
        "The original paperwork must be filed and saved by you, for at least 3 years.",
        0, 'C', false, 1, $note_x + 1, $note_top + 1);

    // Inspector info (right)
    $ii_x   = $note_x + $note_w + 22;
    $ii_top = $note_top;
    $pdf->SetXY($ii_x, $ii_top);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell($ii_w - 2, 5, 'Inspector: ' . $s('inspector_name'), 0, 2, 'L');
    $pdf->SetX($ii_x);
    $pdf->SetFont('helvetica', '', 6.5);
    foreach ([
        'Paid to:',
        'Address: ' . $s('insp_address'),
        'City/ST: ' . $insp_cityst,
        'Fax: ' . $s('insp_fax'),
        'Who: ' . $s('typed_by'),
    ] as $line) {
        $pdf->Cell($ii_w - 2, 4.5, $line, 0, 2, 'L');
        $pdf->SetX($ii_x);
    }

    // =====================================================================
    // PAGE 2
    // =====================================================================
    $pdf->AddPage();

    // ── HEADER (matches page 1) ────────────────────────────────────────────
    $pdf->Rect($lm, 6, 32, 12, 'D');
    $pdf->SetXY($lm, 7.5);
    $pdf->SetFont('helvetica', 'B', 6);
    $pdf->Cell(32, 0, 'FIA  Number', 0, 1, 'C');
    $pdf->SetXY($lm, 10);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetTextColor(0, 51, 204);   // dark blue
    $pdf->Cell(32, 0, $s('fia_number'), 0, 0, 'C');
    $pdf->SetTextColor(0, 0, 0);      // restore black

    $pdf->SetXY($lm + 33, 4);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(135, 7, 'FIA In-Shop Worksheet', 0, 2, 'C');
    $pdf->SetX($lm + 33);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(135, 5, 'Page 2 (of 2)', 0, 2, 'C');
    $pdf->SetX($lm + 33);
    $pdf->SetFont('helvetica', '', 6.5);
    $pdf->Cell(135, 5,
        'PO Box 1308, Largo, FL 33779  •  888-342-4678 ph  •  727-588-0580 fax',
        0, 0, 'C');

    $adj_x2 = $rm - 40;
    $pdf->Rect($adj_x2, 6, 40, 12, 'D');
    $pdf->SetXY($adj_x2 + 1, 7.5);
    $pdf->SetFont('helvetica', '', 6.5);
    $pdf->Cell(38, 0, 'ADJUSTER:', 0, 2, 'C');
    $pdf->SetX($adj_x2 + 1);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(38, 0, $s('called_in_by'), 0, 0, 'C');

    // ── PAGE 2 CONTENT ────────────────────────────────────────────────────
    $p2 = 20;   // current y — matches page 1 header height

    // All four fields on one line — label + 1 space + value
    $pdf->Rect($lm, $p2 + 1, 200, 5, 'F', [], [194, 224, 239]);  // light blue fill
    $pdf->SetXY($lm + 1, $p2 + 1);
    foreach ([
        ['Repair Shop:',      'repair_shop'],
        ['Claim Number:',     'claim_number'],
        ['Contract Number:',  'contract_number'],
        ['Warranty Company:', 'warranty_co'],
    ] as [$label, $field]) {
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->Cell($pdf->GetStringWidth($label), 5, $label, 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell($pdf->GetStringWidth($s($field)) + 6, 5, ' ' . $s($field), 0, 0, 'L');
    }
    $p2 += 6;

    // State of teardown
    $pdf->SetXY($lm, $p2 + 2);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(25, 5, 'State of Teardown', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell(4, $cb_h, '', 1, 0, 'C');
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell(20, 5, 'Fully Assembled', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(22, 5, 'If not, how far?', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell($pw - 91, 5, '__________________________________________________', 0, 2, 'L');
    $p2 += 6;

    // Inspector's Report (lined area)
    $pdf->SetXY($lm, $p2 + 3);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetLineWidth(0.1);                    // thinner lines
    $pdf->SetDrawColor(180, 180, 180);          // light grey
    $pdf->Cell($pw, 6, "Inspector's Report:", 'B', 2, 'L');
    $pdf->SetFont('helvetica', '', 7);
        for ($li = 0; $li < 14; $li++) {
            $pdf->SetX($lm);
            $pdf->Cell($pw, 5, '', 'B', 1, 'L');
        }
    $p2 = $pdf->GetY() + 2;

    // Shop's Opinion
    $pdf->SetXY($lm, $p2);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(42, 5, "Shop's Opinion of Failure:", 'B', 0, 'L');
    $pdf->Cell($pw - 42, 5, '', 'B', 2, 'L');
    $pdf->SetX($lm);
    $pdf->Cell($pw, 5, '', 'B', 2, 'L');
    $pdf->SetX($lm);
    $pdf->Cell($pw, 5, '', 'B', 2, 'L');
    $p2 = $pdf->GetY() + 1;

    // Inspector's Opinion
    $pdf->SetXY($lm, $p2);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(44, 5, "Inspector's Opinion of Failure:", 'B', 0, 'L');
    $pdf->Cell($pw - 44, 5, '', 'B', 2, 'L');
    $pdf->SetX($lm);
    $pdf->Cell($pw, 5, '', 'B', 2, 'L');
    $pdf->SetX($lm);
    $pdf->Cell($pw, 5, '', 'B', 2, 'L');
    $p2 = $pdf->GetY() + 1;

    // Recommended Repairs
    $pdf->SetXY($lm, $p2);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(36, 5, 'Recommended Repairs:', 'B', 0, 'L');
    $pdf->Cell($pw - 36, 5, '', 'B', 2, 'L');
    $pdf->SetX($lm);
    $pdf->Cell($pw, 5, '', 'B', 2, 'L');
    $pdf->SetX($lm);
    $pdf->Cell($pw, 5, '', 'B', 2, 'L');
    $p2 = $pdf->GetY() + 1;

    // Service History checkboxes
    $pdf->SetXY($lm, $p2);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(54, $cb_h, 'Service History Related to Current Failure?', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 7);
    foreach ([['Not Available', 26], ['No', 14], ['Yes (explain below):', 38]] as [$opt, $ow]) {
        $pdf->Cell(4, $cb_h, '', 1, 0, 'C');
        $pdf->Cell($ow, $cb_h, $opt, 0, 0, 'L');
    }
    $pdf->Ln(5);
    $pdf->SetX($lm);
    $pdf->Cell($pw, 5, '', 'B', 2, 'L');
    $pdf->SetX($lm);
    $pdf->Cell($pw, 5, '', 'B', 2, 'L');
    $p2 = $pdf->GetY() + 1;

    // Report Called Into / Date / Time
    $pdf->SetXY($lm, $p2 + 6);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(30, 5, 'Report Called Into:', 0, 0, 'L');
    $pdf->Cell(50, 5, '____________________________', 0, 0, 'L');
    $pdf->Cell(22, 5, 'Date Called:', 0, 0, 'L');
    $pdf->Cell(36, 5, '___________________', 0, 0, 'L');
    $pdf->Cell(22, 5, 'Time Called:', 0, 0, 'L');
    $pdf->Cell($pw - 160, 5, '___________________', 0, 2, 'L');
    $p2 = $pdf->GetY() + 1;

    // Important notice box
    $pdf->SetLineWidth(0.4);
    $pdf->Rect($lm, $p2, $pw, 16, 'D');
    $pdf->writeHTMLCell($pw - 4, 5, $lm + 2, $p2 + 1.5,
        '<span style="color:#cc0000; font-size:10px;"><b><i>IMPORTANT : COMPLETE THIS 2-PAGE REPORT BEFORE LEAVING SHOP WITH SIGNATURES</i></b></span>',
        0, 1, false, true, 'C');
    $pdf->SetX($lm + 2);
    $pdf->SetFont('helvetica', '', 6);
    $pdf->MultiCell($pw - 4, 3.5,
        "I agree with the findings of the inspector except as noted in my comments above.\n" .
        "I realize that the inspector does not represent the administrator/insurer and that any " .
        "payment approval must be obtained through the plan administrator.\n" .
        "I am also aware that the inspector cannot authorize any repairs or tear down on this claim.",
        0, 'C', false, 1, $lm + 2, $p2 + 6.5);
    $p2 += 18;
    $pdf->SetLineWidth(0.2);  // restore default line width

    // Additional Remarks from Shop
    $pdf->SetXY($lm, $p2);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(38, 5, 'Additional Remarks from Shop:', 'B', 0, 'L');
    $pdf->Cell($pw - 38, 5, '', 'B', 2, 'L');
    $pdf->SetX($lm); $pdf->Cell($pw, 5, '', 'B', 1, 'L');
    $pdf->Cell($pw - 38, 5, '', 'B', 2, 'L');
    $pdf->SetX($lm); $pdf->Cell($pw, 5, '', 'B', 1, 'L');
    $p2 = $pdf->GetY() + 1;

    // Shop agree / Pictures taken
    $pdf->SetXY($lm, $p2);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(54, $cb_h, "Does shop agree with inspector's findings?", 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 7);
    foreach ([['Yes', 8], ['No', 12]] as [$opt, $ow]) {
        $pdf->Cell(4, $cb_h, '', 1, 0, 'C');
        $pdf->Cell($ow, $cb_h, $opt, 0, 0, 'L');
    }
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(22, $cb_h, 'Pictures Taken?:', 0, 0, 'L');
    $pdf->SetFont('helvetica', '', 7);
    foreach ([['Yes', 8], ['No', 10]] as [$opt, $ow]) {
        $pdf->Cell(4, $cb_h, '', 1, 0, 'C');
        $pdf->Cell($ow, $cb_h, $opt, 0, 0, 'L');
    }
    $pdf->SetLineWidth(0.2);  // restore default line width
    $p2 += 8;

    // Signature lines
    $sig_lw = $pw * 0.55;   // inspector sig + date
    $sig_rw = $pw - $sig_lw; // shop sig + date
    $sig_nl = $sig_lw * 0.72;
    $sig_dl = $sig_lw - $sig_nl - 4;

    $pdf->SetXY($lm, $p2 + 6);
    $pdf->Cell($sig_nl, 5, '', 'B', 0, 'L');
    $pdf->Cell(4, 5, '', 0, 0, 'C');
    $pdf->Cell($sig_dl, 5, '', 'B', 0, 'L');
    $pdf->Cell(4, 5, '', 0, 0, 'C');
    $sig_rn = $sig_rw * 0.72;
    $sig_rd = $sig_rw - $sig_rn - 4;
    $pdf->Cell($sig_rn, 5, '', 'B', 0, 'L');
    $pdf->Cell(4, 5, '', 0, 0, 'C');
    $pdf->Cell($sig_rd, 5, '', 'B', 2, 'L');

    $pdf->SetX($lm);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell($sig_nl, 5, 'Inspector Signature', 0, 0, 'L');
    $pdf->Cell(4, 5, '', 0, 0, 'C');
    $pdf->Cell($sig_dl, 5, 'Date', 0, 0, 'L');
    $pdf->Cell(4, 5, '', 0, 0, 'C');
    $pdf->Cell($sig_rn, 5, 'Shop Representative Signature', 0, 0, 'L');
    $pdf->Cell(4, 5, '', 0, 0, 'C');
    $pdf->Cell($sig_rd, 5, 'Date', 0, 0, 'L');
    $p2 = $pdf->GetY() + 8;

    // Print Shop Rep Name
    $pdf->SetXY($lm + $sig_lw - 15, $p2 + 6);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(35, 5, 'Print Shop Rep Name', 0, 0, 'C');
    $pdf->Cell(60, 5, '________________________________________', 0, 0, 'L');

    // Return raw bytes
    return $pdf->Output('', 'S');
}
