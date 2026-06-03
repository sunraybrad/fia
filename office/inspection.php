<?php
/**
 * inspection.php — Inspection detail page
 * 7-tab layout: Dispatch | Vehicle & Shop | Findings 1 | Findings 2 |
 *               Tire Inspection | Billing | Emails
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

$db = get_db();

// ── Load inspection ───────────────────────────────────────────────────────

$fia = (int)($_GET['fia'] ?? 0);
if (!$fia) { header('Location: /office/index.php'); exit; }

$stmt = $db->prepare(
    "SELECT i.*,
            w.company_name   AS warranty_co_name,
            w.rate_base_national, w.rate_base_florida, w.rate_base_canada,
            w.supervisor_name, w.supervisor_email,
            insp.full_name       AS inspector_name,
            insp.phone_primary   AS inspector_phone,
            insp.phone_cell      AS inspector_cell,
            insp.email           AS inspector_email,
            insp.inspector_notes AS inspector_notes
       FROM inspections i
       LEFT JOIN warranty_co w    ON w.warranty_co_id  = i.warranty_co_id
       LEFT JOIN inspectors  insp ON insp.inspector_id = i.inspector_id
      WHERE i.fia_number = ?
      LIMIT 1"
);
$stmt->bind_param('i', $fia);
if (!$stmt->execute()) {
    error_log('Query failed [office/inspection.php/inspection ' . $fia . ']: ' . $db->error);
    header('Location: /office/index.php');
    exit;
}
$ins = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ins) { header('Location: /office/index.php'); exit; }

// ── Load tire record (if exists) ──────────────────────────────────────────

$tire = null;
if ($ins['inspection_type'] === 'Tire Inspection') {
    $ts = $db->prepare("SELECT * FROM inspection_tires WHERE fia_number = ? LIMIT 1");
    $ts->bind_param('i', $fia);
    if (!$ts->execute()) {
        error_log('Query failed [office/inspection.php/tire ' . $fia . ']: ' . $db->error);
        $tire = null;
    } else {
        $tire = $ts->get_result()->fetch_assoc();
    }
    $ts->close();
}

// ── Load nearby inspectors (Dispatch tab) ────────────────────────────────

$shop_zip   = preg_replace('/[^0-9]/', '', substr($ins['zip'] ?? '', 0, 5));
$radius     = 75; // miles
$inspectors_nearby = [];

if ($shop_zip !== '') {
    $dist_stmt = $db->prepare(
        "SELECT
             i.inspector_id, i.full_name, i.phone_primary, i.phone_cell,
             i.email, i.rating, i.restrictions, i.inspector_notes,
             i.comments, i.base_fee, i.state_code, i.city, i.zip,
             z_insp.lat AS insp_lat, z_insp.lng AS insp_lng,
             ROUND(
                 3959 * ACOS(
                     LEAST(1.0, COS(RADIANS(z_shop.lat)) * COS(RADIANS(z_insp.lat)) *
                     COS(RADIANS(z_insp.lng) - RADIANS(z_shop.lng)) +
                     SIN(RADIANS(z_shop.lat)) * SIN(RADIANS(z_insp.lat)))
                 ), 1
             ) AS distance_miles
         FROM inspectors i
         JOIN zip_codes z_insp ON z_insp.zip = LEFT(TRIM(i.zip), 5)
         JOIN zip_codes z_shop ON z_shop.zip = ?
         WHERE i.status = 'Active'
           AND i.is_archived = FALSE
         HAVING distance_miles <= ?
         ORDER BY distance_miles"
    );
    $dist_stmt->bind_param('sd', $shop_zip, $radius);
    if (!$dist_stmt->execute()) {
        error_log('Query failed [office/inspection.php/nearby ' . $fia . ']: ' . $db->error);
        $inspectors_nearby = [];
    } else {
        $inspectors_nearby = $dist_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $dist_stmt->close();
}

// ── Load photos (Photos tab) ──────────────────────────────────────────────

$photos_stmt = $db->prepare(
    "SELECT picture_id, image_path, caption, uploaded_at
       FROM pictures
      WHERE fia_number  = ?
        AND is_archived = FALSE
      ORDER BY uploaded_at, picture_id"
);
$photos_stmt->bind_param('i', $fia);
if (!$photos_stmt->execute()) {
    error_log('Query failed [office/inspection.php/photos ' . $fia . ']: ' . $db->error);
    $photos = [];
} else {
    $photos = $photos_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$photos_stmt->close();

$vPix_base = DEV_MODE ? '//fiainspectors.com/vPix/' : '/vPix/';

// ── Load emails (Emails tab) ──────────────────────────────────────────────

$emails_stmt = $db->prepare(
    "SELECT email_id, sent_at, `status`, from_address, to_address, `subject`, body_text
       FROM emails
      WHERE fia_number = ?
      ORDER BY sent_at DESC"
);
$emails_stmt->bind_param('i', $fia);
if (!$emails_stmt->execute()) {
    error_log('Query failed [office/inspection.php/emails ' . $fia . ']: ' . $db->error);
    $emails = [];
} else {
    $emails = $emails_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$emails_stmt->close();

// ── Load warranty co contacts (for compose dropdown) ─────────────────────

$warco_contacts = [];
if ($ins['warranty_co_id']) {
    $wcc_stmt = $db->prepare(
        "SELECT contact_id, contact_name, email
           FROM contacts
          WHERE warranty_co_id = ?
            AND is_archived = FALSE
            AND email IS NOT NULL AND email != ''
          ORDER BY contact_name"
    );
    $wcc_stmt->bind_param('i', $ins['warranty_co_id']);
    if (!$wcc_stmt->execute()) {
        error_log('Query failed [office/inspection.php/warco_contacts ' . $fia . ']: ' . $db->error);
        $warco_contacts = [];
    } else {
        $warco_contacts = $wcc_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $wcc_stmt->close();

    // Also add supervisor as first option if they have an email
    if ($ins['supervisor_email'] ?? '') {
        array_unshift($warco_contacts, [
            'contact_id'   => 0,
            'contact_name' => ($ins['supervisor_name'] ?? 'Supervisor') . ' (Supervisor)',
            'email'        => $ins['supervisor_email'],
        ]);
    }
}

// ── Active tab ────────────────────────────────────────────────────────────

$valid_tabs  = ['dispatch','vehicle','findings1','findings2','tire','billing','photos','emails'];
$default_tab = match($ins['status']) {
    'Unassigned' => 'dispatch',
    'Complete'   => 'billing',
    'Billed'     => 'billing',
    default      => 'dispatch',
};

if (isset($_GET['tab']) && in_array($_GET['tab'], $valid_tabs, true)) {
    $_SESSION['insp_tab_' . $fia] = $_GET['tab'];
}
$active_tab = $_SESSION['insp_tab_' . $fia] ?? $default_tab;

// ── Flash message ─────────────────────────────────────────────────────────

$flash = null;
if (isset($_GET['saved'])) {
    $flash = ['type' => 'success', 'msg' => 'Changes saved successfully.'];
} elseif (isset($_GET['err'])) {
    $err_msg = match($_GET['err'] ?? '') {
        'sendfail' => 'Email send failed — check the Emails tab for details.',
        'invalid'  => 'Invalid email address, subject, or body.',
        default    => 'Save failed — please try again.',
    };
    $flash = ['type' => 'danger', 'msg' => $err_msg];
}

// ── Status badge colour ───────────────────────────────────────────────────

$status_colour = inspection_status_colour($ins['status'] ?? '');

// ── Helper: field value shorthand ────────────────────────────────────────

function is_video(string $path): bool {
    return (bool)preg_match('/\.(mp4|mov|avi|wmv|mpeg|mpg)$/i', $path);
}

function val(array $row, string $key): string {
    return htmlspecialchars((string)($row[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}
function fdate(?string $d): string {
    return ($d && $d !== '0000-00-00') ? date('m/d/Y', strtotime($d)) : '';
}
function ftime(?string $t): string {
    return ($t && $t !== '00:00:00') ? date('H:i', strtotime($t)) : '';
}

// ── Page output ───────────────────────────────────────────────────────────

$page_title = 'Inspection #' . $fia;
$active_nav = 'inspections';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     INSPECTION HEADER
     ══════════════════════════════════════════════════════════════════════ -->
<div class="fia-card mb-3">
    <div class="fia-page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <span class="fw-bold fs-5">FIA #<?= $fia ?></span>
            <span class="badge bg-<?= $status_colour ?> ms-2"><?= h($ins['status']) ?></span>
            <span class="badge bg-dark ms-1"><?= h($ins['inspection_type'] ?? 'Inspection') ?></span>
        </div>
        <div class="d-flex gap-2">
            <?php if ($ins['status'] === 'Complete'): ?>
            <form method="POST" action="/office/save_inspection.php">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="fia" value="<?= $fia ?>">
                <input type="hidden" name="tab" value="status_change">
                <input type="hidden" name="new_status" value="Billed">
                <button type="submit" class="btn btn-sm btn-fia"
                        onclick="return confirm('Mark this inspection as Billed?')">
                    <i class="bi bi-receipt"></i> Mark Billed
                </button>
            </form>
            <form method="POST" action="/office/save_inspection.php">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="fia" value="<?= $fia ?>">
                <input type="hidden" name="tab" value="status_change">
                <input type="hidden" name="new_status" value="Assigned">
                <button type="submit" class="btn btn-sm btn-outline-warning"
                        onclick="return confirm('Revert this inspection to Assigned? The inspector will be able to edit it again.')">
                    <i class="bi bi-arrow-counterclockwise"></i> Revert to Assigned
                </button>
            </form>
            <?php elseif ($ins['status'] === 'Billed'): ?>
            <form method="POST" action="/office/save_inspection.php">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="fia" value="<?= $fia ?>">
                <input type="hidden" name="tab" value="status_change">
                <input type="hidden" name="new_status" value="Invoiced">
                <button type="submit" class="btn btn-sm btn-fia"
                        onclick="return confirm('Mark this inspection as Invoiced?')">
                    <i class="bi bi-file-earmark-check"></i> Mark Invoiced
                </button>
            </form>
            <?php endif; ?>
            <?php if (!in_array($ins['status'], ['Invoiced', 'Cancelled'], true)): ?>
            <button type="button" class="btn btn-sm btn-outline-danger"
                    data-bs-toggle="modal" data-bs-target="#cancelModal">
                <i class="bi bi-x-circle"></i> Cancel
            </button>
            <?php endif; ?>
            <a href="/office/index.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>
    <div class="fia-card-body">
        <div class="row g-2" style="font-size:.85rem;">
            <div class="col-6 col-md-3">
                <span class="text-muted">Warranty Co</span><br>
                <strong><?= h($ins['warranty_co_name'] ?? '—') ?></strong>
            </div>
            <div class="col-6 col-md-3">
                <span class="text-muted">Inspector</span><br>
                <strong><?= $ins['inspector_name'] ? h($ins['inspector_name']) : '<span class="text-danger">Unassigned</span>' ?></strong>
            </div>
            <div class="col-6 col-md-2">
                <span class="text-muted">Created</span><br>
                <?= h(fdate($ins['created_date'])) ?> <?= h(ftime($ins['created_time'])) ?>
            </div>
            <div class="col-6 col-md-2">
                <span class="text-muted">Claim #</span><br>
                <?= h($ins['claim_number'] ?? '—') ?>
            </div>
            <div class="col-6 col-md-2">
                <span class="text-muted">Contract #</span><br>
                <?= h($ins['contract_number'] ?? '—') ?>
            </div>
            <?php if ($ins['insured']): ?>
            <div class="col-6 col-md-3">
                <span class="text-muted">Insured</span><br>
                <?= h($ins['insured']) ?>
            </div>
            <?php endif; ?>
            <?php if ($ins['called_in_by']): ?>
            <div class="col-6 col-md-3">
                <span class="text-muted">Called In By</span><br>
                <?= h($ins['called_in_by']) ?>
            </div>
            <?php endif; ?>
            <?php if ($ins['date_assigned']): ?>
            <div class="col-6 col-md-2">
                <span class="text-muted">Date Assigned</span><br>
                <?= h(fdate($ins['date_assigned'])) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TABS
     ══════════════════════════════════════════════════════════════════════ -->
<ul class="nav nav-tabs mb-0" id="inspTabs" role="tablist">
    <?php
    $tabs = [
        'dispatch'  => 'Dispatch',
        'vehicle'   => 'Vehicle & Shop',
        'findings1' => 'Findings 1',
        'findings2' => 'Findings 2',
        'tire'      => 'Tire Inspection',
        'billing'   => 'Billing',
        'photos'    => 'Photos <span class="badge bg-secondary ms-1">' . count($photos) . '</span>',
        'emails'    => 'Emails <span class="badge bg-secondary ms-1">' . count($emails) . '</span>',
    ];
    foreach ($tabs as $slug => $label):
        $is_active = ($active_tab === $slug);
    ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $is_active ? 'active' : '' ?> fia-tab-btn"
                data-bs-toggle="tab"
                data-bs-target="#tab-<?= $slug ?>"
                data-tab="<?= $slug ?>"
                type="button" role="tab">
            <?= $label ?>
        </button>
    </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content fia-card border-top-0" style="border-radius:0 0 4px 4px;">

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 1 — DISPATCH
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'dispatch' ? 'show active' : '' ?>" id="tab-dispatch" role="tabpanel">
<form method="POST" action="/office/save_inspection.php" id="form-dispatch" class="tab-form">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="fia"        value="<?= $fia ?>">
<input type="hidden" name="tab"        value="dispatch">
<div class="fia-card-body">

    <div class="row g-3">

        <!-- Shop location reference -->
        <div class="col-md-5">
            <div class="row g-2 mb-3">
                <div class="<?= $ins['inspector_name'] ? 'col-12 col-sm-6' : 'col-12' ?>">
                    <div class="fia-legend h-100" style="font-size:.82rem;">
                        <strong>Shop Location</strong><br>
                        <?= h($ins['repair_shop'] ?? '') ?><br>
                        <?= h($ins['address'] ?? '') ?><br>
                        <?= h(implode(', ', array_filter([$ins['city'] ?? '', $ins['state_code'] ?? '']))) ?>
                        <?= h($ins['zip'] ?? '') ?><br>
                        <?php if ($ins['phone_number']): ?>
                        <i class="bi bi-telephone"></i> <?= h($ins['phone_number']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($ins['inspector_name']): ?>
                <div class="col-12 col-sm-6">
                    <div class="fia-legend h-100" style="font-size:.82rem;">
                        <strong>Assigned Inspector</strong><br>
                        <span class="fw-semibold"><?= h($ins['inspector_name']) ?></span><br>
                        <?php if ($ins['inspector_phone']): ?>
                        <i class="bi bi-telephone"></i> <?= h($ins['inspector_phone']) ?>
                        <?php endif; ?>
                        <?php if ($ins['inspector_cell']): ?>
                        &nbsp;<i class="bi bi-phone"></i> <?= h($ins['inspector_cell']) ?>
                        <?php endif; ?>
                        <?php if ($ins['inspector_notes']): ?>
                        <div class="mt-1 text-muted" style="white-space:pre-wrap;"><?= h($ins['inspector_notes']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Assignment fields -->
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label fw-semibold">Inspector ID</label>
                    <input type="text" inputmode="numeric" pattern="[0-9]*" name="inspector_id" class="form-control form-control-sm"
                           id="inspector_id_input"
                           value="<?= val($ins, 'inspector_id') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Date Assigned</label>
                    <input type="date" name="date_assigned" class="form-control form-control-sm"
                           value="<?= val($ins, 'date_assigned') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Time Assigned</label>
                    <input type="time" name="time_assigned" class="form-control form-control-sm"
                           value="<?= ftime($ins['time_assigned']) ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Contacted</label>
                    <input type="text" name="contacted" class="form-control form-control-sm"
                           value="<?= val($ins, 'contacted') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Time of Contact</label>
                    <input type="time" name="time_of_contact" class="form-control form-control-sm"
                           value="<?= ftime($ins['time_of_contact']) ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Quoted Fee</label>
                    <input type="number" step="0.01" name="quoted_fee" class="form-control form-control-sm"
                           value="<?= val($ins, 'quoted_fee') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">ETA</label>
                    <input type="date" name="eta" class="form-control form-control-sm"
                           value="<?= val($ins, 'eta') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Transmitted</label>
                    <input type="text" name="transmitted" class="form-control form-control-sm"
                           value="<?= val($ins, 'transmitted') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Delay Flag</label>
                    <input type="text" name="delay_flag" class="form-control form-control-sm"
                           value="<?= val($ins, 'delay_flag') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Delay Reason</label>
                    <input type="text" name="delay_reason" class="form-control form-control-sm"
                           value="<?= val($ins, 'delay_reason') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Reason for Inspection</label>
                    <textarea name="reason_for_inspection" class="form-control form-control-sm" rows="2"><?= val($ins, 'reason_for_inspection') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control form-control-sm" rows="3"><?= val($ins, 'notes') ?></textarea>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-fia btn-sm save-btn">
                    <i class="bi bi-check-circle"></i> Save Dispatch
                </button>
                <?php if ($ins['status'] === 'Unassigned' && $ins['inspector_id']): ?>
                <form method="POST" action="/office/save_inspection.php" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="fia" value="<?= $fia ?>">
                    <input type="hidden" name="tab" value="status_change">
                    <input type="hidden" name="new_status" value="Assigned">
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-person-check"></i> Confirm Assignment
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Inspector list -->
        <div class="col-md-7">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <strong style="font-size:.85rem;">
                    Inspectors within <?= $radius ?> miles
                    <?php if ($shop_zip): ?>
                    of <?= h($shop_zip) ?>
                    <?php else: ?>
                    <span class="text-danger">(no shop zip on record)</span>
                    <?php endif; ?>
                </strong>
                <span class="badge bg-secondary"><?= count($inspectors_nearby) ?></span>
            </div>

            <?php if (empty($inspectors_nearby)): ?>
            <p class="text-muted" style="font-size:.82rem;">No active inspectors found within <?= $radius ?> miles.</p>
            <?php else: ?>
            <div style="max-height:320px; overflow-y:auto;">
                <table class="fia-table" id="inspector-list">
                    <thead>
                        <tr>
                            <th>Mi</th>
                            <th class="text-start">Name</th>
                            <th>Cell</th>
                            <th>Rating</th>
                            <th>City / State</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($inspectors_nearby as $ni): ?>
                    <tr class="inspector-row <?= (int)$ins['inspector_id'] === (int)$ni['inspector_id'] ? 'table-primary' : '' ?>"
                        data-id="<?= (int)$ni['inspector_id'] ?>"
                        data-name="<?= h($ni['full_name']) ?>"
                        data-phone="<?= h($ni['phone_primary'] ?? '') ?>"
                        data-cell="<?= h($ni['phone_cell'] ?? '') ?>"
                        data-email="<?= h($ni['email'] ?? '') ?>"
                        data-fee="<?= h($ni['base_fee'] ?? '') ?>"
                        data-notes="<?= h($ni['inspector_notes'] ?? '') ?>"
                        data-comments="<?= h($ni['comments'] ?? '') ?>"
                        data-restrictions="<?= h($ni['restrictions'] ?? '') ?>"
                        style="cursor:pointer;">
                        <td data-label="Mi"><?= $ni['distance_miles'] ?></td>
                        <td data-label="Name" class="text-start fw-semibold"><?= h($ni['full_name']) ?></td>
                        <td data-label="Cell" style="white-space:nowrap;"><?= h($ni['phone_cell'] ?? $ni['phone_primary'] ?? '—') ?></td>
                        <td data-label="Rating"><?= $ni['rating'] ? number_format((float)$ni['rating'], 1) : '—' ?></td>
                        <td data-label="Location"><?= h(implode(', ', array_filter([$ni['city'] ?? '', $ni['state_code'] ?? '']))) ?></td>
                        <td>
                            <button type="button" class="btn btn-fia btn-sm assign-btn py-0 px-2"
                                    data-id="<?= (int)$ni['inspector_id'] ?>"
                                    data-name="<?= h($ni['full_name']) ?>"
                                    title="Assign this inspector">
                                <i class="bi bi-person-check"></i> Select
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Inspector detail panel -->
            <div id="inspector-detail" class="fia-legend mt-2" style="font-size:.82rem; display:none;">
                <div class="d-flex justify-content-between align-items-start">
                    <strong id="det-name" style="font-size:.9rem;"></strong>
                    <span class="text-muted" id="det-fee"></span>
                </div>
                <div class="mt-1">
                    <i class="bi bi-telephone"></i> <span id="det-phone"></span>
                    &nbsp;&nbsp;<i class="bi bi-phone"></i> <span id="det-cell"></span>
                    &nbsp;&nbsp;<i class="bi bi-envelope"></i> <span id="det-email"></span>
                </div>
                <div id="det-restrictions" class="text-danger mt-1" style="display:none;">
                    <i class="bi bi-exclamation-triangle-fill"></i> <span id="det-restrictions-text"></span>
                </div>
                <div id="det-notes-wrap" class="mt-1" style="display:none;">
                    <span class="fw-semibold">Inspector Notes:</span>
                    <div id="det-notes" class="text-muted" style="white-space:pre-wrap;"></div>
                </div>
                <div id="det-comments-wrap" class="mt-1" style="display:none;">
                    <span class="fw-semibold">Availability / History:</span>
                    <div id="det-comments" class="text-muted" style="white-space:pre-wrap;"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
</form>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 2 — VEHICLE & SHOP
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'vehicle' ? 'show active' : '' ?>" id="tab-vehicle" role="tabpanel">
<form method="POST" action="/office/save_inspection.php" id="form-vehicle" class="tab-form">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="fia"        value="<?= $fia ?>">
<input type="hidden" name="tab"        value="vehicle">
<div class="fia-card-body">
    <div class="row g-3">

        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Vehicle</h6>
            <div class="row g-2">
                <div class="col-4">
                    <label class="form-label fw-semibold">Year</label>
                    <input type="text" name="year" class="form-control form-control-sm" value="<?= val($ins,'year') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Make</label>
                    <input type="text" name="make" class="form-control form-control-sm" value="<?= val($ins,'make') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Model</label>
                    <input type="text" name="model" class="form-control form-control-sm" value="<?= val($ins,'model') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Color</label>
                    <input type="text" name="color" class="form-control form-control-sm" value="<?= val($ins,'color') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Mileage</label>
                    <input type="text" name="mileage" class="form-control form-control-sm" value="<?= val($ins,'mileage') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Current Mileage</label>
                    <input type="text" name="current_mileage" class="form-control form-control-sm" value="<?= val($ins,'current_mileage') ?>">
                </div>
                <div class="col-8">
                    <label class="form-label fw-semibold">VIN</label>
                    <input type="text" name="vin" class="form-control form-control-sm" value="<?= val($ins,'vin') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Complete VIN</label>
                    <input type="text" name="complete_vin" class="form-control form-control-sm" value="<?= val($ins,'complete_vin') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Tag</label>
                    <input type="text" name="tag" class="form-control form-control-sm" value="<?= val($ins,'tag') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Tag State</label>
                    <input type="text" name="tag_state" class="form-control form-control-sm" value="<?= val($ins,'tag_state') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Labor Rate</label>
                    <input type="number" step="0.01" name="labor_rate" class="form-control form-control-sm" value="<?= val($ins,'labor_rate') ?>">
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Shop</h6>
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label fw-semibold">Repair Shop</label>
                    <input type="text" name="repair_shop" class="form-control form-control-sm" value="<?= val($ins,'repair_shop') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Address</label>
                    <input type="text" name="address" class="form-control form-control-sm" value="<?= val($ins,'address') ?>">
                </div>
                <div class="col-5">
                    <label class="form-label fw-semibold">City</label>
                    <input type="text" name="city" class="form-control form-control-sm" value="<?= val($ins,'city') ?>">
                </div>
                <div class="col-3">
                    <label class="form-label fw-semibold">State</label>
                    <input type="text" name="state_code" class="form-control form-control-sm" value="<?= val($ins,'state_code') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Zip</label>
                    <input type="text" name="zip" class="form-control form-control-sm" value="<?= val($ins,'zip') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Phone</label>
                    <input type="text" name="phone_number" class="form-control form-control-sm" value="<?= val($ins,'phone_number') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Contact</label>
                    <input type="text" name="contact" class="form-control form-control-sm" value="<?= val($ins,'contact') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Shop Rep Name</label>
                    <input type="text" name="shop_rep_name" class="form-control form-control-sm" value="<?= val($ins,'shop_rep_name') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Did Shop Sign Report</label>
                    <input type="text" name="did_shop_sign_report" class="form-control form-control-sm" value="<?= val($ins,'did_shop_sign_report') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Shop Comments</label>
                    <textarea name="shop_comments" class="form-control form-control-sm" rows="2"><?= val($ins,'shop_comments') ?></textarea>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Verbal To</label>
                    <input type="text" name="verbal_to" class="form-control form-control-sm" value="<?= val($ins,'verbal_to') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Call Verbal Into</label>
                    <input type="text" name="call_verbal_into" class="form-control form-control-sm" value="<?= val($ins,'call_verbal_into') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Called In By</label>
                    <input type="text" name="called_in_by" class="form-control form-control-sm" value="<?= val($ins,'called_in_by') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Email Confirm</label>
                    <input type="text" name="email_confirm" class="form-control form-control-sm" value="<?= val($ins,'email_confirm') ?>">
                </div>
            </div>
        </div>

    </div>
    <div class="mt-3">
        <button type="submit" class="btn btn-fia btn-sm save-btn">
            <i class="bi bi-check-circle"></i> Save Vehicle & Shop
        </button>
    </div>
</div>
</form>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 3 — FINDINGS 1
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'findings1' ? 'show active' : '' ?>" id="tab-findings1" role="tabpanel">
<form method="POST" action="/office/save_inspection.php" id="form-findings1" class="tab-form">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="fia"        value="<?= $fia ?>">
<input type="hidden" name="tab"        value="findings1">
<div class="fia-card-body">
    <div class="row g-3">

        <!-- Fluid conditions -->
        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Fluid Conditions</h6>
            <?php
            $fluids = [
                'engine_oil'      => 'Engine Oil',
                'coolant'         => 'Coolant',
                'brake_fluid'     => 'Brake Fluid',
                'power_steering'  => 'Power Steering',
                'trans_fluid'     => 'Trans Fluid',
            ];
            ?>
            <table class="fia-table">
                <thead><tr><th class="text-start">Fluid</th><th>Condition</th><th>Level</th></tr></thead>
                <tbody>
                <?php foreach ($fluids as $key => $label):
                    $cond_key  = $key . '_cond';
                    $level_key = $key . '_level';
                ?>
                <tr>
                    <td class="text-start" style="font-size:.82rem;"><?= $label ?></td>
                    <td><input type="text" name="<?= $cond_key ?>" class="form-control form-control-sm"
                               value="<?= val($ins, $cond_key) ?>" style="width:100px;"></td>
                    <td><input type="text" name="<?= $level_key ?>" class="form-control form-control-sm"
                               value="<?= val($ins, $level_key) ?>" style="width:100px;"></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Drivetrain & dates -->
        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Drivetrain</h6>
            <div class="row g-2 mb-3">
                <div class="col-4">
                    <label class="form-label fw-semibold">Engine Size</label>
                    <input type="text" name="engine_size" class="form-control form-control-sm" value="<?= val($ins,'engine_size') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Transmission</label>
                    <input type="text" name="transmission_type" class="form-control form-control-sm" value="<?= val($ins,'transmission_type') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Drive Train</label>
                    <input type="text" name="drive_train" class="form-control form-control-sm" value="<?= val($ins,'drive_train') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Towed/Driven</label>
                    <input type="text" name="towed_driven" class="form-control form-control-sm" value="<?= val($ins,'towed_driven') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Tire Size</label>
                    <input type="text" name="insp_tire_size" class="form-control form-control-sm" value="<?= val($ins,'insp_tire_size') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Oversize Tires</label>
                    <input type="text" name="oversize_tires" class="form-control form-control-sm" value="<?= val($ins,'oversize_tires') ?>">
                </div>
            </div>
            <h6 class="fw-bold mb-2">Inspection Dates</h6>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label fw-semibold">Date of Inspection</label>
                    <input type="date" name="date_of_inspection" class="form-control form-control-sm" value="<?= val($ins,'date_of_inspection') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Time of Inspection</label>
                    <input type="time" name="time_of_inspection" class="form-control form-control-sm" value="<?= ftime($ins['time_of_inspection']) ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">RO Number</label>
                    <input type="text" name="ro_no" class="form-control form-control-sm" value="<?= val($ins,'ro_no') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">RO Date</label>
                    <input type="date" name="ro_date" class="form-control form-control-sm" value="<?= val($ins,'ro_date') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Commercial Use</label>
                    <input type="text" name="commercial_use" class="form-control form-control-sm" value="<?= val($ins,'commercial_use') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Impact Damage</label>
                    <input type="text" name="impact_damage" class="form-control form-control-sm" value="<?= val($ins,'impact_damage') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Service History Avail</label>
                    <input type="text" name="service_history_avail" class="form-control form-control-sm" value="<?= val($ins,'service_history_avail') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Towing</label>
                    <input type="number" step="0.01" name="towing" class="form-control form-control-sm" value="<?= val($ins,'towing') ?>">
                </div>
            </div>
        </div>

        <div class="col-12">
            <label class="form-label fw-semibold">Modifications</label>
            <input type="text" name="modifications" class="form-control form-control-sm" value="<?= val($ins,'modifications') ?>">
        </div>
        <div class="col-12">
            <label class="form-label fw-semibold">Customer Complaint</label>
            <textarea name="customer_complaint" class="form-control form-control-sm" rows="3"><?= val($ins,'customer_complaint') ?></textarea>
        </div>
        <div class="col-12">
            <label class="form-label fw-semibold">Overall Condition of Vehicle</label>
            <textarea name="overall_condition" class="form-control form-control-sm" rows="3"><?= val($ins,'overall_condition') ?></textarea>
        </div>
    </div>
    <div class="mt-3">
        <button type="submit" class="btn btn-fia btn-sm save-btn">
            <i class="bi bi-check-circle"></i> Save Findings 1
        </button>
    </div>
</div>
</form>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 4 — FINDINGS 2
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'findings2' ? 'show active' : '' ?>" id="tab-findings2" role="tabpanel">
<form method="POST" action="/office/save_inspection.php" id="form-findings2" class="tab-form">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="fia"        value="<?= $fia ?>">
<input type="hidden" name="tab"        value="findings2">
<div class="fia-card-body">
    <div class="row g-3">
        <div class="col-md-6">
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label fw-semibold">Date Called In</label>
                    <input type="date" name="date_called_in" class="form-control form-control-sm" value="<?= val($ins,'date_called_in') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Time Called In</label>
                    <input type="time" name="time_called_in" class="form-control form-control-sm" value="<?= ftime($ins['time_called_in']) ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Is Vehicle Torn Down</label>
                    <input type="text" name="is_vehicle_torn_down" class="form-control form-control-sm" value="<?= val($ins,'is_vehicle_torn_down') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Amount of Teardown</label>
                    <input type="text" name="amount_of_teardown" class="form-control form-control-sm" value="<?= val($ins,'amount_of_teardown') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Collision Damage</label>
                    <input type="text" name="collision_damage" class="form-control form-control-sm" value="<?= val($ins,'collision_damage') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Failed/Damaged</label>
                    <input type="text" name="failed_damaged" class="form-control form-control-sm" value="<?= val($ins,'failed_damaged') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Abuse Apparent</label>
                    <input type="text" name="abuse_apparent" class="form-control form-control-sm" value="<?= val($ins,'abuse_apparent') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Is Service Related</label>
                    <input type="text" name="is_service_related" class="form-control form-control-sm" value="<?= val($ins,'is_service_related') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Shop of Failure</label>
                    <input type="text" name="shop_of_failure" class="form-control form-control-sm" value="<?= val($ins,'shop_of_failure') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Report Called Into</label>
                    <input type="text" name="report_called_into" class="form-control form-control-sm" value="<?= val($ins,'report_called_into') ?>">
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label fw-semibold">Cause of Failure</label>
                    <textarea name="cause_of_failure" class="form-control form-control-sm" rows="3"><?= val($ins,'cause_of_failure') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Corrective Action Needed</label>
                    <textarea name="corrective_action_needed" class="form-control form-control-sm" rows="3"><?= val($ins,'corrective_action_needed') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Recommended Repairs</label>
                    <textarea name="recommended_repairs" class="form-control form-control-sm" rows="3"><?= val($ins,'recommended_repairs') ?></textarea>
                </div>
            </div>
        </div>
        <div class="col-12">
            <label class="form-label fw-semibold">Inspector's Report</label>
            <textarea name="inspectors_report" class="form-control form-control-sm" rows="5"><?= val($ins,'inspectors_report') ?></textarea>
        </div>
    </div>
    <div class="mt-3">
        <button type="submit" class="btn btn-fia btn-sm save-btn">
            <i class="bi bi-check-circle"></i> Save Findings 2
        </button>
    </div>
</div>
</form>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 5 — TIRE INSPECTION
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'tire' ? 'show active' : '' ?>" id="tab-tire" role="tabpanel">
<form method="POST" action="/office/save_inspection.php" id="form-tire" class="tab-form">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="fia"        value="<?= $fia ?>">
<input type="hidden" name="tab"        value="tire">
<?php $t = $tire ?? []; ?>
<div class="fia-card-body">
    <?php if ($ins['inspection_type'] !== 'Tire Inspection'): ?>
    <p class="text-muted">This is not a tire inspection.</p>
    <?php else: ?>

    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <label class="form-label fw-semibold">General Tire Size</label>
            <input type="text" name="tire_size_general" class="form-control form-control-sm"
                   value="<?= h($t['tire_size_general'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Factory Tire Size</label>
            <input type="text" name="tire_factory_size" class="form-control form-control-sm"
                   value="<?= h($t['tire_factory_size'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Brand (all same)</label>
            <input type="text" name="tire_brand_same" class="form-control form-control-sm"
                   value="<?= h($t['tire_brand_same'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Size (all same)</label>
            <input type="text" name="tire_size_same" class="form-control form-control-sm"
                   value="<?= h($t['tire_size_same'] ?? '') ?>">
        </div>
    </div>

    <?php
    $positions = ['lf' => 'Left Front', 'lr' => 'Left Rear', 'rf' => 'Right Front', 'rr' => 'Right Rear'];
    ?>
    <div class="table-responsive">
    <table class="fia-table">
        <thead>
            <tr>
                <th class="text-start">Position</th>
                <th>Brand</th>
                <th>Size</th>
                <th>Type</th>
                <th>DOT</th>
                <th>Tread C</th>
                <th>Tread L</th>
                <th>Tread R</th>
                <th>Fail</th>
                <th>Run Flat</th>
                <th>Wheel Fail</th>
                <th>OFC</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($positions as $pos => $label): ?>
        <tr>
            <td class="text-start fw-semibold" style="font-size:.82rem;"><?= $label ?></td>
            <td><input type="text" name="tire_brand_<?= $pos ?>" class="form-control form-control-sm" style="width:90px;"
                       value="<?= h($t['tire_brand_' . $pos] ?? '') ?>"></td>
            <td><input type="text" name="tire_size_<?= $pos ?>" class="form-control form-control-sm" style="width:90px;"
                       value="<?= h($t['tire_size_' . $pos] ?? '') ?>"></td>
            <td><input type="text" name="tire_type_<?= $pos ?>" class="form-control form-control-sm" style="width:80px;"
                       value="<?= h($t['tire_type_' . $pos] ?? '') ?>"></td>
            <td><input type="text" name="tire_dot_<?= $pos ?>" class="form-control form-control-sm" style="width:80px;"
                       value="<?= h($t['tire_dot_' . $pos] ?? '') ?>"></td>
            <td><input type="number" step="0.01" name="tire_tread_<?= $pos ?>_c" class="form-control form-control-sm" style="width:60px;"
                       value="<?= h($t['tire_tread_' . $pos . '_c'] ?? '') ?>"></td>
            <td><input type="number" step="0.01" name="tire_tread_<?= $pos ?>_l" class="form-control form-control-sm" style="width:60px;"
                       value="<?= h($t['tire_tread_' . $pos . '_l'] ?? '') ?>"></td>
            <td><input type="number" step="0.01" name="tire_tread_<?= $pos ?>_r" class="form-control form-control-sm" style="width:60px;"
                       value="<?= h($t['tire_tread_' . $pos . '_r'] ?? '') ?>"></td>
            <td class="text-center">
                <input type="checkbox" name="tire_fail_<?= $pos ?>" value="1" class="form-check-input"
                       <?= !empty($t['tire_fail_' . $pos]) ? 'checked' : '' ?>>
            </td>
            <td class="text-center">
                <input type="checkbox" name="tire_runflat_<?= $pos ?>" value="1" class="form-check-input"
                       <?= !empty($t['tire_runflat_' . $pos]) ? 'checked' : '' ?>>
            </td>
            <td class="text-center">
                <input type="checkbox" name="wheel_fail_<?= $pos ?>" value="1" class="form-check-input"
                       <?= !empty($t['wheel_fail_' . $pos]) ? 'checked' : '' ?>>
            </td>
            <td><input type="text" name="tire_ofc_<?= $pos ?>" class="form-control form-control-sm" style="width:70px;"
                       value="<?= h($t['tire_ofc_' . $pos] ?? '') ?>"></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-fia btn-sm save-btn">
            <i class="bi bi-check-circle"></i> Save Tire Inspection
        </button>
    </div>
    <?php endif; ?>
</div>
</form>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 6 — BILLING
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'billing' ? 'show active' : '' ?>" id="tab-billing" role="tabpanel">
<form method="POST" action="/office/save_inspection.php" id="form-billing" class="tab-form">
<input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
<input type="hidden" name="fia"        value="<?= $fia ?>">
<input type="hidden" name="tab"        value="billing">
<div class="fia-card-body">
    <div class="row g-3">

        <!-- Warranty Co rates (read-only reference) -->
        <div class="col-md-4">
            <div class="fia-legend" style="font-size:.82rem;">
                <strong>Warranty Co Rates</strong><br>
                National: <?= h($ins['rate_base_national'] ?? '—') ?><br>
                Florida: <?= h($ins['rate_base_florida'] ?? '—') ?><br>
                Canada: <?= h($ins['rate_base_canada'] ?? '—') ?>
            </div>
        </div>

        <!-- Fee fields -->
        <div class="col-md-4">
            <h6 class="fw-bold mb-2">Inspector Fees</h6>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label fw-semibold">Base Fee</label>
                    <input type="number" step="0.01" name="base_fee" class="form-control form-control-sm" value="<?= val($ins,'base_fee') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Additional Mileage</label>
                    <input type="number" step="0.01" name="additional_mileage" class="form-control form-control-sm" value="<?= val($ins,'additional_mileage') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Special Charges</label>
                    <input type="number" step="0.01" name="special_charges" class="form-control form-control-sm" value="<?= val($ins,'special_charges') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Fuel Surcharge</label>
                    <input type="number" step="0.01" name="fuel_surcharge" class="form-control form-control-sm" value="<?= val($ins,'fuel_surcharge') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Quoted Fee</label>
                    <input type="number" step="0.01" name="quoted_fee" class="form-control form-control-sm" value="<?= val($ins,'quoted_fee') ?>">
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <h6 class="fw-bold mb-2">FIA / Client Fees</h6>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label fw-semibold">FIA Base Fee</label>
                    <input type="number" step="0.01" name="fia_base_fee" class="form-control form-control-sm" value="<?= val($ins,'fia_base_fee') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Inspection Fee</label>
                    <input type="number" step="0.01" name="inspection_fee" class="form-control form-control-sm" value="<?= val($ins,'inspection_fee') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Insp Fee Approved</label>
                    <input type="number" step="0.01" name="inspection_fee_approv" class="form-control form-control-sm" value="<?= val($ins,'inspection_fee_approv') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">FIA Special Charges</label>
                    <input type="number" step="0.01" name="fia_special_charges" class="form-control form-control-sm" value="<?= val($ins,'fia_special_charges') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">FIA Pix</label>
                    <input type="number" name="fia_pix" class="form-control form-control-sm" value="<?= val($ins,'fia_pix') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Total Pix</label>
                    <input type="number" name="total_pix" class="form-control form-control-sm" value="<?= val($ins,'total_pix') ?>">
                </div>
            </div>
        </div>

        <!-- Invoice / accounting -->
        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Invoice & Accounting</h6>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label fw-semibold">Invoice No</label>
                    <input type="text" name="invoice_no" class="form-control form-control-sm" value="<?= val($ins,'invoice_no') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">FIA Invoice No</label>
                    <input type="text" name="fia_invoice_number" class="form-control form-control-sm" value="<?= val($ins,'fia_invoice_number') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">QB Invoice No</label>
                    <input type="text" name="inv_qb_no" class="form-control form-control-sm" value="<?= val($ins,'inv_qb_no') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">QB Check No</label>
                    <input type="text" name="qb_chnum" class="form-control form-control-sm" value="<?= val($ins,'qb_chnum') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">FIA Check No</label>
                    <input type="text" name="fia_check_number" class="form-control form-control-sm" value="<?= val($ins,'fia_check_number') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Typed By</label>
                    <input type="text" name="typed_by" class="form-control form-control-sm" value="<?= val($ins,'typed_by') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Notes on Email</label>
                    <textarea name="notes_on_email" class="form-control form-control-sm" rows="2"><?= val($ins,'notes_on_email') ?></textarea>
                </div>
            </div>
        </div>

        <!-- Dates & statuses -->
        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Dates & Statuses</h6>
            <div class="row g-2">
                <?php
                $bill_dates = [
                    'date_received'          => 'Date Received',
                    'date_typed'             => 'Date Typed',
                    'date_invoiced'          => 'Date Invoiced',
                    'date_fia_paid'          => 'Date FIA Paid',
                    'date_inspector_paid'    => 'Date Inspector Paid',
                ];
                foreach ($bill_dates as $field => $label):
                ?>
                <div class="col-6">
                    <label class="form-label fw-semibold"><?= $label ?></label>
                    <input type="date" name="<?= $field ?>" class="form-control form-control-sm"
                           value="<?= val($ins, $field) ?>">
                </div>
                <?php endforeach; ?>
                <?php
                $bill_statuses = [
                    'status_invoiced' => 'Status Invoiced',
                    'status_typed'    => 'Status Typed',
                ];
                foreach ($bill_statuses as $field => $label):
                ?>
                <div class="col-6">
                    <label class="form-label fw-semibold"><?= $label ?></label>
                    <input type="text" name="<?= $field ?>" class="form-control form-control-sm"
                           value="<?= val($ins, $field) ?>">
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
    <div class="mt-3">
        <button type="submit" class="btn btn-fia btn-sm save-btn">
            <i class="bi bi-check-circle"></i> Save Billing
        </button>
    </div>
</div>
</form>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 7 — PHOTOS
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'photos' ? 'show active' : '' ?>" id="tab-photos" role="tabpanel">
<div class="fia-card-body">

    <!-- Upload zone -->
    <form method="POST" action="/office/upload_photo.php"
          enctype="multipart/form-data" id="office-upload-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="fia"        value="<?= $fia ?>">

        <div class="upload-zone mb-3" id="office-drop-zone">
            <i class="bi bi-cloud-upload"></i>
            <p class="mb-1 fw-semibold">Drag &amp; drop photos or videos here</p>
            <p class="text-muted mb-2" style="font-size:.82rem;">Photos up to 20 MB &nbsp;·&nbsp; Videos up to 150 MB (MP4, MOV)</p>
            <input type="file" name="photos[]" id="office-file-input"
                   accept="image/jpeg,image/png,image/heic,image/webp,video/mp4,video/quicktime,video/*"
                   multiple class="d-none">
            <button type="button" class="btn btn-outline-primary btn-sm" id="office-browse-btn">
                <i class="bi bi-folder2-open"></i> Browse
            </button>
        </div>

        <div id="office-upload-preview" class="photo-grid mb-2" style="display:none;"></div>

        <button type="submit" class="btn btn-fia btn-sm" id="office-upload-btn" style="display:none;">
            <i class="bi bi-upload"></i> Upload <span id="office-upload-count"></span> Photo(s)
        </button>
    </form>

    <!-- Existing photos -->
    <?php if (!empty($photos)): ?>
    <hr>
    <form method="POST" action="/office/save_inspection.php" id="office-captions-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="fia"        value="<?= $fia ?>">
        <input type="hidden" name="tab"        value="photos_captions">

        <div class="photo-grid">
        <?php foreach ($photos as $i => $pic):
            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $pic['image_path'])) continue;
            $thumb = $vPix_base . $fia . '/' . h($pic['image_path']);
        ?>
        <div class="photo-item" data-media-index="<?= $i ?>">
            <?php if (is_video($pic['image_path'])): ?>
            <video src="<?= $thumb ?>" controls preload="metadata"
                   class="media-thumb"
                   data-src="<?= $thumb ?>" data-type="video" data-caption="<?= h($pic['caption'] ?? '') ?>"
                   style="width:100%;height:140px;object-fit:cover;display:block;background:#000;cursor:pointer;"></video>
            <?php else: ?>
            <img src="<?= $thumb ?>" alt="Photo <?= $i + 1 ?>"
                 class="media-thumb"
                 data-src="<?= $thumb ?>" data-type="image" data-caption="<?= h($pic['caption'] ?? '') ?>"
                 onerror="this.onerror=null;this.src='/images/photo_missing.png'"
                 style="cursor:pointer;">
            <?php endif; ?>
            <div class="photo-caption">
                <input type="text"
                       name="caption[<?= (int)$pic['picture_id'] ?>]"
                       value="<?= h($pic['caption'] ?? '') ?>"
                       placeholder="Add caption…">
            </div>
            <div style="font-size:.7rem; color:#888; padding:0 0.4rem 0.3rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                 title="<?= h($pic['image_path']) ?>">
                <?= h($pic['image_path']) ?>
            </div>
            <div class="photo-actions">
                <button type="button"
                        class="btn btn-outline-danger btn-sm py-0 px-1 btn-office-delete-photo"
                        data-id="<?= (int)$pic['picture_id'] ?>"
                        title="Delete photo">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <div class="mt-3">
            <button type="submit" class="btn btn-fia btn-sm">
                <i class="bi bi-check-circle"></i> Save Captions
            </button>
        </div>
    </form>
    <?php else: ?>
    <p class="text-muted mt-2 mb-0" style="font-size:.85rem;">No photos uploaded yet.</p>
    <?php endif; ?>

</div>
</div>

<!-- ── Cancel inspection modal ───────────────────────────────────────── -->
<div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="/office/save_inspection.php">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="fia" value="<?= $fia ?>">
            <input type="hidden" name="tab" value="cancel">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger" id="cancelModalLabel">
                        <i class="bi bi-x-circle"></i> Cancel Inspection #<?= $fia ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        This will set the status to <strong>Cancelled</strong> and archive the record.
                    </p>
                    <label for="cancel_reason" class="form-label fw-semibold">Reason for cancellation</label>
                    <textarea name="cancel_reason" id="cancel_reason" class="form-control" rows="3"
                              placeholder="e.g. Client withdrew request, duplicate entry…"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Never mind</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-circle"></i> Confirm Cancel
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Media lightbox modal ───────────────────────────────────────────── -->
<div class="modal fade" id="mediaModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content bg-dark">
            <div class="modal-header border-0 py-2">
                <span class="text-white-50 small" id="media-modal-caption"></span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 text-center overflow-hidden" id="media-modal-body"
                 style="min-height:300px; background:#111; cursor:zoom-in;">
            </div>
            <div class="modal-footer border-0 justify-content-between py-2 gap-2 flex-wrap">
                <button type="button" class="btn btn-outline-light btn-sm" id="media-prev">
                    <i class="bi bi-chevron-left"></i> Prev
                </button>
                <div class="d-flex align-items-center gap-1">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="media-zoom-out" disabled title="Zoom out (-)">
                        <i class="bi bi-dash-lg"></i>
                    </button>
                    <span class="text-white-50 small" id="media-zoom-level" style="min-width:3.5rem;text-align:center;">100%</span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="media-zoom-in" title="Zoom in (+)">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm ms-1" id="media-zoom-reset" disabled title="Reset zoom (0)">
                        <i class="bi bi-arrows-fullscreen"></i>
                    </button>
                </div>
                <span class="text-white-50 small" id="media-modal-counter"></span>
                <button type="button" class="btn btn-outline-light btn-sm" id="media-next">
                    Next <i class="bi bi-chevron-right"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete photo form (submitted via JS) -->
<form method="POST" action="/office/save_inspection.php" id="office-delete-photo-form">
    <input type="hidden" name="csrf_token"  value="<?= csrf_token() ?>">
    <input type="hidden" name="fia"         value="<?= $fia ?>">
    <input type="hidden" name="tab"         value="photos_delete">
    <input type="hidden" name="picture_id"  id="office-delete-picture-id" value="">
</form>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 8 — EMAILS
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'emails' ? 'show active' : '' ?>" id="tab-emails" role="tabpanel">

<!-- Compose section -->
<div class="fia-card-body border-bottom">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <strong style="font-size:.85rem;">Compose Email</strong>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-toggle-compose">
            <i class="bi bi-chevron-down" id="compose-chevron"></i>
        </button>
    </div>
    <div id="compose-form-wrap" style="display:none;">
    <form method="POST" action="/office/send_inspection_email.php" id="compose-form">
        <input type="hidden" name="csrf_token"  value="<?= csrf_token() ?>">
        <input type="hidden" name="fia"         value="<?= $fia ?>">
        <input type="hidden" name="recipient_type" id="recipient_type" value="inspector">
        <input type="hidden" name="warranty_co_id"  value="<?= (int)($ins['warranty_co_id'] ?? 0) ?>">
        <div class="row g-2">

            <!-- Row 1: Template + Recipient toggle -->
            <div class="col-md-4">
                <label class="form-label fw-semibold" style="font-size:.82rem;">Template</label>
                <select id="email-template" class="form-select form-select-sm">
                    <option value="">— Choose template —</option>
                    <option value="assignment">Assignment to Inspector</option>
                    <option value="reminder">Reminder to Inspector</option>
                    <option value="warco_notify">Notification to Warranty Co</option>
                    <option value="manual">Manual / Custom</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" style="font-size:.82rem;">Recipient</label>
                <div class="btn-group w-100" role="group">
                    <input type="radio" class="btn-check" name="recipient_radio" id="r-inspector" value="inspector" checked>
                    <label class="btn btn-outline-primary btn-sm" for="r-inspector">
                        <i class="bi bi-person"></i> Inspector
                    </label>
                    <input type="radio" class="btn-check" name="recipient_radio" id="r-warco" value="warco">
                    <label class="btn btn-outline-primary btn-sm" for="r-warco">
                        <i class="bi bi-building"></i> Warranty Co
                    </label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" style="font-size:.82rem;">CC</label>
                <input type="email" name="cc" id="email-cc" class="form-control form-control-sm" placeholder="optional">
            </div>

            <!-- Row 2: To (changes based on recipient) -->
            <div class="col-md-8" id="to-inspector-wrap">
                <label class="form-label fw-semibold" style="font-size:.82rem;">To — Inspector</label>
                <input type="email" name="to_inspector" id="to-inspector"
                       class="form-control form-control-sm"
                       value="<?= h($ins['inspector_email'] ?? '') ?>"
                       placeholder="inspector@example.com">
            </div>
            <div class="col-md-8" id="to-warco-wrap" style="display:none;">
                <label class="form-label fw-semibold" style="font-size:.82rem;">To — Warranty Co Contact</label>
                <?php if (empty($warco_contacts)): ?>
                <input type="email" name="to_warco" id="to-warco-input"
                       class="form-control form-control-sm" placeholder="No contacts on file — enter email">
                <?php else: ?>
                <select name="to_warco" id="to-warco-select" class="form-select form-select-sm">
                    <option value="">— Select contact —</option>
                    <?php foreach ($warco_contacts as $wcc): ?>
                    <option value="<?= h($wcc['email']) ?>">
                        <?= h($wcc['contact_name']) ?> &lt;<?= h($wcc['email']) ?>&gt;
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <!-- Row 3: Subject -->
            <div class="col-12">
                <label class="form-label fw-semibold" style="font-size:.82rem;">Subject</label>
                <input type="text" name="subject" id="email-subject" class="form-control form-control-sm"
                       value="FIA Inspection #<?= $fia ?>" required>
            </div>

            <!-- Row 4: Body -->
            <div class="col-12">
                <label class="form-label fw-semibold" style="font-size:.82rem;">Body</label>
                <textarea name="body" id="email-body-input" class="form-control form-control-sm"
                          rows="10" required></textarea>
            </div>

            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-fia btn-sm">
                    <i class="bi bi-send"></i> Send Email
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-clear-compose">Clear</button>
            </div>
        </div>
    </form>
    </div>
</div>

<!-- Email history -->
<div class="fia-card-body">
    <?php if (empty($emails)): ?>
    <p class="text-muted mb-0">No emails on record for this inspection.</p>
    <?php else: ?>
    <table class="fia-table">
        <thead>
            <tr>
                <th>Sent</th>
                <th>Status</th>
                <th class="text-start">To</th>
                <th class="text-start">Subject</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($emails as $em): ?>
        <tr>
            <td style="white-space:nowrap;">
                <?= $em['sent_at'] ? date('m/d/Y H:i', strtotime($em['sent_at'])) : '—' ?>
            </td>
            <td>
                <span class="badge bg-<?= $em['status'] === 'SENT' ? 'success' : 'secondary' ?>">
                    <?= h($em['status'] ?? '') ?>
                </span>
            </td>
            <td class="text-start" style="font-size:.8rem;"><?= h($em['to_address'] ?? '') ?></td>
            <td class="text-start"><?= h($em['subject'] ?? '') ?></td>
            <td>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                        data-bs-toggle="collapse"
                        data-bs-target="#email-body-<?= (int)$em['email_id'] ?>">
                    <i class="bi bi-chevron-down"></i>
                </button>
            </td>
        </tr>
        <tr class="collapse" id="email-body-<?= (int)$em['email_id'] ?>">
            <td colspan="5">
                <pre class="mb-0 p-2" style="font-size:.78rem; white-space:pre-wrap; background:#f8f9fa; border-radius:4px;"><?= h($em['body_text'] ?? '') ?></pre>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</div>

</div><!-- /.tab-content -->

<!-- ══════════════════════════════════════════════════════════════════════
     UNSAVED CHANGES MODAL
     ══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="unsavedModal" tabindex="-1" aria-labelledby="unsavedModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="unsavedModalLabel">
                    <i class="bi bi-exclamation-triangle text-warning"></i> Unsaved Changes
                </h6>
            </div>
            <div class="modal-body" style="font-size:.88rem;">
                You have unsaved changes on this tab. What would you like to do?
            </div>
            <div class="modal-footer py-2 gap-2">
                <button type="button" class="btn btn-fia btn-sm" id="modal-save-btn">
                    Save Now
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="modal-discard-btn">
                    Discard
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     JAVASCRIPT — Dirty tracking, inspector detail panel, tab persistence
     ══════════════════════════════════════════════════════════════════════ -->
<script>
(function () {
    'use strict';

    // ── Dirty tracking ────────────────────────────────────────────────────

    let dirty        = false;
    let activeFormId = 'form-<?= $active_tab ?>';
    let pendingTab   = null;
    let _modal       = null;

    function getModal() {
        if (!_modal) _modal = new bootstrap.Modal(document.getElementById('unsavedModal'));
        return _modal;
    }

    function markDirty() { dirty = true; }
    function clearDirty() { dirty = false; }

    // Watch all form inputs for changes
    document.querySelectorAll('.tab-form input, .tab-form textarea, .tab-form select').forEach(el => {
        el.addEventListener('change', markDirty);
        el.addEventListener('input',  markDirty);
    });

    // After a successful save the page reloads — dirty starts false
    // Intercept tab switching
    document.querySelectorAll('.fia-tab-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            if (dirty) {
                e.preventDefault();
                e.stopImmediatePropagation();
                pendingTab = this;
                getModal().show();
            } else {
                // Store tab in session via URL (let PHP handle it on reload)
                // We update the URL without reload so the back button works
                const tab = this.dataset.tab;
                history.replaceState(null, '', '?fia=<?= $fia ?>&tab=' + tab);
                activeFormId = 'form-' + tab;
            }
        });
    });

    // Save Now — submit the active tab's form
    document.getElementById('modal-save-btn').addEventListener('click', function () {
        getModal().hide();
        const form = document.getElementById(activeFormId);
        if (form) form.submit();
    });

    // Discard — clear dirty and switch to pending tab
    document.getElementById('modal-discard-btn').addEventListener('click', function () {
        clearDirty();
        getModal().hide();
        if (pendingTab) {
            pendingTab.click();
            pendingTab = null;
        }
    });

    // Block page navigation when dirty
    window.addEventListener('beforeunload', function (e) {
        if (dirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Clear dirty flag when any save button is clicked
    document.querySelectorAll('.save-btn').forEach(btn => {
        btn.addEventListener('click', clearDirty);
    });

    // ── Inspector selection (Dispatch tab) ───────────────────────────────

    const idInput  = document.getElementById('inspector_id_input');
    const detPanel = document.getElementById('inspector-detail');

    function showInspectorDetail(row) {
        if (!detPanel) return;

        document.getElementById('det-name').textContent  = row.dataset.name;
        document.getElementById('det-phone').textContent = row.dataset.phone || '—';
        document.getElementById('det-cell').textContent  = row.dataset.cell  || '—';
        document.getElementById('det-email').textContent = row.dataset.email || '—';
        document.getElementById('det-fee').textContent   = row.dataset.fee ? 'Base fee: $' + row.dataset.fee : '';

        // Restrictions
        const restricEl   = document.getElementById('det-restrictions');
        const restricText = document.getElementById('det-restrictions-text');
        if (row.dataset.restrictions) {
            restricText.textContent    = row.dataset.restrictions;
            restricEl.style.display    = '';
        } else {
            restricEl.style.display = 'none';
        }

        // Inspector Notes
        const notesWrap = document.getElementById('det-notes-wrap');
        const notesEl   = document.getElementById('det-notes');
        if (row.dataset.notes) {
            notesEl.textContent        = row.dataset.notes;
            notesWrap.style.display    = '';
        } else {
            notesWrap.style.display = 'none';
        }

        // Availability / Comments
        const commentsWrap = document.getElementById('det-comments-wrap');
        const commentsEl   = document.getElementById('det-comments');
        if (row.dataset.comments) {
            commentsEl.textContent      = row.dataset.comments;
            commentsWrap.style.display  = '';
        } else {
            commentsWrap.style.display = 'none';
        }

        detPanel.style.display = 'block';
    }

    // Row click → show detail panel
    document.querySelectorAll('.inspector-row').forEach(row => {
        row.addEventListener('click', function () {
            document.querySelectorAll('.inspector-row').forEach(r => r.classList.remove('table-primary'));
            this.classList.add('table-primary');
            showInspectorDetail(this);
        });
    });

    // [Select] → immediately save inspector assignment via AJAX, then reload
    document.querySelectorAll('.assign-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();

            const inspId   = this.dataset.id;
            const inspName = this.dataset.name;
            const row      = this.closest('tr');

            // Highlight row & show detail
            document.querySelectorAll('.inspector-row').forEach(r => r.classList.remove('table-primary'));
            if (row) { row.classList.add('table-primary'); showInspectorDetail(row); }

            // Disable all select buttons while saving
            document.querySelectorAll('.assign-btn').forEach(b => b.disabled = true);
            this.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            const fd = new FormData();
            fd.append('csrf_token', '<?= csrf_token() ?>');
            fd.append('fia',        '<?= $fia ?>');
            fd.append('tab',        'assign_inspector');
            fd.append('inspector_id', inspId);

            fetch('/office/save_inspection.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        clearDirty();
                        window.location.href = '/office/inspection.php?fia=<?= $fia ?>&tab=dispatch&saved=1';
                    } else {
                        alert('Assignment failed: ' + (data.error || 'unknown error'));
                        document.querySelectorAll('.assign-btn').forEach(b => b.disabled = false);
                        this.innerHTML = '<i class="bi bi-person-check"></i> Select';
                    }
                })
                .catch(() => {
                    alert('Network error — assignment not saved.');
                    document.querySelectorAll('.assign-btn').forEach(b => b.disabled = false);
                    this.innerHTML = '<i class="bi bi-person-check"></i> Select';
                });
        });
    });

    // ── Email compose ─────────────────────────────────────────────────────

    const composeWrap = document.getElementById('compose-form-wrap');
    const composeChev = document.getElementById('compose-chevron');

    function openCompose() {
        composeWrap.style.display = 'block';
        composeChev.className = 'bi bi-chevron-up';
    }

    document.getElementById('btn-toggle-compose')?.addEventListener('click', function () {
        const open = composeWrap.style.display === 'block';
        composeWrap.style.display = open ? 'none' : 'block';
        composeChev.className = open ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
    });

    document.getElementById('btn-clear-compose')?.addEventListener('click', function () {
        document.getElementById('email-template').value   = '';
        document.getElementById('email-cc').value         = '';
        document.getElementById('email-subject').value    = 'FIA Inspection #<?= $fia ?>';
        document.getElementById('email-body-input').value = '';
        setRecipient('inspector');
    });

    // Template data
    const TPL = {
        inspector_email : <?= json_encode($ins['inspector_email'] ?? '') ?>,
        inspector_name  : <?= json_encode($ins['inspector_name']  ?? '') ?>,
        inspector_phone : <?= json_encode($ins['inspector_phone'] ?? $ins['inspector_cell'] ?? '') ?>,
        warco_name      : <?= json_encode($ins['warranty_co_name']  ?? '') ?>,
        warco_supervisor: <?= json_encode($ins['supervisor_name']   ?? '') ?>,
        fia             : <?= $fia ?>,
        shop            : <?= json_encode($ins['repair_shop'] ?? '') ?>,
        address         : <?= json_encode(trim(($ins['address'] ?? '') . ', ' . ($ins['city'] ?? '') . ', ' . ($ins['state_code'] ?? '') . ' ' . ($ins['zip'] ?? ''), ', ')) ?>,
        phone           : <?= json_encode($ins['phone_number'] ?? '') ?>,
        vehicle         : <?= json_encode(trim(($ins['year'] ?? '') . ' ' . ($ins['make'] ?? '') . ' ' . ($ins['model'] ?? ''))) ?>,
        claim           : <?= json_encode($ins['claim_number']    ?? '') ?>,
        contract        : <?= json_encode($ins['contract_number'] ?? '') ?>,
        eta             : <?= json_encode($ins['eta'] ?? '') ?>,
        quoted_fee      : <?= json_encode($ins['quoted_fee'] ?? '') ?>,
    };

    // recipient type → inspector or warco
    function setRecipient(type) {
        document.getElementById('recipient_type').value = type;
        document.getElementById('r-inspector').checked = (type === 'inspector');
        document.getElementById('r-warco').checked     = (type === 'warco');
        document.getElementById('to-inspector-wrap').style.display = type === 'inspector' ? '' : 'none';
        document.getElementById('to-warco-wrap').style.display     = type === 'warco'     ? '' : 'none';
    }

    document.querySelectorAll('input[name="recipient_radio"]').forEach(r => {
        r.addEventListener('change', function () { setRecipient(this.value); });
    });

    const TEMPLATES = {
        assignment: {
            recipient: 'inspector',
            body: `Hi ${TPL.inspector_name},\n\nYou have been assigned the following inspection:\n\nFIA #: ${TPL.fia}\nShop: ${TPL.shop}\nAddress: ${TPL.address}\nPhone: ${TPL.phone}\nVehicle: ${TPL.vehicle}\nClaim #: ${TPL.claim}\nContract #: ${TPL.contract}\nETA: ${TPL.eta}\nQuoted Fee: $${TPL.quoted_fee}\n\nPlease confirm receipt of this assignment.\n\nThank you,\nFlorida Inspection Associates`,
        },
        reminder: {
            recipient: 'inspector',
            body: `Hi ${TPL.inspector_name},\n\nThis is a reminder regarding your assigned inspection:\n\nFIA #: ${TPL.fia}\nShop: ${TPL.shop}\nAddress: ${TPL.address}\nVehicle: ${TPL.vehicle}\n\nPlease update us on the status at your earliest convenience.\n\nThank you,\nFlorida Inspection Associates`,
        },
        warco_notify: {
            recipient: 'warco',
            body: `Dear ${TPL.warco_supervisor || TPL.warco_name},\n\nWe are writing to confirm that an inspector has been assigned to the following inspection:\n\nFIA #: ${TPL.fia}\nClaim #: ${TPL.claim}\nContract #: ${TPL.contract}\nVehicle: ${TPL.vehicle}\nShop: ${TPL.shop}\nETA: ${TPL.eta}\n\nWe will follow up once the inspection is complete.\n\nFlorida Inspection Associates`,
        },
        manual: {
            recipient: 'inspector',
            body: '',
        },
    };

    document.getElementById('email-template')?.addEventListener('change', function () {
        const tpl = TEMPLATES[this.value];
        if (!tpl) return;
        setRecipient(tpl.recipient);
        document.getElementById('email-body-input').value = tpl.body;
        openCompose();
    });

    // ── Office photo upload zone ──────────────────────────────────────────

    const offDropZone   = document.getElementById('office-drop-zone');
    const offFileInput  = document.getElementById('office-file-input');
    const offBrowseBtn  = document.getElementById('office-browse-btn');
    const offPreview    = document.getElementById('office-upload-preview');
    const offUploadBtn  = document.getElementById('office-upload-btn');
    const offCount      = document.getElementById('office-upload-count');

    if (offBrowseBtn) {
        offBrowseBtn.addEventListener('click', () => offFileInput.click());

        offDropZone.addEventListener('dragover',  e => { e.preventDefault(); offDropZone.classList.add('drag-over'); });
        offDropZone.addEventListener('dragleave', () => offDropZone.classList.remove('drag-over'));
        offDropZone.addEventListener('drop', e => {
            e.preventDefault();
            offDropZone.classList.remove('drag-over');
            offFileInput.files = e.dataTransfer.files;
            offShowPreviews(offFileInput.files);
        });
        offDropZone.addEventListener('click', e => {
            if (e.target !== offBrowseBtn && !offBrowseBtn.contains(e.target)) offFileInput.click();
        });
        offFileInput.addEventListener('change', () => offShowPreviews(offFileInput.files));
    }

    function offShowPreviews(files) {
        offPreview.innerHTML = '';
        if (!files.length) {
            offPreview.style.display = 'none';
            offUploadBtn.style.display = 'none';
            return;
        }
        Array.from(files).forEach(file => {
            const div = document.createElement('div');
            div.className = 'photo-item';
            const isVideo = file.type.startsWith('video/');
            let media;
            if (isVideo) {
                media = document.createElement('video');
                media.src = URL.createObjectURL(file);
                media.controls = true;
                media.preload = 'metadata';
                media.style.cssText = 'width:100%;height:140px;object-fit:cover;display:block;background:#000;';
            } else {
                media = document.createElement('img');
                media.src = URL.createObjectURL(file);
            }
            div.appendChild(media);
            const cap = document.createElement('div');
            cap.className = 'photo-caption';
            cap.style.cssText = 'padding:.3rem .5rem;font-size:.75rem;color:#666;';
            cap.textContent = file.name;
            div.appendChild(cap);
            offPreview.appendChild(div);
        });
        offPreview.style.display = 'grid';
        offUploadBtn.style.display = '';
        offCount.textContent = files.length;
    }

    document.querySelectorAll('.btn-office-delete-photo').forEach(btn => {
        btn.addEventListener('click', function () {
            if (!confirm('Delete this photo? This cannot be undone.')) return;
            document.getElementById('office-delete-picture-id').value = this.dataset.id;
            document.getElementById('office-delete-photo-form').submit();
        });
    });

    // Auto-open / auto-fill on ?compose= param
    <?php if (isset($_GET['compose'])): ?>
    openCompose();
    <?php if (array_key_exists($_GET['compose'], ['assignment'=>1,'reminder'=>1,'warco_notify'=>1])): ?>
    document.getElementById('email-template').value = <?= json_encode($_GET['compose'] ?? '') ?>;
    document.getElementById('email-template').dispatchEvent(new Event('change'));
    <?php endif; ?>
    <?php endif; ?>

})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
