<?php
/**
 * save_inspection.php — Per-tab inspection save handler
 *
 * Accepts POST from inspection.php tab forms.
 * Routes by $_POST['tab'] and updates only the relevant fields.
 * Redirects back to inspection.php on completion.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /office/index.php');
    exit;
}

verify_csrf();

$db  = get_db();
$fia = (int)($_POST['fia'] ?? 0);
$tab = $_POST['tab'] ?? '';

if (!$fia) {
    header('Location: /office/index.php');
    exit;
}

// Redirect helper
function redirect_back(int $fia, string $tab, bool $ok = true): never {
    $param = $ok ? 'saved=1' : 'err=1';
    header("Location: /office/inspection.php?fia={$fia}&tab={$tab}&{$param}");
    exit;
}

// Sanitise helpers
function post_str(string $key): ?string {
    $v = isset($_POST[$key]) ? trim($_POST[$key]) : null;
    return ($v === '' || $v === null) ? null : $v;
}
function post_dec(string $key): ?string {
    $v = trim($_POST[$key] ?? '');
    return ($v === '' || !is_numeric($v)) ? null : $v;
}
function post_int(string $key): ?int {
    $v = trim($_POST[$key] ?? '');
    return ($v === '' || !is_numeric($v)) ? null : (int)$v;
}
function post_date(string $key): ?string {
    $v = trim($_POST[$key] ?? '');
    return ($v === '' || $v === '0000-00-00') ? null : $v;
}
function post_time(string $key): ?string {
    $v = trim($_POST[$key] ?? '');
    return ($v === '') ? null : $v;
}
function post_bool(string $key): int {
    return isset($_POST[$key]) ? 1 : 0;
}

// ── Execute a named-array UPDATE on inspections ──────────────────────────

function update_inspections(mysqli $db, int $fia, array $fields): bool {
    if (empty($fields)) return true;
    $sets   = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
    $values = array_values($fields);
    $values[] = $fia;
    $types    = str_repeat('s', count($fields)) . 'i';
    $stmt = $db->prepare("UPDATE inspections SET {$sets} WHERE fia_number = ?");
    $stmt->bind_param($types, ...$values);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// ═════════════════════════════════════════════════════════════════════════
// ROUTE BY TAB
// ═════════════════════════════════════════════════════════════════════════

switch ($tab) {

    // ── Quick inspector assignment (AJAX from Dispatch tab Select button) ───
    case 'assign_inspector':
        header('Content-Type: application/json');
        $inspector_id = post_int('inspector_id');
        if (!$inspector_id) {
            echo json_encode(['ok' => false, 'error' => 'No inspector ID provided']);
            exit;
        }
        // Fetch current status and date_assigned
        $cur = $db->prepare("SELECT status, date_assigned, inspector_id FROM inspections WHERE fia_number = ? LIMIT 1");
        $cur->bind_param('i', $fia);
        $cur->execute();
        $cur_row = $cur->get_result()->fetch_assoc();
        $cur->close();

        $fields = ['inspector_id' => $inspector_id];

        // Auto-set date_assigned if not already set
        if (empty($cur_row['date_assigned']) || $cur_row['date_assigned'] === '0000-00-00') {
            $fields['date_assigned'] = date('Y-m-d');
        }

        // Auto-advance status Unassigned → Assigned
        if (($cur_row['status'] ?? '') === 'Unassigned') {
            $fields['status'] = 'Assigned';
        }

        $ok = update_inspections($db, $fia, $fields);
        if ($ok) {
            $d = ['tab' => 'assign_inspector'];
            if ((int)($cur_row['inspector_id'] ?? 0) !== $inspector_id) {
                $d['inspector_id'] = ['old' => (int)($cur_row['inspector_id'] ?? 0), 'new' => $inspector_id];
            }
            if (isset($fields['status'])) {
                $d['status'] = ['old' => $cur_row['status'], 'new' => $fields['status']];
            }
            log_audit('inspection.save', 'inspection', $fia, $d);
        }
        echo json_encode(['ok' => $ok]);
        exit;

    // ── Status-only change (from header buttons) ──────────────────────────
    case 'cancel':
        $old = $db->prepare("SELECT status, notes FROM inspections WHERE fia_number = ? LIMIT 1");
        $old->bind_param('i', $fia);
        $old->execute();
        $old_row    = $old->get_result()->fetch_assoc();
        $old_status = $old_row['status'] ?? '';
        $old->close();

        $reason      = trim($_POST['cancel_reason'] ?? '');
        $reason_text = $reason !== '' ? $reason : 'No reason given';
        $prefix      = '[Cancelled ' . date('Y-m-d') . ': ' . $reason_text . ']';
        $new_notes   = $prefix . ($old_row['notes'] ? "\n" . $old_row['notes'] : '');

        $stmt = $db->prepare(
            "UPDATE inspections SET status = 'Cancelled', is_archived = TRUE, notes = ? WHERE fia_number = ?"
        );
        $stmt->bind_param('si', $new_notes, $fia);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            log_audit('inspection.cancel', 'inspection', $fia, [
                'status' => ['old' => $old_status, 'new' => 'Cancelled'],
            ]);
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Inspection #' . $fia . ' has been cancelled.'];
            header('Location: /office/index.php');
            exit;
        }
        redirect_back($fia, 'dispatch', false);

    case 'status_change':
        $allowed = ['Assigned', 'Billed', 'Invoiced'];
        $new_status = $_POST['new_status'] ?? '';
        if (!in_array($new_status, $allowed, true)) {
            redirect_back($fia, 'dispatch', false);
        }
        $old = $db->prepare("SELECT status FROM inspections WHERE fia_number = ? LIMIT 1");
        $old->bind_param('i', $fia);
        $old->execute();
        $old_status = $old->get_result()->fetch_assoc()['status'] ?? '';
        $old->close();
        $stmt = $db->prepare("UPDATE inspections SET status = ? WHERE fia_number = ?");
        $stmt->bind_param('si', $new_status, $fia);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            log_audit('inspection.save', 'inspection', $fia, [
                'status' => ['old' => $old_status, 'new' => $new_status],
            ]);
        }
        redirect_back($fia, 'dispatch', $ok);

    // ── Dispatch ──────────────────────────────────────────────────────────
    case 'dispatch':
        $fields = [
            'inspector_id'         => post_int('inspector_id'),
            'date_assigned'        => post_date('date_assigned'),
            'time_assigned'        => post_time('time_assigned'),
            'contacted'            => post_str('contacted'),
            'time_of_contact'      => post_time('time_of_contact'),
            'quoted_fee'           => post_dec('quoted_fee'),
            'eta'                  => post_date('eta'),
            'transmitted'          => post_str('transmitted'),
            'delay_flag'           => post_str('delay_flag'),
            'delay_reason'         => post_str('delay_reason'),
            'reason_for_inspection'=> post_str('reason_for_inspection'),
            'notes'                => post_str('notes'),
        ];
        // Auto-manage status based on inspector assignment
        $cur = $db->prepare("SELECT status, inspector_id, quoted_fee FROM inspections WHERE fia_number = ? LIMIT 1");
        $cur->bind_param('i', $fia);
        $cur->execute();
        $cur_row    = $cur->get_result()->fetch_assoc();
        $cur->close();
        $cur_status = $cur_row['status'] ?? '';

        if ($fields['inspector_id'] && $cur_status === 'Unassigned') {
            $fields['status'] = 'Assigned';
        } elseif (!$fields['inspector_id'] && $cur_status === 'Assigned') {
            $fields['status'] = 'Unassigned';
        }
        $ok = update_inspections($db, $fia, $fields);
        if ($ok) {
            $d = ['tab' => 'dispatch'];
            if ((int)($cur_row['inspector_id'] ?? 0) !== (int)($fields['inspector_id'] ?? 0)) {
                $d['inspector_id'] = ['old' => (int)($cur_row['inspector_id'] ?? 0), 'new' => (int)($fields['inspector_id'] ?? 0)];
            }
            if ((string)($cur_row['quoted_fee'] ?? '') !== (string)($fields['quoted_fee'] ?? '')) {
                $d['quoted_fee'] = ['old' => $cur_row['quoted_fee'], 'new' => $fields['quoted_fee']];
            }
            if (isset($fields['status'])) {
                $d['status'] = ['old' => $cur_status, 'new' => $fields['status']];
            }
            log_audit('inspection.save', 'inspection', $fia, $d);
        }
        redirect_back($fia, 'dispatch', $ok);

    // ── Vehicle & Shop ────────────────────────────────────────────────────
    case 'vehicle':
        $fields = [
            'year'                => post_str('year'),
            'make'                => post_str('make'),
            'model'               => post_str('model'),
            'color'               => post_str('color'),
            'mileage'             => post_str('mileage'),
            'current_mileage'     => post_str('current_mileage'),
            'vin'                 => post_str('vin'),
            'complete_vin'        => post_str('complete_vin'),
            'tag'                 => post_str('tag'),
            'tag_state'           => post_str('tag_state'),
            'labor_rate'          => post_dec('labor_rate'),
            'repair_shop'         => post_str('repair_shop'),
            'address'             => post_str('address'),
            'city'                => post_str('city'),
            'state_code'          => post_str('state_code'),
            'zip'                 => post_str('zip'),
            'phone_number'        => post_str('phone_number'),
            'contact'             => post_str('contact'),
            'shop_rep_name'       => post_str('shop_rep_name'),
            'did_shop_sign_report'=> post_str('did_shop_sign_report'),
            'shop_comments'       => post_str('shop_comments'),
            'verbal_to'           => post_str('verbal_to'),
            'call_verbal_into'    => post_str('call_verbal_into'),
            'called_in_by'        => post_str('called_in_by'),
            'email_confirm'       => post_str('email_confirm'),
        ];
        $ok = update_inspections($db, $fia, $fields);
        if ($ok) log_audit('inspection.save', 'inspection', $fia, ['tab' => 'vehicle']);
        redirect_back($fia, 'vehicle', $ok);

    // ── Findings 1 ────────────────────────────────────────────────────────
    case 'findings1':
        $fields = [
            'engine_oil_condition'   => post_str('engine_oil_condition'),
            'engine_oil_level'       => post_str('engine_oil_level'),
            'coolant_cond'           => post_str('coolant_cond'),
            'coolant_level'          => post_str('coolant_level'),
            'brake_fluid_cond'       => post_str('brake_fluid_cond'),
            'brake_fluid_level'      => post_str('brake_fluid_level'),
            'power_steering_cond'    => post_str('power_steering_cond'),
            'power_steering_level'   => post_str('power_steering_level'),
            'trans_fluid_cond'       => post_str('trans_fluid_cond'),
            'trans_fluid_level'      => post_str('trans_fluid_level'),
            'engine_size'            => post_str('engine_size'),
            'transmission_type'      => post_str('transmission_type'),
            'drive_train'            => post_str('drive_train'),
            'towed_driven'           => post_str('towed_driven'),
            'insp_tire_size'         => post_str('insp_tire_size'),
            'oversize_tires'         => post_str('oversize_tires'),
            'date_of_inspection'     => post_date('date_of_inspection'),
            'time_of_inspection'     => post_time('time_of_inspection'),
            'ro_no'                  => post_str('ro_no'),
            'ro_date'                => post_date('ro_date'),
            'commercial_use'         => post_str('commercial_use'),
            'impact_damage'          => post_str('impact_damage'),
            'service_history_avail'  => post_str('service_history_avail'),
            'towing'                 => post_dec('towing'),
            'modifications'          => post_str('modifications'),
            'customer_complaint'     => post_str('customer_complaint'),
            'overall_condition'      => post_str('overall_condition'),
        ];
        $ok = update_inspections($db, $fia, $fields);
        if ($ok) log_audit('inspection.save', 'inspection', $fia, ['tab' => 'findings1']);
        redirect_back($fia, 'findings1', $ok);

    // ── Findings 2 ────────────────────────────────────────────────────────
    case 'findings2':
        $fields = [
            'date_called_in'          => post_date('date_called_in'),
            'time_called_in'          => post_time('time_called_in'),
            'is_vehicle_torn_down'    => post_str('is_vehicle_torn_down'),
            'amount_of_teardown'      => post_str('amount_of_teardown'),
            'collision_damage'        => post_str('collision_damage'),
            'failed_damaged'          => post_str('failed_damaged'),
            'abuse_apparent'          => post_str('abuse_apparent'),
            'is_service_related'      => post_str('is_service_related'),
            'shop_of_failure'         => post_str('shop_of_failure'),
            'report_called_into'      => post_str('report_called_into'),
            'cause_of_failure'        => post_str('cause_of_failure'),
            'corrective_action_needed'=> post_str('corrective_action_needed'),
            'recommended_repairs'     => post_str('recommended_repairs'),
            'inspectors_report'       => post_str('inspectors_report'),
        ];
        $ok = update_inspections($db, $fia, $fields);
        if ($ok) log_audit('inspection.save', 'inspection', $fia, ['tab' => 'findings2']);
        redirect_back($fia, 'findings2', $ok);

    // ── Tire Inspection ───────────────────────────────────────────────────
    case 'tire':
        // Check if tire record exists
        $chk = $db->prepare("SELECT tire_inspection_id FROM inspection_tires WHERE fia_number = ? LIMIT 1");
        $chk->bind_param('i', $fia);
        $chk->execute();
        $existing_tire = $chk->get_result()->fetch_assoc();
        $chk->close();

        $positions = ['lf','lr','rf','rr'];
        $fields    = [
            'tire_size_general'  => post_str('tire_size_general'),
            'tire_factory_size'  => post_str('tire_factory_size'),
            'tire_brand_same'    => post_str('tire_brand_same'),
            'tire_size_same'     => post_str('tire_size_same'),
        ];
        foreach ($positions as $pos) {
            $fields["tire_brand_{$pos}"]       = post_str("tire_brand_{$pos}");
            $fields["tire_size_{$pos}"]        = post_str("tire_size_{$pos}");
            $fields["tire_type_{$pos}"]        = post_str("tire_type_{$pos}");
            $fields["tire_dot_{$pos}"]         = post_str("tire_dot_{$pos}");
            $fields["tire_tread_{$pos}_c"]     = post_dec("tire_tread_{$pos}_c");
            $fields["tire_tread_{$pos}_l"]     = post_dec("tire_tread_{$pos}_l");
            $fields["tire_tread_{$pos}_r"]     = post_dec("tire_tread_{$pos}_r");
            $fields["tire_fail_{$pos}"]        = post_bool("tire_fail_{$pos}");
            $fields["tire_runflat_{$pos}"]     = post_bool("tire_runflat_{$pos}");
            $fields["wheel_fail_{$pos}"]       = post_bool("wheel_fail_{$pos}");
            $fields["tire_ofc_{$pos}"]         = post_str("tire_ofc_{$pos}");
        }

        if ($existing_tire) {
            $sets   = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
            $values = array_values($fields);
            $values[] = $fia;
            $types    = str_repeat('s', count($fields)) . 'i';
            $stmt = $db->prepare("UPDATE inspection_tires SET {$sets} WHERE fia_number = ?");
            $stmt->bind_param($types, ...$values);
        } else {
            $fields['fia_number'] = $fia;
            $cols   = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($fields)));
            $marks  = implode(', ', array_fill(0, count($fields), '?'));
            $values = array_values($fields);
            $types  = str_repeat('s', count($fields) - 1) . 'i';
            // reorder so fia_number (int) is last type
            $stmt = $db->prepare("INSERT INTO inspection_tires ({$cols}) VALUES ({$marks})");
            $stmt->bind_param(str_repeat('s', count($fields) - 1) . 'i', ...$values);
        }
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) log_audit('inspection.save', 'inspection', $fia, ['tab' => 'tire']);
        redirect_back($fia, 'tire', $ok);

    // ── Billing ───────────────────────────────────────────────────────────
    case 'billing':
        $old = $db->prepare("SELECT base_fee, inspection_fee, inspection_fee_approv FROM inspections WHERE fia_number = ? LIMIT 1");
        $old->bind_param('i', $fia);
        $old->execute();
        $old_fees = $old->get_result()->fetch_assoc() ?? [];
        $old->close();
        $fields = [
            'base_fee'              => post_dec('base_fee'),
            'additional_mileage'    => post_dec('additional_mileage'),
            'special_charges'       => post_dec('special_charges'),
            'fuel_surcharge'        => post_dec('fuel_surcharge'),
            'quoted_fee'            => post_dec('quoted_fee'),
            'fia_base_fee'          => post_dec('fia_base_fee'),
            'inspection_fee'        => post_dec('inspection_fee'),
            'inspection_fee_approv' => post_dec('inspection_fee_approv'),
            'fia_special_charges'   => post_dec('fia_special_charges'),
            'fia_pix'               => post_int('fia_pix'),
            'total_pix'             => post_int('total_pix'),
            'invoice_no'            => post_str('invoice_no'),
            'fia_invoice_number'    => post_str('fia_invoice_number'),
            'inv_qb_no'             => post_str('inv_qb_no'),
            'qb_chnum'              => post_str('qb_chnum'),
            'fia_check_number'      => post_str('fia_check_number'),
            'typed_by'              => post_str('typed_by'),
            'notes_on_email'        => post_str('notes_on_email'),
            'date_received'         => post_date('date_received'),
            'date_typed'            => post_date('date_typed'),
            'date_invoiced'         => post_date('date_invoiced'),
            'date_fia_paid'         => post_date('date_fia_paid'),
            'date_inspector_paid'   => post_date('date_inspector_paid'),
            'status_invoiced'       => post_str('status_invoiced'),
            'status_typed'          => post_str('status_typed'),
        ];
        $ok = update_inspections($db, $fia, $fields);
        if ($ok) {
            $d = ['tab' => 'billing'];
            foreach (['base_fee', 'inspection_fee', 'inspection_fee_approv'] as $f) {
                if ((string)($old_fees[$f] ?? '') !== (string)($fields[$f] ?? '')) {
                    $d[$f] = ['old' => $old_fees[$f] ?? null, 'new' => $fields[$f]];
                }
            }
            log_audit('inspection.save', 'inspection', $fia, $d);
        }
        redirect_back($fia, 'billing', $ok);

    // ── Photo captions (Photos tab) ───────────────────────────────────────
    case 'photos_captions':
        $action        = 'captions';
        $redirect_base = "/office/inspection.php?fia={$fia}&tab=photos";
        $audit_detail  = ['tab' => 'photos_captions', 'portal' => 'office'];
        require_once 'C:\inetpub\fia_private\photo_actions_handler.php';
        // handler exits; code below only reached on fall-through (never)

    // ── Photo delete (Photos tab) ─────────────────────────────────────────
    case 'photos_delete':
        $action        = 'delete_photo';
        $redirect_base = "/office/inspection.php?fia={$fia}&tab=photos";
        require_once 'C:\inetpub\fia_private\photo_actions_handler.php';
        // handler exits

    default:
        redirect_back($fia, 'dispatch', false);
}
