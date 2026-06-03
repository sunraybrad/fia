<?php
/**
 * parse_geico_ajax.php — Parse a Geico inspection request email body.
 *
 * POST  body  : raw email text
 * Returns JSON object with parsed field values + warranty_co_id lookup.
 */

require_once 'C:/inetpub/fia_private/config.php';
require_once 'C:/inetpub/fia_private/db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = trim($_POST['body'] ?? '');
if ($body === '') {
    echo json_encode(['error' => 'No email body supplied']);
    exit;
}

// ---------------------------------------------------------------------------
// Helper: extract the value after a label on the same line.
// e.g.  "Claim Number: 8705654280000015"  →  "8705654280000015"
// ---------------------------------------------------------------------------
function extract_line(string $body, string $label): string {
    // Case-insensitive; label may have trailing spaces before the colon
    $pattern = '/' . preg_quote($label, '/') . '\s*:?\s*(.*)/i';
    if (preg_match($pattern, $body, $m)) {
        return trim($m[1]);
    }
    return '';
}

// ---------------------------------------------------------------------------
// Helper: convert MM/DD/YYYY (or M/D/YYYY) → YYYY-MM-DD.
// Returns '' if unparseable.
// ---------------------------------------------------------------------------
function to_sql_date(string $val): string {
    $val = trim($val);
    if ($val === '') return '';
    // Try MM/DD/YYYY
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $val, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
    }
    // Try strtotime as fallback
    $ts = strtotime($val);
    if ($ts && $ts > 0) {
        return date('Y-m-d', $ts);
    }
    return '';
}

// ---------------------------------------------------------------------------
// Parse fields
// ---------------------------------------------------------------------------

$parsed = [];

// Claim number (top block — appears before "GEICO Inspection Request" header)
$parsed['claim_number'] = extract_line($body, 'Claim Number');

// VINs
$parsed['complete_vin'] = trim(extract_line($body, 'VIN'));
$parsed['vin']          = trim(extract_line($body, 'Last 8 of VIN'));

// Company name → warranty_co lookup
$company_name = trim(extract_line($body, 'Company Name'));

// Policyholder / insured
$parsed['insured'] = trim(extract_line($body, 'Policyholder Name'));

// Loss Date → date_called_in
$parsed['date_called_in'] = to_sql_date(extract_line($body, 'Loss Date'));

// Vehicle Year, Make and Model: "2019, CHEV TAHOE"
$vehicle_raw = trim(extract_line($body, 'Vehicle Year, Make and Model'));
if ($vehicle_raw !== '') {
    // Split on first comma: "2019" and "CHEV TAHOE"
    $parts = array_map('trim', explode(',', $vehicle_raw, 2));
    $parsed['year'] = $parts[0] ?? '';
    // Split make/model on first space
    $make_model = $parts[1] ?? '';
    $mm_parts   = array_map('trim', explode(' ', $make_model, 2));
    $parsed['make']  = $mm_parts[0] ?? '';
    $parsed['model'] = $mm_parts[1] ?? '';
} else {
    $parsed['year'] = $parsed['make'] = $parsed['model'] = '';
}

// Mileage
$parsed['mileage'] = extract_line($body, 'Mileage');

// Requested Appointment Date → eta
$parsed['eta'] = to_sql_date(extract_line($body, 'Requested Appointment Date'));

// Repair Facility Name → repair_shop
$parsed['repair_shop'] = trim(extract_line($body, 'Repair Facility Name'));

// Repair Facility Address → split into address/city/state_code/zip
// Format: "2901 34th St N, St Petersburg, FL, 33713-3636"
$addr_raw = trim(extract_line($body, 'Repair Facility Address'));
if ($addr_raw !== '') {
    $addr_parts = array_map('trim', explode(',', $addr_raw));
    $parsed['address']    = $addr_parts[0] ?? '';
    $parsed['city']       = $addr_parts[1] ?? '';
    $parsed['state_code'] = $addr_parts[2] ?? '';
    // Zip may be "33713-3636" — store as-is (ZIP+4 fine in VARCHAR(10))
    $parsed['zip'] = $addr_parts[3] ?? '';
} else {
    $parsed['address'] = $parsed['city'] = $parsed['state_code'] = $parsed['zip'] = '';
}

// Contact: "CHUCK 727-323-5007"
$contact_raw = trim(extract_line($body, 'Repair Facility Contact'));
$parsed['contact'] = $contact_raw;

// Phone — prefer "Repair Facility Contact Phone:" if populated; otherwise extract from contact
$phone_raw = trim(extract_line($body, 'Repair Facility Contact Phone'));
if ($phone_raw !== '') {
    $parsed['phone_number'] = $phone_raw;
} else {
    // Try to pull a phone-like pattern from the contact field
    if (preg_match('/(\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4})/', $contact_raw, $pm)) {
        $parsed['phone_number'] = $pm[1];
    } else {
        $parsed['phone_number'] = '';
    }
}

// Inspection Instructions → customer_complaint
// Everything after "Inspection Instructions:" to end of line (can be multi-sentence)
$parsed['customer_complaint'] = trim(extract_line($body, 'Inspection Instructions'));

// ---------------------------------------------------------------------------
// Warranty company lookup by name from email
// ---------------------------------------------------------------------------
$parsed['warranty_co_id']   = null;
$parsed['warranty_co_name'] = '';

$db = get_db();

if ($company_name !== '') {
    // Try exact match first, then partial
    $stmt = $db->prepare(
        'SELECT warranty_co_id, company_name FROM warranty_co
         WHERE company_name LIKE ? AND is_archived = FALSE
         ORDER BY company_name LIMIT 5'
    );
    // Extract first meaningful word(s) for search — "GEICO General Insurance Company" → "GEICO"
    $keyword = '%' . $db->real_escape_string(preg_replace('/\s+(General|Insurance|Company|Inc|LLC|Corp|Ltd).*/i', '', $company_name)) . '%';
    $stmt->bind_param('s', $keyword);
    $stmt->execute();
    $result = $stmt->get_result();
    $candidates = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($candidates) === 1) {
        $parsed['warranty_co_id']   = (int)$candidates[0]['warranty_co_id'];
        $parsed['warranty_co_name'] = $candidates[0]['company_name'];
    } elseif (count($candidates) > 1) {
        // Return all candidates so the form can show a dropdown
        $parsed['warranty_co_candidates'] = $candidates;
    }
}

// Return raw company name too so staff can see what was in the email
$parsed['email_company_name'] = $company_name;

echo json_encode($parsed);
