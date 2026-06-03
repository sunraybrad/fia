<?php
/**
 * save_intake.php — Save a new inspection from the Geico email intake form.
 *
 * POST-only. Inserts a new row into inspections, redirects to inspection.php.
 */

require_once 'C:/inetpub/fia_private/config.php';
require_once 'C:/inetpub/fia_private/db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /office/intake_geico.php');
    exit;
}

verify_csrf();

$db = get_db();

// ---------------------------------------------------------------------------
// Sanitise & collect inputs
// ---------------------------------------------------------------------------

function post_str(string $key, int $max = 0): string {
    $val = trim($_POST[$key] ?? '');
    return ($max > 0) ? mb_substr($val, 0, $max) : $val;
}

function post_int(string $key): ?int {
    $val = trim($_POST[$key] ?? '');
    return ($val !== '' && ctype_digit($val)) ? (int)$val : null;
}

function post_date(string $key): ?string {
    $val = trim($_POST[$key] ?? '');
    // Accept YYYY-MM-DD (from date input) or MM/DD/YYYY (typed)
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $val, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
    }
    return null;
}

$warranty_co_id     = 1000; // Geico — fixed
$claim_number       = post_str('claim_number', 20);
$complete_vin       = post_str('complete_vin', 20);
$vin                = post_str('vin', 20);
$insured            = post_str('insured');
$date_called_in     = post_date('date_called_in');
$eta                = post_date('eta');
$year               = post_str('year', 10);
$make               = post_str('make');
$model              = post_str('model');
$mileage            = post_str('mileage', 20);
$repair_shop        = post_str('repair_shop');
$address            = post_str('address');
$city               = post_str('city', 100);
$state_code         = post_str('state_code', 10);
$zip                = post_str('zip', 10);
$contact            = post_str('contact');
$phone_number       = post_str('phone_number', 30);
$customer_complaint = post_str('customer_complaint');
$email_body         = post_str('email_body');

// ---------------------------------------------------------------------------
// Validate required: warranty company must be selected
// ---------------------------------------------------------------------------

if (!$warranty_co_id) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Please select a warranty company before saving.'];
    header('Location: /office/intake_geico.php');
    exit;
}

// ---------------------------------------------------------------------------
// Verify warranty_co_id exists
// ---------------------------------------------------------------------------

$stmt = $db->prepare('SELECT warranty_co_id FROM warranty_co WHERE warranty_co_id = ? AND is_archived = FALSE LIMIT 1');
$stmt->bind_param('i', $warranty_co_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows === 0) {
    $stmt->close();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Selected warranty company not found. Please try again.'];
    header('Location: /office/intake_geico.php');
    exit;
}
$stmt->close();

// ---------------------------------------------------------------------------
// Next FIA number (manual serial — PK is not AUTO_INCREMENT)
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// INSERT  (fia_number is AUTO_INCREMENT — MySQL assigns it)
// ---------------------------------------------------------------------------

$uuid = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

$sql = "
    INSERT INTO inspections (
        uuid,
        warranty_co_id,
        status, source,
        claim_number,
        complete_vin, vin,
        insured,
        date_called_in, eta,
        year, make, model,
        mileage,
        repair_shop,
        address, city, state_code, zip,
        contact, phone_number,
        customer_complaint,
        email_body,
        created_date
    ) VALUES (
        ?,
        ?,
        'Unassigned', 'Geico Email',
        ?,
        ?, ?,
        ?,
        ?, ?,
        ?, ?, ?,
        ?,
        ?,
        ?, ?, ?, ?,
        ?, ?,
        ?,
        ?,
        CURDATE()
    )
";

$stmt = $db->prepare($sql);
$stmt->bind_param(
    'sisssssssssssssssssss',
    $uuid,
    $warranty_co_id,
    $claim_number,
    $complete_vin,
    $vin,
    $insured,
    $date_called_in,
    $eta,
    $year,
    $make,
    $model,
    $mileage,
    $repair_shop,
    $address,
    $city,
    $state_code,
    $zip,
    $contact,
    $phone_number,
    $customer_complaint,
    $email_body
);

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Database error creating inspection: ' . $err];
    header('Location: /office/intake_geico.php');
    exit;
}
$fia_num = $db->insert_id;
$stmt->close();

// ---------------------------------------------------------------------------
// Redirect to the new inspection
// ---------------------------------------------------------------------------

$_SESSION['flash'] = [
    'type' => 'success',
    'msg'  => 'Inspection #' . $fia_num . ' created from Geico email.'
];
header('Location: /office/inspection.php?fia=' . $fia_num);
exit;
