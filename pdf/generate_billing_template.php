<?php
error_reporting(E_ALL & ~E_DEPRECATED);
/**
 * generate_billing_template.php
 *
 * Generates the FIA Billing Report PDF by overlaying inspection data onto the
 * CMI shell template (page 1), then appending a photo/video appendix.
 *
 * Shell template:  templates/cmi-template-shell.pdf   (1 page, the report face)
 * Field reference: templates/cmi-template-fields.pdf  (yellow zones mark each
 *                  {placeholder}'s position — coordinates below were measured
 *                  from that reference and WILL need a visual calibration pass
 *                  against a real render; see comments at each block).
 *
 * Usage:
 *   require_once('pdf/generate_billing_template.php');
 *   $pdfBytes = generateBillingReport($data);
 *
 * All coordinates are in PDF points (pt), measured from the top-left of the
 * page. Page size: 612 x 792 pt (US Letter), matching the worksheet renderer.
 *
 * NOTE — known data gaps to confirm before go-live:
 *   - {complete_vin} vs {vin}: the CMI template references {complete_vin};
 *     $data['complete_vin'] is preferred, with $data['vin'] as a fallback.
 *   - {current_mileage} vs {mileage}: same pattern — complete_/current_
 *     variants preferred, with the shorter column names as fallback, in case
 *     one or the other is the one actually populated on a given record.
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use setasign\Fpdi\Tcpdf\Fpdi;

// ---------------------------------------------------------------------------
// Appendix layout constants (photo/video grid)
// ---------------------------------------------------------------------------
const BR_PAGE_W      = 612.0;
const BR_PAGE_H      = 792.0;
const BR_GRID_COLS   = 2;
const BR_GRID_ROWS   = 3;
const BR_PER_PAGE    = BR_GRID_COLS * BR_GRID_ROWS; // 6
const BR_MARGIN      = 36.0;   // .5"
const BR_SLUG_H      = 22.0;   // slugline band at top of appendix pages
const BR_GUTTER      = 12.0;
const BR_CAPTION_H   = 12.0;

/**
 * @param array $data  See _billing_fetch() in includes/billing_pdf.php for
 *                     the full key list. All scalar values should already be
 *                     pre-formatted strings; $data['media'] is an array of
 *                     rows from the `pictures` table
 *                     (picture_id, image_path, caption, uploaded_at).
 *
 * @return string  Raw PDF bytes
 */
