<?php
/**
 * import_inspectors.php
 * One-time reimport of inspectors from CSV.
 *
 * Access: office auth required — run from browser or CLI.
 * CLI:    php import_inspectors.php
 * Browser: https://fiainspectors.com/office/tools/import_inspectors.php
 *
 * What this script does:
 *   1. Reads data_migration/inspectors.csv by COLUMN NAME (not position)
 *   2. Applies all data transforms (see below)
 *   3. TRUNCATES inspectors table and reloads from scratch
 *
 * Transforms applied:
 *   Status   : 'NO'/'no' → 'Inactive'
 *              'Active'/'active' → 'Active'
 *              'Prospective'/'prospective' → 'Prospective'
 *              'Chemical Analysis Lab', 'Competitor', blank → 'Inactive'
 *   Zip      : if email embedded, try Zip5 fallback; strip to 10 chars
 *              Canadian postal codes kept as-is (won't match zip_codes table)
 *   SSN      : AES-256-CBC encrypted using AES_KEY from db.php
 *   Name     : records with blank Inspector name are skipped
 *   Rating   : leading/trailing whitespace stripped; empty → NULL
 *   Dates    : M/D/YYYY → YYYY-MM-DD; invalid → NULL
 *   Decimals : strip $ and commas; non-numeric → NULL
 *
 * Skipped FM fields (not in schema):
 *   Alt, Info, InspectionCount, Pager (legacy), PhoneType3/4/5,
 *   Picture Directory, Prime, Zip5 (used only as fallback here)
 */

// ── Force errors visible before config.php silences them ─────────────────
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ── Allow CLI execution without HTTP session ──────────────────────────────
$is_cli = (php_sapi_name() === 'cli');

if (!$is_cli) {
    require_once 'C:\inetpub\fia_private\config.php';
    require_once __DIR__ . '/../includes/auth.php';
    init_session();
    require_office();
}

require_once 'C:\inetpub\fia_private\db.php';

// ── Config ────────────────────────────────────────────────────────────────
// If auto-detection fails, set this path explicitly and re-run:
$csv_override = 'D:\Clients\inetpub\wwwroot\FIA\data_migration\inspectors.csv';  // e.g. 'C:\path\to\inspectors.csv'

if ($csv_override !== '') {
    $csv_path = $csv_override;
} else {
    $candidates = [
        'C:\Users\Brad\Documents\Claude\Projects\FIA Migration\data_migration\inspectors.csv',
        'C:\inetpub\fia_private\migration\inspectors.csv',
    ];
    $csv_path = null;
    foreach ($candidates as $c) {
        if (file_exists($c)) { $csv_path = $c; break; }
    }
}

if (!$csv_path || !file_exists($csv_path)) {
    $nl = (php_sapi_name() === 'cli') ? "\n" : "<br>\n";
    echo "ERROR: Cannot find inspectors.csv{$nl}";
    echo "Checked:{$nl}";
    foreach ($candidates ?? [$csv_override] as $c) {
        echo "  [{$c}] — " . (file_exists($c) ? 'FOUND' : 'not found') . "{$nl}";
    }
    echo "{$nl}Set \$csv_override at the top of this script to the exact file path.{$nl}";
    exit;
}

$db = get_db();

// ── Helpers ───────────────────────────────────────────────────────────────

// clean_varchar trims to the column's max length — use for VARCHAR fields
function clean_varchar(?string $v, int $max): ?string {
    $v = clean_str($v);
    return $v !== null ? substr($v, 0, $max) : null;
}

function clean_str(?string $v): ?string {
    if ($v === null) return null;
    // Convert Latin-1/Windows-1252 (FileMaker CSV encoding) to UTF-8 for MySQL
    $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    // FileMaker uses \r and \x0b (vertical tab) as internal line separators — normalize all to \n
    $v = str_replace(["\r\n", "\r", "\x0b"], "\n", $v);
    $v = trim($v);
    return $v === '' ? null : $v;
}

function clean_dec(?string $v): ?string {
    if ($v === null) return null;
    $v = trim(str_replace(['$', ','], '', $v));
    return ($v === '' || !is_numeric($v)) ? null : $v;
}

function clean_date(?string $v): ?string {
    if (!$v || trim($v) === '') return null;
    $v = trim($v);
    // M/D/YYYY or M/D/YY
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $v, $m)) {
        $y = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
        $dt = sprintf('%04d-%02d-%02d', $y, $m[1], $m[2]);
        return ($dt < '1900-01-01' || $dt > '2100-01-01') ? null : $dt;
    }
    return null;
}

