<?php
/**
 * save_job.php — Inspector job save handler
 * Handles: findings save, caption save, photo delete, mark complete
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_inspector();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /inspector/jobs.php');
    exit;
}

verify_csrf();

$db           = get_db();
$inspector_id = (int)$_SESSION['inspector_id'];
$fia          = (int)($_POST['fia'] ?? 0);
$action       = $_POST['action'] ?? '';

if (!$fia) {
    header('Location: /inspector/jobs.php');
    exit;
}

// Verify ownership
$own = $db->prepare(
    "SELECT status FROM inspections WHERE fia_number = ? AND inspector_id = ? AND is_archived = FALSE LIMIT 1"
);
$own->bind_param('ii', $fia, $inspector_id);
$own->execute();
$row = $own->get_result()->fetch_assoc();
$own->close();

if (!$row) {
    header('Location: /inspector/jobs.php');
    exit;
}

$current_status = $row['status'];

// Complete inspections are read-only for inspectors
if ($current_status === 'Complete') {
    header("Location: /inspector/job.php?fia={$fia}&locked=1");
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────

function post_str(string $key): ?string {
    $v = isset($_POST[$key]) ? trim($_POST[$key]) : null;
    return ($v === '' || $v === null) ? null : $v;
}
function post_date(string $key): ?string {
    $v = trim($_POST[$key] ?? '');
    return ($v === '' || $v === '0000-00-00') ? null : $v;
}
function post_time(string $key): ?string {
    $v = trim($_POST[$key] ?? '');
    return $v === '' ? null : $v;
}

// ── Findings save ─────────────────────────────────────────────────────────

if ($action === 'findings' || $action === 'complete') {

    $fields = [
        'date_of_inspection'   => post_date('date_of_inspection'),
        'time_of_inspection'   => post_time('time_of_inspection'),
        'ro_no'                => post_str('ro_no'),
        'ro_date'              => post_date('ro_date'),
        'mileage'              => post_str('mileage'),
        'current_mileage'      => post_str('current_mileage'),
        'towed_driven'         => post_str('towed_driven'),
        'engine_size'          => post_str('engine_size'),
        'transmission_type'    => post_str('transmission_type'),
        'drive_train'          => post_str('drive_train'),
        'commercial_use'       => post_str('commercial_use'),
        'impact_damage'        => post_str('impact_damage'),
        'service_history_avail'=> post_str('service_history_avail'),
        'did_shop_sign_report' => post_str('did_shop_sign_report'),
        'shop_rep_name'        => post_str('shop_rep_name'),
        'date_called_in'       => post_date('date_called_in'),
        'time_called_in'       => post_time('time_called_in'),
        'is_vehicle_torn_down' => post_str('is_vehicle_torn_down'),
        'amount_of_teardown'   => post_str('amount_of_teardown'),
        'abuse_apparent'       => post_str('abuse_apparent'),
        'collision_damage'     => post_str('collision_damage'),
        // fluid conditions
        'engine_oil_cond'      => post_str('engine_oil_cond'),
        'engine_oil_level'     => post_str('engine_oil_level'),
        'coolant_cond'         => post_str('coolant_cond'),
        'coolant_level'        => post_str('coolant_level'),
        'brake_fluid_cond'     => post_str('brake_fluid_cond'),
        'brake_fluid_level'    => post_str('brake_fluid_level'),
        'power_steering_cond'  => post_str('power_steering_cond'),
        'power_steering_level' => post_str('power_steering_level'),
        'trans_fluid_cond'     => post_str('trans_fluid_cond'),
        'trans_fluid_level'    => post_str('trans_fluid_level'),
        // report text
        'customer_complaint'     => post_str('customer_complaint'),
        'cause_of_failure'       => post_str('cause_of_failure'),
        'corrective_action_needed'=> post_str('corrective_action_needed'),
        'overall_condition'      => post_str('overall_condition'),
        'recommended_repairs'    => post_str('recommended_repairs'),
        'inspectors_report'      => post_str('inspectors_report'),
        'shop_comments'          => post_str('shop_comments'),
    ];

    if ($action === 'complete' && $current_status === 'Assigned') {
        $fields['status']         = 'Complete';
        $fields['date_of_inspection'] = $fields['date_of_inspection'] ?? date('Y-m-d');
    }

    $sets   = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
    $types  = str_repeat('s', count($fields)) . 'ii';
    $values = array_values($fields);
    $values[] = $fia;
    $values[] = $inspector_id;

    $stmt = $db->prepare(
        "UPDATE inspections SET {$sets}
          WHERE fia_number = ? AND inspector_id = ?"
    );
    $stmt->bind_param($types, ...$values);
    $ok = $stmt->execute();
    $stmt->close();

    $param = $action === 'complete' ? 'complete=1' : 'saved=1';
    header("Location: /inspector/job.php?fia={$fia}&" . ($ok ? $param : 'err=1'));
    exit;
}

// ── Photo actions (captions + delete) — shared handler ───────────────────────

if ($action === 'captions' || $action === 'delete_photo') {
    $redirect_base = "/inspector/job.php?fia={$fia}";
    require_once 'C:\inetpub\fia_private\photo_actions_handler.php';
}

header("Location: /inspector/job.php?fia={$fia}");
exit;