function generateBillingReport(array $data, array $opts = []): string
{
    $templatePath = __DIR__ . '/../templates/cmi-template-shell.pdf';

    $pdf = new Fpdi('P', 'pt', 'LETTER');
    $pdf->SetAutoPageBreak(false);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // =========================================================================
    // PAGE 1 — Report face (overlay onto shell template)
    // =========================================================================
    $pdf->AddPage();
    $pdf->setSourceFile($templatePath);
    $tpl = $pdf->importPage(1);
    $pdf->useTemplate($tpl, 0, 0, BR_PAGE_W, BR_PAGE_H);

    // Helper: place a single-line text field. $align: 'L' | 'C' | 'R'
    $cell = function(float $x, float $y, float $w, float $h, string $text, string $align = 'L') use ($pdf) {
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, $text, 0, 0, $align);
    };

    // Helper: place a multi-line text block (top-left origin)
    $multiCell = function(float $x, float $y, float $w, float $h, string $text, float $lineH = 10.0) use ($pdf) {
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($w, $lineH, $text, 0, 'L', false, 1, $x, $y);
    };

    $val = function(array $data, string $key, string $fallbackKey = '') {
        if (!empty($data[$key])) return $data[$key];
        if ($fallbackKey !== '' && !empty($data[$fallbackKey])) return $data[$fallbackKey];
        return '';
    };

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(0, 62, 128); // Dark blue — matches worksheet data-field color

    // -------------------------------------------------------------------
    // All coordinates below were extracted programmatically from the yellow
    // rectangle annotations in templates/cmi-template-fields.pdf (via
    // pdfplumber — exact x/top/width/height in points), then matched to the
    // {placeholder} label drawn over each zone. These are measured, not
    // estimated, and should land very close on the first render.
    // -------------------------------------------------------------------

    // HEADER
    $pdf->SetFont('helvetica', 'B', 14);
    $cell(27.7, 40, 62.9, 14.3, $data['fia_number'] ?? '', 'C');

    $pdf->SetFont('helvetica', '', 8);
    $cell(499.3, 37.5, 89.1, 19.7, $data['called_in_by'] ?? '', 'C');

    $datetime = trim(($data['created_date'] ?? '') . '; ' . ($data['created_time'] ?? ''));
    $cell(92.9, 66.4, 129.0, 9.6, $datetime, 'L');

    $reportFor = 'Report For: ' . ($data['company_name'] ?? '');
    $cell(434.4, 66.5, 155.2, 9.6, $reportFor, 'L');

    // REPAIR SHOP BLOCK (left column)
    $pdf->SetFont('helvetica', '', 8);
    $cell(76.3, 80.4,  180.6, 8.2, $data['repair_shop'] ?? '', 'L');
    $cell(76.3, 91.1,  180.6, 8.2, $data['shop_address'] ?? '', 'L');
    $shopCityLine = trim(
        ($data['shop_city'] ?? '') . ', ' .
        ($data['shop_state_code'] ?? '') . ' ' .
        ($data['shop_zip'] ?? '')
    );
    $cell(76.3, 101.8, 180.6, 8.2, $shopCityLine, 'L');
    $cell(76.3, 112.7, 180.6, 8.2, $data['shop_phone_number'] ?? '', 'L');
    $cell(76.3, 123.5, 180.6, 8.2, $data['shop_contact'] ?? '', 'L');

    // INSURED / VEHICLE / VIN / MILEAGE / TAG (center column)
    $cell(294.0, 80.4,  148.0, 8.2, $data['insured'] ?? '', 'L');
    $vehicleDesc = trim(($data['year'] ?? '') . ' ' . ($data['make'] ?? '') . ' ' . ($data['model'] ?? ''));
    $cell(294.0, 90.8,  148.0, 8.2, $vehicleDesc, 'L');
    $cell(294.0, 101.8, 148.0, 8.2, $val($data, 'complete_vin', 'vin'), 'L');
    $cell(294.0, 112.5, 43.9,  8.2, $val($data, 'current_mileage', 'mileage'), 'L');
    $tagLine = trim(($data['tag'] ?? '') . ' | ' . ($data['tag_state'] ?? ''));
    $cell(353.5, 112.5, 72.7, 8.2, $tagLine, 'L');

    // CLAIM / CONTRACT / RO / INSPECTION DATE / INSPECTOR (right column)
    $cell(491.4, 80.4,  73.7, 8.2, $data['claim_number'] ?? '', 'L');
    $cell(491.4, 90.8,  73.7, 8.2, $data['contract_number'] ?? '', 'L');
    $roLine = trim(($data['ro_no'] ?? '') . ' | ' . ($data['ro_date'] ?? ''));
    $cell(491.4, 101.8, 73.7, 8.2, $roLine, 'L');
    $cell(491.4, 112.5, 73.7, 8.2, $data['date_of_inspection'] ?? '', 'L');
    $cell(491.4, 123.4, 73.7, 8.2, $data['inspector_full_name'] ?? '', 'L');

    // VEHICLE / MECHANICAL STACK (far right column, below claim block)
    $pdf->SetFont('helvetica', '', 8);
    $cell(491.4, 140.0, 73.6, 8.2, $data['trailer_hitch'] ?? '', 'L');
    $cell(491.4, 153.1, 73.6, 8.2, $data['towed_driven'] ?? '', 'L');
    $cell(491.4, 166.2, 73.6, 8.2, $data['engine_size'] ?? '', 'L');
    $cell(491.4, 179.4, 73.6, 8.2, $data['transmission_type'] ?? '', 'L');
    $cell(491.4, 192.5, 73.6, 8.2, $data['drive_train'] ?? '', 'L');
    $cell(491.4, 205.7, 73.6, 8.2, $data['overall_condition'] ?? '', 'L');

    // CUSTOMER COMPLAINT (left, tall box)
    $pdf->setCellHeightRatio(1.4);   // default is 1.25
    $multiCell(17.9, 149.4, 274.3, 66.0, $data['customer_complaint'] ?? '');

    // FLUIDS TABLE (level / condition columns)
    // Row order per the CMI template: Engine Oil, Transmission Fluid,
    // Engine Coolant, Power Steering, Brake Fluid.
    $pdf->SetFont('helvetica', '', 8);
    $fluidRows = [
        153.1 => ['engine_oil_level',     'engine_oil_cond'],
        166.2 => ['trans_fluid_level',    'trans_fluid_cond'],
        179.3 => ['coolant_level',        'coolant_cond'],
        192.5 => ['power_steering_level', 'power_steering_cond'],
        205.6 => ['brake_fluid_level',    'brake_fluid_cond'],
    ];
    foreach ($fluidRows as $y => [$levelKey, $condKey]) {
        $cell(356.1, $y, 34.1, 8.2, $data[$levelKey] ?? '', 'C');
        $cell(394.4, $y, 34.1, 8.2, $data[$condKey] ?? '', 'C');
    }

    // MODIFICATIONS / COMMERCIAL USE / SHOP COMMENTS (left)
    $pdf->SetFont('helvetica', '', 8);
    $cell(82.7, 223.9, 237.5, 11.5, $data['modifications'] ?? '', 'L');
    $cell(81.6, 239.1, 238.1, 11.5, $data['commercial_use'] ?? '', 'L');
    $cell(81.6, 254.6, 238.0, 11.5, $data['shop_comments'] ?? '', 'L');

    // IMPACT DAMAGE / TEARDOWN / SERVICE HISTORY (right)
    $cell(387.9, 223.8, 206.1, 11.5, $data['impact_damage'] ?? '', 'L');
    $cell(387.9, 239.3, 206.1, 11.5, $data['amount_of_teardown'] ?? '', 'L');
    $cell(387.9, 254.9, 206.1, 11.5, $data['service_history_avail'] ?? '', 'L');

    // INSPECTOR'S REPORT (full-width, large box)
    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->setCellHeightRatio(1.4);   // default is 1.25
    $multiCell(17.9, 283.7, 576.1, 228.9, $data['inspectors_report'] ?? '');

    // INSPECTOR'S OPINION OF CAUSE (full-width box)
    $multiCell(17.9, 530.5, 576.1, 57.3, $data['cause_of_failure'] ?? '');

    // SHOP'S OPINION OF CAUSE (left) / RECOMMENDED REPAIRS (right)
    $multiCell(18.0,  608.5, 282.7, 67.5, $data['shop_of_failure'] ?? '');
    $multiCell(310.7, 608.5, 282.7, 67.5, $data['recommended_repairs'] ?? '');

    // FEES (left column)
    $pdf->SetFont('helvetica', '', 8);
    $cell(116.8, 686.2, 50.4, 11.5, $data['base_fee'] ?? '', 'L');
    $cell(116.8, 701.2, 50.4, 11.5, $data['fuel_surcharge'] ?? '', 'L');
    $cell(116.8, 717.0, 50.4, 11.5, $data['special_charges'] ?? '', 'L');
    $pdf->SetFont('helvetica', 'B', 7.5);
    $cell(116.8, 733.0, 50.4, 11.5, $data['fia_total_fee'] ?? ($data['inspection_fee'] ?? ''), 'L');

    // NOTES / SIGN-OFF / LABOR RATE (right column)
    $pdf->SetFont('helvetica', '', 8);
    $cell(391.1, 686.2, 203.0, 11.5, $data['notes_on_email'] ?? '', 'L');
    $signLine = trim(($data['did_shop_sign_report'] ?? '') . ' ' . ($data['shop_rep_name'] ?? ''));
    $cell(391.1, 701.2, 203.0, 11.5, $signLine, 'L');
    $cell(391.1, 717.2, 203.0, 11.5, $data['labor_rate'] ?? '', 'L');

    // =========================================================================
    // PHOTO / VIDEO APPENDIX
    // =========================================================================
    if (empty($opts['no_photos'])) {
        _billing_append_media_pages($pdf, $data);
    }

    return $pdf->Output('', 'S');  // Return as string
}