function clean_zip(?string $zip, ?string $zip5): ?string {
    $zip  = trim($zip  ?? '');
    $zip5 = trim($zip5 ?? '');

    // Email concatenated into zip field — try Zip5 fallback
    if (str_contains($zip, '@')) {
        // Sometimes the real zip prefix is at the start before the email
        if (preg_match('/^\d{5}/', $zip, $m)) return $m[0];
        // Use Zip5 if it looks clean
        if (preg_match('/^\d{5}/', $zip5)) return substr($zip5, 0, 5);
        return null;
    }

    if ($zip === '') return null;

    // Strip trailing extension (52761-3506 → 52761-3506, keep full for VARCHAR 10)
    // Cap at 10 chars to fit schema
    return substr($zip, 0, 10) ?: null;
}

function normalize_status(?string $v): string {
    $v = strtolower(trim($v ?? ''));
    return match(true) {
        $v === 'active'      => 'Active',
        $v === 'prospective' => 'Prospective',
        default              => 'Inactive',  // NO, no, inactive, blank, anything else
    };
}

function encrypt_ssn(?string $ssn): ?string {
    if (!$ssn || trim($ssn) === '') return null;
    $ssn = trim($ssn);
    if (!defined('AES_KEY')) return null;
    $iv         = random_bytes(16);
    $encrypted  = openssl_encrypt($ssn, 'AES-256-CBC', AES_KEY, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) return null;
    // Store as base64(iv):base64(ciphertext)
    return base64_encode($iv) . ':' . base64_encode($encrypted);
}

// ── Read CSV ──────────────────────────────────────────────────────────────
// Open in BINARY mode so PHP doesn't mangle \r characters before fgetcsv sees them.
// fgetcsv(..., 0) = no length limit — this enables proper multiline quoted field
// support, which is required for FileMaker exports where \r and \x0b appear
// inside quoted fields and would otherwise split a record across multiple reads.

$handle = fopen($csv_path, 'rb');
if (!$handle) die("ERROR: Cannot open $csv_path\n");

$csv_rows   = [];
$header     = fgetcsv($handle, 0);
// Strip UTF-8 BOM from first header column if present
$header[0]  = ltrim($header[0], "\xEF\xBB\xBF");
$col_count  = count($header);

while (($row = fgetcsv($handle, 0)) !== false) {
    // Skip completely blank rows
    if ($row === [null]) continue;

    // Pad short rows (can happen if trailing empty fields are omitted)
    while (count($row) < $col_count) $row[] = '';

    // Truncate over-long rows (shouldn't happen, but be safe)
    $row = array_slice($row, 0, $col_count);

    $csv_rows[] = array_combine($header, $row);
}
fclose($handle);

// ── Truncate and reload ───────────────────────────────────────────────────

$db->query("SET FOREIGN_KEY_CHECKS = 0");
$db->query("TRUNCATE TABLE inspectors");
$db->query("SET FOREIGN_KEY_CHECKS = 1");

$stmt = $db->prepare(
    "INSERT INTO inspectors (
        inspector_id, full_name, legacy_pin, ssn_encrypted,
        status, appraiser_id,
        address, city, state_code, zip, country,
        phone_primary, phone_cell, fax, phone_pager, phone_alternate,
        phone_alt_label, phone_type_1, phone_type_2,
        email,
        base_fee, base_price_notes, mileage_fee_notes, picture_fee_notes,
        online_billing, quickbooks_ref, company, camera_type,
        rating, comments, inspector_notes, restrictions,
        created_by, date_created, date_modified,
        is_archived
    ) VALUES (
        ?,?,?,?,
        ?,?,
        ?,?,?,?,?,
        ?,?,?,?,?,
        ?,?,?,
        ?,
        ?,?,?,?,
        ?,?,?,?,
        ?,?,?,?,
        ?,?,?,
        0
    )"
);

$inserted = 0;
$skipped  = 0;
$skip_log = [];

