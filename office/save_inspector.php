<?php
/**
 * save_inspector.php — Inspector save handler
 *
 * Handles POST from inspector.php (detail tab) plus
 * archive / unarchive actions from the page header.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /office/inspectors.php');
    exit;
}

verify_csrf();

$db           = get_db();
$tab          = $_POST['tab'] ?? '';
$inspector_id = (int)($_POST['inspector_id'] ?? 0);
$is_new       = ($inspector_id === 0);

// ── Sanitise helpers ──────────────────────────────────────────────────────

function post_str(string $key): ?string {
    $v = isset($_POST[$key]) ? trim($_POST[$key]) : null;
    return ($v === '' || $v === null) ? null : $v;
}
function post_dec(string $key): ?string {
    $v = trim($_POST[$key] ?? '');
    return ($v === '' || !is_numeric($v)) ? null : $v;
}
function post_int_val(string $key): ?int {
    $v = trim($_POST[$key] ?? '');
    return ($v === '' || !is_numeric($v)) ? null : (int)$v;
}

// ── Archive / Unarchive ───────────────────────────────────────────────────

if ($tab === 'archive' || $tab === 'unarchive') {
    if (!$inspector_id) { header('Location: /office/inspectors.php'); exit; }
    $archived = ($tab === 'archive') ? 1 : 0;
    $stmt = $db->prepare("UPDATE inspectors SET is_archived = ? WHERE inspector_id = ?");
    $stmt->bind_param('ii', $archived, $inspector_id);
    $stmt->execute();
    $stmt->close();
    log_audit('inspector.' . $tab, 'inspector', $inspector_id);
    header("Location: /office/inspector.php?id={$inspector_id}&saved=1");
    exit;
}

// ── Detail tab — create or update ────────────────────────────────────────

if ($tab === 'detail') {

    $fields = [
        'full_name'         => post_str('full_name'),
        'company'           => post_str('company'),
        'status'            => post_str('status'),
        'rating'            => post_dec('rating'),
        'camera_type'       => post_str('camera_type'),
        'appraiser_id'      => post_int_val('appraiser_id'),
        'email'             => post_str('email'),
        'phone_primary'     => post_str('phone_primary'),
        'phone_cell'        => post_str('phone_cell'),
        'fax'               => post_str('fax'),
        'phone_pager'       => post_str('phone_pager'),
        'phone_alternate'   => post_str('phone_alternate'),
        'phone_alt_label'   => post_str('phone_alt_label'),
        'address'           => post_str('address'),
        'city'              => post_str('city'),
        'state_code'        => post_str('state_code'),
        'zip'               => post_str('zip'),
        'country'           => post_str('country'),
        'base_fee'          => post_dec('base_fee'),
        'base_price_notes'  => post_str('base_price_notes'),
        'mileage_fee_notes' => post_str('mileage_fee_notes'),
        'picture_fee_notes' => post_str('picture_fee_notes'),
        'online_billing'    => post_str('online_billing'),
        'quickbooks_ref'    => post_str('quickbooks_ref'),
        'inspector_notes'   => post_str('inspector_notes'),
        'comments'          => post_str('comments'),
        'restrictions'      => post_str('restrictions'),
    ];

    // Require a name
    if (empty($fields['full_name'])) {
        header('Location: /office/inspector.php' . ($is_new ? '?new=1' : "?id={$inspector_id}") . '&err=1');
        exit;
    }

    // Enforce unique email
    if (!empty($fields['email'])) {
        $eq = $db->prepare(
            "SELECT inspector_id FROM inspectors WHERE email = ? AND inspector_id != ? LIMIT 1"
        );
        $eq->bind_param('si', $fields['email'], $inspector_id);
        $eq->execute();
        $dup = $eq->get_result()->fetch_assoc();
        $eq->close();
        if ($dup) {
            header('Location: /office/inspector.php' . ($is_new ? '?new=1' : "?id={$inspector_id}&tab=detail") . '&err=email');
            exit;
        }
    }

    // Validate status
    $valid_statuses = ['Active', 'Inactive', 'Prospective', 'NO'];
    if (!in_array($fields['status'], $valid_statuses, true)) {
        $fields['status'] = 'Active';
    }

    if ($is_new) {
        // INSERT
        $fields['date_created'] = date('Y-m-d');
        $cols   = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($fields)));
        $marks  = implode(', ', array_fill(0, count($fields), '?'));
        $types  = str_repeat('s', count($fields));
        $values = array_values($fields);

        $stmt = $db->prepare("INSERT INTO inspectors ({$cols}) VALUES ({$marks})");
        $stmt->bind_param($types, ...$values);
        $ok = $stmt->execute();
        $new_id = $ok ? (int)$db->insert_id : 0;
        $stmt->close();

        if ($ok && $new_id) {
            log_audit('inspector.create', 'inspector', $new_id, ['name' => $fields['full_name']]);
            header("Location: /office/inspector.php?id={$new_id}&created=1");
        } else {
            header('Location: /office/inspector.php?new=1&err=1');
        }
        exit;

    } else {
        // UPDATE
        $old = $db->prepare("SELECT status, base_fee FROM inspectors WHERE inspector_id = ? LIMIT 1");
        $old->bind_param('i', $inspector_id);
        $old->execute();
        $old_row = $old->get_result()->fetch_assoc() ?? [];
        $old->close();

        $fields['date_modified'] = date('Y-m-d');
        $sets   = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
        $types  = str_repeat('s', count($fields)) . 'i';
        $values = array_values($fields);
        $values[] = $inspector_id;

        $stmt = $db->prepare("UPDATE inspectors SET {$sets} WHERE inspector_id = ?");
        $stmt->bind_param($types, ...$values);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            $d = [];
            if (($old_row['status'] ?? '') !== ($fields['status'] ?? '')) {
                $d['status'] = ['old' => $old_row['status'] ?? null, 'new' => $fields['status']];
            }
            if ((string)($old_row['base_fee'] ?? '') !== (string)($fields['base_fee'] ?? '')) {
                $d['base_fee'] = ['old' => $old_row['base_fee'] ?? null, 'new' => $fields['base_fee']];
            }
            log_audit('inspector.save', 'inspector', $inspector_id, $d);
        }

        header("Location: /office/inspector.php?id={$inspector_id}&tab=detail&" . ($ok ? 'saved=1' : 'err=1'));
        exit;
    }
}

// ── Fallback ──────────────────────────────────────────────────────────────

header('Location: /office/inspectors.php');
exit;