// ---------------------------------------------------------------------------
// Internal: append photo/video appendix pages (2 cols x 3 rows = 6 per page)
// ---------------------------------------------------------------------------
function _billing_append_media_pages(Fpdi $pdf, array $data): void
{
    $media = $data['media'] ?? [];
    if (empty($media)) return;

    $fia      = $data['fia_number'] ?? '';
    $logoPath = __DIR__ . '/../images/logo/logo_horiz_600_report.png';
    $hasLogo  = is_file($logoPath);

    $cellW = (BR_PAGE_W - 2 * BR_MARGIN - (BR_GRID_COLS - 1) * BR_GUTTER) / BR_GRID_COLS;
    $cellH = (BR_PAGE_H - 2 * BR_MARGIN - BR_SLUG_H - (BR_GRID_ROWS - 1) * BR_GUTTER) / BR_GRID_ROWS;

    $chunks   = array_chunk($media, BR_PER_PAGE);
    $pageNum  = 0;
    $numPages = count($chunks);

    foreach ($chunks as $chunk) {
        $pageNum++;
        $pdf->AddPage();

        // ---- Slugline -------------------------------------------------
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->SetDrawColor(180, 180, 180);
        $pdf->Line(BR_MARGIN, BR_MARGIN + BR_SLUG_H - 4, BR_PAGE_W - BR_MARGIN, BR_MARGIN + BR_SLUG_H - 4);

        if ($hasLogo) {
            $pdf->Image($logoPath, BR_MARGIN, BR_MARGIN - 2, 0, BR_SLUG_H - 6);
        }
        $pdf->SetXY(BR_MARGIN, BR_MARGIN);
        $pdf->Cell(BR_PAGE_W - 2 * BR_MARGIN, BR_SLUG_H - 6,
            "FIA #{$fia} — Inspection Photos   (Page {$pageNum} of {$numPages})", 0, 0, 'R');

        // ---- Grid ------------------------------------------------------
        $gridTop = BR_MARGIN + BR_SLUG_H + 6;
        foreach ($chunk as $i => $pic) {
            $col = $i % BR_GRID_COLS;
            $row = intdiv($i, BR_GRID_COLS);
            $x   = BR_MARGIN + $col * ($cellW + BR_GUTTER);
            $y   = $gridTop  + $row * ($cellH + BR_GUTTER);

            _billing_place_media_cell($pdf, $pic, $fia, $x, $y, $cellW, $cellH);
        }
    }
}

