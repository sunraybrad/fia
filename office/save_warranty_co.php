<?php
/**
 * save_warranty_co.php — Warranty company save handler
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /office/warranty_cos.php');
    exit;
}

verify_csrf();

$db    = get_db();
$tab   = $_POST['tab'] ?? '';
$wc_id = (int)($_POST['warranty_co_id'] ?? 0);
$is_new = ($wc_id === 0);

function post_str(string $key): ?string {
    $v = isset($_POST[$key]) ? trim($_POST[$key]) : null;
    return ($v === '' || $v === null) ? null : $v;
}
function post_dec(string $key): ?string {
    $v = trim($_POST[$key] ?? '');
    return ($v === '' || !is_numeric($v)) ? null : $v;
}

// ── Archive / Unarchive ───────────────────────────────────────────────────

if ($tab === 'archive' || $tab === 'unarchive') {
    if (!$wc_id) { header('Location: /office/warranty_cos.php'); exit; }
    $val = ($tab === 'archive') ? 1 : 0;
    $stmt = $db->prepare("UPDATE warranty_co SET is_archived = ? WHERE warranty_co_id = ?");
    $stmt->bind_param('ii', $val, $wc_id);
    $stmt->execute();
    $stmt->close();
    log_audit('warranty_co.' . $tab, 'warranty_co', $wc_id);
    header("Location: /office/warranty_co.php?id={$wc_id}&saved=1");
    exit;
}

// ── Contacts tab ──────────────────────────────────────────────────────────

if ($tab === 'details') {
    $fields = [
        'company_name'        => post_str('company_name'),
        'address'             => post_str('address'),
        'city'                => post_str('city'),
        'state_code'          => post_str('state_code'),
        'zip'                 => post_str('zip'),
        'country'             => post_str('country'),
        'login_username'      => post_str('login_username'),
        'quickbooks_ref'      => post_str('quickbooks_ref'),
        'tax_id'              => post_str('tax_id'),
        'fia_phone'           => post_str('fia_phone'),
        'fax'                 => post_str('fax'),
        'inspector_phone'     => post_str('inspector_phone'),
        'inspector_phone_ext' => post_str('inspector_phone_ext'),
        'supervisor_name'     => post_str('supervisor_name'),
        'supervisor_email'    => post_str('supervisor_email'),
        'supervisor_ext'      => post_str('supervisor_ext'),
        'notes'               => post_str('notes'),
        'other_notes'         => post_str('other_notes'),
    ];

    if (empty($fields['company_name'])) {
        header('Location: /office/warranty_co.php' . ($is_new ? '?new=1' : "?id={$wc_id}") . '&err=1');
        exit;
    }

    $old_row = [];
    if (!$is_new && $wc_id) {
        $old = $db->prepare("SELECT company_name FROM warranty_co WHERE warranty_co_id = ? LIMIT 1");
        $old->bind_param('i', $wc_id);
        $old->execute();
        $old_row = $old->get_result()->fetch_assoc() ?? [];
        $old->close();
    }

    $ok = save_wc($db, $wc_id, $is_new, $fields, $new_id);

    if ($ok) {
        if ($is_new) {
            log_audit('warranty_co.create', 'warranty_co', $new_id, ['name' => $fields['company_name']]);
        } else {
            $d = [];
            if (($old_row['company_name'] ?? '') !== ($fields['company_name'] ?? '')) {
                $d['company_name'] = ['old' => $old_row['company_name'] ?? null, 'new' => $fields['company_name']];
            }
            log_audit('warranty_co.save', 'warranty_co', $wc_id, array_merge(['tab' => 'details'], $d));
        }
    }

    redirect_wc($wc_id, $is_new, $new_id, $ok, 'details');
}

// ── Rates tab ─────────────────────────────────────────────────────────────

if ($tab === 'rates') {
    if ($is_new) { header('Location: /office/warranty_cos.php'); exit; }
    $fields = [
        'rate_base_national'  => post_dec('rate_base_national'),
        'rate_base_florida'   => post_dec('rate_base_florida'),
        'rate_base_canada'    => post_str('rate_base_canada'),
        'photo_instructions'  => post_str('photo_instructions'),
        'special_instructions'=> post_str('special_instructions'),
    ];

    $old = $db->prepare("SELECT rate_base_national, rate_base_florida FROM warranty_co WHERE warranty_co_id = ? LIMIT 1");
    $old->bind_param('i', $wc_id);
    $old->execute();
    $old_rates = $old->get_result()->fetch_assoc() ?? [];
    $old->close();

    $ok = save_wc($db, $wc_id, false, $fields, $new_id);

    if ($ok) {
        $d = ['tab' => 'rates'];
        foreach (['rate_base_national', 'rate_base_florida'] as $f) {
            if ((string)($old_rates[$f] ?? '') !== (string)($fields[$f] ?? '')) {
                $d[$f] = ['old' => $old_rates[$f] ?? null, 'new' => $fields[$f]];
            }
        }
        log_audit('warranty_co.save', 'warranty_co', $wc_id, $d);
    }

    redirect_wc($wc_id, false, $wc_id, $ok, 'rates');
}

// ── Contact AJAX actions ──────────────────────────────────────────────────

if (in_array($tab, ['contact_add', 'contact_update', 'contact_delete', 'fee_add', 'fee_update', 'fee_delete'], true)) {
    header('Content-Type: application/json');
    if (!$wc_id) { echo json_encode(['ok' => false, 'error' => 'No warranty_co_id']); exit; }

    // ── Fee schedule ──────────────────────────────────────────────────────

    if ($tab === 'fee_add') {
        $state = strtoupper(trim($_POST['state_code'] ?? ''));
        $fee   = post_dec('fee_base');
        if (!$state || $fee === null) { echo json_encode(['ok' => false, 'error' => 'State and fee required']); exit; }
        $stmt = $db->prepare(
            "INSERT INTO fee_schedule (warranty_co_id, state_code, fee_base, is_archived)
             VALUES (?, ?, ?, FALSE)
             ON DUPLICATE KEY UPDATE fee_base = VALUES(fee_base), is_archived = FALSE"
        );
        $stmt->bind_param('isd', $wc_id, $state, $fee);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) log_audit('warranty_co.save', 'warranty_co', $wc_id, ['tab' => 'fee_add', 'state' => $state, 'fee' => $fee]);
        echo json_encode(['ok' => $ok]);
        exit;
    }

    if ($tab === 'fee_update') {
        $orig_state = strtoupper(trim($_POST['orig_state']  ?? ''));
        $new_state  = strtoupper(trim($_POST['state_code']  ?? ''));
        $fee        = post_dec('fee_base');
        if (!$orig_state || $fee === null) { echo json_encode(['ok' => false, 'error' => 'Missing data']); exit; }
        $stmt = $db->prepare(
            "UPDATE fee_schedule SET state_code = ?, fee_base = ?
              WHERE warranty_co_id = ? AND state_code = ? AND is_archived = FALSE"
        );
        $stmt->bind_param('sdis', $new_state, $fee, $wc_id, $orig_state);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) log_audit('warranty_co.save', 'warranty_co', $wc_id, ['tab' => 'fee_update', 'state' => $new_state, 'fee' => $fee]);
        echo json_encode(['ok' => $ok]);
        exit;
    }

    if ($tab === 'fee_delete') {
        $state = strtoupper(trim($_POST['state_code'] ?? ''));
        if (!$state) { echo json_encode(['ok' => false, 'error' => 'No state']); exit; }
        $stmt = $db->prepare(
            "UPDATE fee_schedule SET is_archived = TRUE
              WHERE warranty_co_id = ? AND state_code = ?"
        );
        $stmt->bind_param('is', $wc_id, $state);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) log_audit('warranty_co.save', 'warranty_co', $wc_id, ['tab' => 'fee_delete', 'state' => $state]);
        echo json_encode(['ok' => $ok]);
        exit;
    }

    if ($tab === 'contact_add') {
        $stmt = $db->prepare(
            "INSERT INTO contacts (warranty_co_id, contact_name, title, email, phone, phone_ext, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $name  = post_str('name');
        $title = post_str('title');
        $email = post_str('email');
        $phone = post_str('phone');
        $ext   = post_str('ext');
        $notes = post_str('notes');
        $stmt->bind_param('issssss', $wc_id, $name, $title, $email, $phone, $ext, $notes);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) log_audit('warranty_co.save', 'warranty_co', $wc_id, ['tab' => 'contact_add', 'name' => $name]);
        echo json_encode(['ok' => $ok]);
        exit;
    }

    if ($tab === 'contact_update') {
        $contact_id = (int)($_POST['contact_id'] ?? 0);
        if (!$contact_id) { echo json_encode(['ok' => false, 'error' => 'No contact_id']); exit; }
        $stmt = $db->prepare(
            "UPDATE contacts SET contact_name = ?, title = ?, email = ?, phone = ?, phone_ext = ?, notes = ?
              WHERE contact_id = ? AND warranty_co_id = ?"
        );
        $name  = post_str('name');
        $title = post_str('title');
        $email = post_str('email');
        $phone = post_str('phone');
        $ext   = post_str('ext');
        $notes = post_str('notes');
        $stmt->bind_param('ssssssii', $name, $title, $email, $phone, $ext, $notes, $contact_id, $wc_id);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) log_audit('warranty_co.save', 'warranty_co', $wc_id, ['tab' => 'contact_update', 'contact_id' => $contact_id]);
        echo json_encode(['ok' => $ok]);
        exit;
    }

    if ($tab === 'contact_delete') {
        $contact_id = (int)($_POST['contact_id'] ?? 0);
        if (!$contact_id) { echo json_encode(['ok' => false, 'error' => 'No contact_id']); exit; }
        $stmt = $db->prepare(
            "UPDATE contacts SET is_archived = TRUE WHERE contact_id = ? AND warranty_co_id = ?"
        );
        $stmt->bind_param('ii', $contact_id, $wc_id);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) log_audit('warranty_co.save', 'warranty_co', $wc_id, ['tab' => 'contact_delete', 'contact_id' => $contact_id]);
        echo json_encode(['ok' => $ok]);
        exit;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────

function save_wc(mysqli $db, int $wc_id, bool $is_new, array $fields, ?int &$new_id): bool {
    if ($is_new) {
        $cols   = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($fields)));
        $marks  = implode(', ', array_fill(0, count($fields), '?'));
        $types  = str_repeat('s', count($fields));
        $stmt   = $db->prepare("INSERT INTO warranty_co ({$cols}) VALUES ({$marks})");
        $stmt->bind_param($types, ...array_values($fields));
        $ok     = $stmt->execute();
        $new_id = $ok ? (int)$db->insert_id : 0;
        $stmt->close();
        return $ok;
    }
    $sets   = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
    $types  = str_repeat('s', count($fields)) . 'i';
    $values = array_values($fields);
    $values[] = $wc_id;
    $stmt   = $db->prepare("UPDATE warranty_co SET {$sets} WHERE warranty_co_id = ?");
    $stmt->bind_param($types, ...$values);
    $ok     = $stmt->execute();
    $stmt->close();
    $new_id = $wc_id;
    return $ok;
}

function redirect_wc(int $wc_id, bool $is_new, ?int $new_id, bool $ok, string $tab): never {
    if ($is_new && $ok && $new_id) {
        header("Location: /office/warranty_co.php?id={$new_id}&created=1");
    } elseif ($ok) {
        header("Location: /office/warranty_co.php?id={$wc_id}&tab={$tab}&saved=1");
    } else {
        $back = $is_new ? '/office/warranty_co.php?new=1' : "/office/warranty_co.php?id={$wc_id}&tab={$tab}";
        header("Location: {$back}&err=1");
    }
    exit;
}

header('Location: /office/warranty_cos.php');
exit;