foreach ($csv_rows as $line_num => $r) {
    $name = clean_varchar($r['Inspector'] ?? null, 150);

    // Skip blank-name records
    if (!$name) {
        $skipped++;
        $skip_log[] = "Row " . ($line_num + 2) . ": blank name (ID=" . ($r['InspectorID'] ?? '?') . ")";
        continue;
    }

    $id = (int)($r['InspectorID'] ?? 0);
    if (!$id) {
        $skipped++;
        $skip_log[] = "Row " . ($line_num + 2) . ": invalid InspectorID";
        continue;
    }

    // Assign all values to variables — bind_param requires references
    $v_zip          = clean_zip($r['Zip'] ?? null, $r['Zip5'] ?? null);
    $v_status       = normalize_status($r['Status'] ?? null);
    $v_ssn          = encrypt_ssn($r['SocialSecurityNumber'] ?? null);
    $v_rating       = clean_dec($r['Rating'] ?? null);
    $v_app_id       = ($r['AppraisorID'] ?? '') !== '' ? (string)(int)$r['AppraisorID'] : null;
    $v_base_fee     = clean_dec($r['Base Fee'] ?? null);
    $v_pin          = clean_varchar($r['Password']        ?? null, 20);
    $v_address      = clean_varchar($r['Address']         ?? null, 200);
    $v_city         = clean_varchar($r['City']            ?? null, 100);
    $v_state        = $r['State'] !== '' ? substr(trim($r['State']), 0, 10) : null;
    $v_country      = clean_varchar($r['Country']         ?? null, 50);
    $v_phone        = clean_varchar($r['Primary Phone']   ?? null, 30);
    $v_cell         = clean_varchar($r['Cell Phone']      ?? null, 30);
    $v_fax          = clean_varchar($r['Fax']             ?? null, 30);
    $v_pager        = clean_varchar($r['Pager']           ?? null, 30);
    $v_alt_phone    = clean_varchar($r['Alternate Phone'] ?? null, 50);
    $v_alt_label    = null;
    $v_phone_type1  = clean_varchar($r['PhoneType1']      ?? null, 50);
    $v_phone_type2  = clean_varchar($r['PhoneType2']      ?? null, 50);
    $v_email        = clean_varchar($r['EMail']           ?? null, 150);
    $v_base_price   = clean_str($r['Base Price']          ?? null);
    $v_mileage_fee  = clean_str($r['Mileage Fee']         ?? null);
    $v_pic_fee      = clean_str($r['Picture Fee']         ?? null);
    $v_online_bill  = $r['OnlineBilling'] !== '' ? substr(trim($r['OnlineBilling']), 0, 5) : null;
    $v_qbref        = clean_varchar($r['QBref']           ?? null, 100);
    $v_company      = clean_varchar($r['Company']         ?? null, 150);
    $v_camera       = clean_varchar($r['Type of Camera']  ?? null, 100);
    $v_comments     = clean_str($r['Comments']        ?? null);
    $v_insp_notes   = clean_str($r['Inspector Notes'] ?? null);
    $v_restrictions = clean_str($r['Restrictions']    ?? null);
    $v_created_by   = clean_varchar($r['Created By']   ?? null, 50);
    $v_date_created = clean_date($r['Date Created']   ?? null);
    $v_date_modified= clean_date($r['Date Modified']  ?? null);

    $stmt->bind_param(
        'sssssssssssssssssssssssssssssssssss', // 35 params
        $id,           $name,          $v_pin,         $v_ssn,
        $v_status,     $v_app_id,
        $v_address,    $v_city,        $v_state,       $v_zip,         $v_country,
        $v_phone,      $v_cell,        $v_fax,         $v_pager,       $v_alt_phone,
        $v_alt_label,  $v_phone_type1, $v_phone_type2,
        $v_email,
        $v_base_fee,   $v_base_price,  $v_mileage_fee, $v_pic_fee,
        $v_online_bill,$v_qbref,       $v_company,     $v_camera,
        $v_rating,     $v_comments,    $v_insp_notes,  $v_restrictions,
        $v_created_by, $v_date_created,$v_date_modified
    );

    if ($stmt->execute()) {
        $inserted++;
    } else {
        $skipped++;
        $skip_log[] = "Row " . ($line_num + 2) . " (ID=$id $name): " . $stmt->error;
    }
}

$stmt->close();

// ── Report ────────────────────────────────────────────────────────────────

$nl = $is_cli ? "\n" : "<br>\n";

echo "=== Inspector Reimport Complete ==={$nl}";
echo "Inserted : {$inserted}{$nl}";
echo "Skipped  : {$skipped}{$nl}";

if ($skip_log) {
    echo "{$nl}Skipped detail:{$nl}";
    foreach ($skip_log as $entry) {
        echo "  {$entry}{$nl}";
    }
}

// Quick validation
$counts = $db->query(
    "SELECT status, COUNT(*) AS cnt FROM inspectors GROUP BY status ORDER BY cnt DESC"
);
echo "{$nl}Status breakdown:{$nl}";
while ($row = $counts->fetch_assoc()) {
    echo "  {$row['status']}: {$row['cnt']}{$nl}";
}

$zip_missing = $db->query(
    "SELECT COUNT(*) AS cnt FROM inspectors i
     LEFT JOIN zip_codes z ON z.zip = LEFT(TRIM(i.zip), 5)
     WHERE z.zip IS NULL AND i.status = 'Active' AND i.is_archived = FALSE"
)->fetch_assoc()['cnt'];

echo "{$nl}Active inspectors with no zip match in zip_codes: {$zip_missing}{$nl}";