/**
 * Places a single photo or video poster into a grid cell, with caption and
 * (for video) a "watch online" link annotation.
 */
function _billing_place_media_cell(Fpdi $pdf, array $pic, $fia, float $x, float $y, float $w, float $h): void
{
    $imgAreaH = $h - BR_CAPTION_H;
    $sourcePath = rtrim(UPLOAD_PATH, '/') . '/' . $fia . '/' . $pic['image_path'];
    $isVideo    = function_exists('is_video') ? is_video($pic['image_path']) : (bool)preg_match('/\.(mp4|mov|avi|wmv|mpeg|mpg)$/i', $pic['image_path']);

    $imagePath = $sourcePath;
    $badge     = '';

    if ($isVideo) {
        $imagePath = _billing_video_poster($sourcePath, $fia, $pic['image_path']);
        $badge     = '> VIDEO';  // plain ASCII — Helvetica doesn't have the play glyph
    }

    // Dev fallback: local file doesn't exist → fetch from production /vPix/.
    // Skip videos — downloading the raw mp4 just to ignore it produces a blank
    // cell; the placeholder text below is more useful.
    if (!$isVideo && !is_file($imagePath) && defined('DEV_MODE') && DEV_MODE) {
        $imagePath = _billing_fetch_remote_image($fia, $pic['image_path']);
    }

    // Frame
    $pdf->SetDrawColor(210, 210, 210);
    $pdf->Rect($x, $y, $w, $imgAreaH);

    if ($imagePath && is_file($imagePath)) {
        // Resize to display resolution before TCPDF processes it — full-res
        // phone photos can be 4–8 MB each; at 6 per page the decode+re-encode
        // cost dominates render time. We cap at 2× the cell pixel size.
        $imagePath = _billing_resize_for_pdf($imagePath, (int)ceil($w * 1.5), (int)ceil($imgAreaH * 1.5));

        // Fit image within the cell, preserving aspect ratio, centered
        [$iw, $ih] = @getimagesize($imagePath) ?: [$w, $imgAreaH];
        $scale = min(($w - 4) / $iw, ($imgAreaH - 4) / $ih);
        $dw = $iw * $scale;
        $dh = $ih * $scale;
        $ix = $x + ($w - $dw) / 2;
        $iy = $y + ($imgAreaH - $dh) / 2;
        $pdf->Image($imagePath, $ix, $iy, $dw, $dh);

        if ($isVideo) {
            // Badge in the corner
            $pdf->SetFillColor(0, 0, 0);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('helvetica', 'B', 7);
            $pdf->SetXY($ix + 4, $iy + 4);
            $pdf->Cell(34, 12, $badge, 0, 0, 'C', true);

            // Clickable link to view the video online (client portal record)
            $videoUrl = _billing_video_url($fia, $pic['picture_id'] ?? null);
            if ($videoUrl) {
                $pdf->Link($x, $y, $w, $imgAreaH, $videoUrl);
            }
        }
    } else {
        $pdf->SetTextColor(150, 150, 150);
        $pdf->SetFont('helvetica', 'I', 7.5);
        $pdf->SetXY($x, $y + $imgAreaH / 2 - 5);
        $pdf->Cell($w, 10, $isVideo ? '[ video — preview unavailable ]' : '[ image unavailable ]', 0, 0, 'C');
    }

    // Caption
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetFont('helvetica', '', 7);
    $caption = trim($pic['caption'] ?? '');
    if ($isVideo && $caption === '') {
        $caption = 'Video — tap thumbnail to view online';
    }
    $pdf->SetXY($x, $y + $imgAreaH + 1);
    $pdf->Cell($w, BR_CAPTION_H - 2, $caption, 0, 0, 'C');
}

/**
 * Returns the absolute path to a cached poster-frame still for a video,
 * extracting it via ImageMagick on first use. Returns '' on failure.
 *
 * Cache location: {UPLOAD_PATH}/{fia}/_posters/{original filename}.jpg
 * (kept alongside the source so regenerating the report doesn't re-extract.)
 */
function _billing_video_poster(string $sourcePath, $fia, string $originalName): string
{
    if (!is_file($sourcePath)) return '';

    $posterDir  = rtrim(UPLOAD_PATH, '/') . '/' . $fia . '/_posters';
    $posterPath = $posterDir . '/' . $originalName . '.jpg';

    if (is_file($posterPath)) return $posterPath;

    if (!is_dir($posterDir) && !mkdir($posterDir, 0755, true)) {
        error_log("billing_pdf: failed to create poster cache dir {$posterDir}");
        return '';
    }

    if (!defined('MAGICK')) return '';

    // Grab the first frame as a still. The [0] selects frame 0 from the video.
    $cmd = MAGICK . ' ' . escapeshellarg($sourcePath . '[0]') . ' ' . escapeshellarg($posterPath) . ' 2>&1';
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0 || !is_file($posterPath)) {
        error_log("billing_pdf: poster extraction failed for {$sourcePath}: " . implode(' ', $output));
        return '';
    }

    return $posterPath;
}

/**
 * Resize a source image to fit within $maxW x $maxH pixels, writing to a
 * temp file. Tries GD first; falls back to ImageMagick if GD is not loaded.
 * Returns the temp path on success, or the original path if resizing fails or
 * the image is already within bounds.
 * Temp files are cleaned up by the OS — no manual housekeeping needed.
 */
function _billing_resize_for_pdf(string $srcPath, int $maxW, int $maxH): string
{
    $info = @getimagesize($srcPath);
    if (!$info) return $srcPath;
    [$srcW, $srcH] = $info;

    // Skip if already within bounds
    if ($srcW <= $maxW && $srcH <= $maxH) return $srcPath;

    $tmpPath = sys_get_temp_dir() . '/fia_billing_img_' . md5($srcPath . $maxW . $maxH) . '.jpg';
    if (is_file($tmpPath)) return $tmpPath;  // cached from earlier in this request

    // --- GD path ---
    if (function_exists('imagecreatefromjpeg')) {
        $type  = $info[2];
        $scale = min($maxW / $srcW, $maxH / $srcH);
        $dstW  = max(1, (int)round($srcW * $scale));
        $dstH  = max(1, (int)round($srcH * $scale));

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($srcPath),
            IMAGETYPE_PNG  => @imagecreatefrompng($srcPath),
            IMAGETYPE_GIF  => @imagecreatefromgif($srcPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false,
            default        => false,
        };
        if ($src) {
            $dst = imagecreatetruecolor($dstW, $dstH);
            if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP])) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefilledrectangle($dst, 0, 0, $dstW, $dstH,
                    imagecolorallocatealpha($dst, 255, 255, 255, 127));
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
            imagedestroy($src);
            imagejpeg($dst, $tmpPath, 75);
            imagedestroy($dst);
            return is_file($tmpPath) ? $tmpPath : $srcPath;
        }
    }

    // --- ImageMagick fallback ---
    if (defined('MAGICK')) {
        // {W}x{H}> means "shrink to fit, never enlarge, preserve aspect ratio"
        $geometry = "{$maxW}x{$maxH}>";
        $cmd = MAGICK . ' ' . escapeshellarg($srcPath)
             . ' -resize ' . escapeshellarg($geometry)
             . ' -quality 75'
             . ' ' . escapeshellarg($tmpPath) . ' 2>&1';
        exec($cmd, $out, $code);
        if ($code === 0 && is_file($tmpPath)) return $tmpPath;
        error_log("billing_pdf resize failed for {$srcPath}: " . implode(' ', $out));
    }

    return $srcPath;
}

/**
 * Dev-mode fallback: fetch an image from the production /vPix/ endpoint and
 * cache it in the system temp dir for the duration of the request. Returns
 * the temp path on success, '' on failure. The temp file is cleaned up by
 * the OS — no manual housekeeping needed.
 */
function _billing_fetch_remote_image($fia, string $imageName): string
{
    // Sanitise — same pattern as client/inspection.php
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $imageName)) return '';

    $url     = 'https://fiainspectors.com/vPix/' . urlencode((string)$fia) . '/' . rawurlencode($imageName);
    $tmpPath = sys_get_temp_dir() . '/fia_billing_' . md5($fia . '_' . $imageName) . '_' . pathinfo($imageName, PATHINFO_EXTENSION);

    if (is_file($tmpPath)) return $tmpPath;  // already cached this request

    $bytes = @file_get_contents($url);
    if ($bytes === false || strlen($bytes) < 100) {
        error_log("billing_pdf DEV: could not fetch remote image {$url}");
        return '';
    }

    file_put_contents($tmpPath, $bytes);
    return $tmpPath;
}

/**
 * Builds the URL the warranty co can use to view a video online (since PDF
 * viewers generally can't play embedded video reliably). Points at the
 * client-portal inspection record; adjust to a direct media URL if one
 * becomes available.
 */
function _billing_video_url($fia, $pictureId = null): string
{
    if (!defined('SITE_URL') || !$fia) return '';
    $url = rtrim(SITE_URL, '/') . '/client/inspection.php?fia=' . urlencode((string)$fia) . '#photos';
    if ($pictureId) {
        $url .= '&media=' . urlencode((string)$pictureId);
    }
    return $url;
}
