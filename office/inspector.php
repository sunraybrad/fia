<?php
/**
 * inspector.php — Inspector detail / edit page
 *
 * Tabs: Detail | Inspection History | Email History
 * Pass ?id=N to edit an existing inspector.
 * Pass ?new=1 to create a new inspector.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

$db = get_db();

$is_new      = isset($_GET['new']);
$inspector_id = (int)($_GET['id'] ?? 0);

// ── Load inspector ────────────────────────────────────────────────────────

if ($is_new) {
    // Blank record defaults
    $ins = [
        'inspector_id' => null, 'full_name' => '', 'company' => '',
        'status' => 'Active', 'appraiser_id' => '',
        'address' => '', 'city' => '', 'state_code' => '', 'zip' => '', 'country' => 'United States',
        'phone_primary' => '', 'phone_cell' => '', 'fax' => '', 'phone_pager' => '',
        'phone_alternate' => '', 'phone_alt_label' => '',
        'email' => '', 'rating' => '', 'camera_type' => '',
        'base_fee' => '', 'base_price_notes' => '',
        'mileage_fee_notes' => '', 'picture_fee_notes' => '',
        'online_billing' => '', 'quickbooks_ref' => '',
        'inspector_notes' => '', 'comments' => '', 'restrictions' => '',
        'created_by' => '', 'date_created' => '', 'date_modified' => '',
        'is_archived' => 0,
    ];
} else {
    if (!$inspector_id) { header('Location: /office/inspectors.php'); exit; }
    $stmt = $db->prepare("SELECT * FROM inspectors WHERE inspector_id = ? LIMIT 1");
    $stmt->bind_param('i', $inspector_id);
    $stmt->execute();
    $ins = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$ins) { header('Location: /office/inspectors.php'); exit; }
}

// ── Pagination ────────────────────────────────────────────────────────────

$per_page    = 50;
$hist_page   = max(1, (int)($_GET['hist_page']  ?? 1));
$email_page  = max(1, (int)($_GET['email_page'] ?? 1));
$insp_total  = 0;
$insp_pages  = 1;
$email_total = 0;
$email_pages = 1;

// ── Inspection history ────────────────────────────────────────────────────

$insp_history = [];
if (!$is_new && $inspector_id) {
    $ct = $db->prepare(
        "SELECT COUNT(*) FROM inspections WHERE inspector_id = ? AND is_archived = FALSE"
    );
    $ct->bind_param('i', $inspector_id);
    $ct->execute();
    $ct->bind_result($insp_total);
    $ct->fetch();
    $ct->close();
    $insp_pages = max(1, (int)ceil($insp_total / $per_page));
    $hist_page  = min($hist_page, $insp_pages);
    $h_offset   = ($hist_page - 1) * $per_page;

    $h_stmt = $db->prepare(
        "SELECT i.fia_number, i.status, i.inspection_type,
                i.created_date, i.date_of_inspection,
                i.year, i.make, i.model,
                i.city, i.state_code,
                w.company_name AS warranty_co,
                i.base_fee, i.inspection_fee
           FROM inspections i
           LEFT JOIN warranty_co w ON w.warranty_co_id = i.warranty_co_id
          WHERE i.inspector_id = ?
            AND i.is_archived = FALSE
          ORDER BY i.created_date DESC, i.fia_number DESC
          LIMIT ? OFFSET ?"
    );
    $h_stmt->bind_param('iii', $inspector_id, $per_page, $h_offset);
    $h_stmt->execute();
    $insp_history = $h_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $h_stmt->close();
}

// ── Email history ─────────────────────────────────────────────────────────

$email_history = [];
if (!$is_new && $inspector_id) {
    $ct = $db->prepare(
        "SELECT COUNT(*) FROM emails WHERE inspector_id = ?"
    );
    $ct->bind_param('i', $inspector_id);
    $ct->execute();
    $ct->bind_result($email_total);
    $ct->fetch();
    $ct->close();
    $email_pages = max(1, (int)ceil($email_total / $per_page));
    $email_page  = min($email_page, $email_pages);
    $e_offset    = ($email_page - 1) * $per_page;

    $e_stmt = $db->prepare(
        "SELECT e.email_id, e.fia_number, e.sent_at, e.status,
                e.to_address, e.subject, e.body_text
           FROM emails e
          WHERE e.inspector_id = ?
          ORDER BY e.sent_at DESC
          LIMIT ? OFFSET ?"
    );
    $e_stmt->bind_param('iii', $inspector_id, $per_page, $e_offset);
    $e_stmt->execute();
    $email_history = $e_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $e_stmt->close();
}

// ── Active tab ────────────────────────────────────────────────────────────

$valid_tabs = ['detail', 'history', 'emails'];
$tab_key    = $is_new ? 'new' : 'insp_' . $inspector_id;

if (isset($_GET['tab']) && in_array($_GET['tab'], $valid_tabs, true)) {
    $_SESSION['insp_tab_' . $tab_key] = $_GET['tab'];
}
$active_tab = $_SESSION['insp_tab_' . $tab_key] ?? 'detail';

// ── Flash ─────────────────────────────────────────────────────────────────

$flash = null;
if (isset($_GET['saved'])) {
    $flash = ['type' => 'success', 'msg' => 'Inspector saved successfully.'];
} elseif (isset($_GET['created'])) {
    $flash = ['type' => 'success', 'msg' => 'New inspector created.'];
} elseif (isset($_GET['err'])) {
    $flash = ['type' => 'danger', 'msg' => 'Save failed — please try again.'];
}

// ── Helpers ───────────────────────────────────────────────────────────────

function val(array $row, string $key): string {
    return htmlspecialchars((string)($row[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}

function fdate(?string $d): string {
    return ($d && $d !== '0000-00-00') ? date('m/d/Y', strtotime($d)) : '—';
}

$status_options = ['Active', 'Inactive', 'Prospective', 'NO'];

// ── Page output ───────────────────────────────────────────────────────────

$page_title = $is_new ? 'New Inspector' : h($ins['full_name'] ?? 'Inspector');
$active_nav = 'inspectors';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     INSPECTOR HEADER
     ══════════════════════════════════════════════════════════════════════ -->
<div class="fia-card mb-3">
    <div class="fia-page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <?php if ($is_new): ?>
            <span class="fw-bold fs-5">New Inspector</span>
            <?php else: ?>
            <span class="fw-bold fs-5"><?= h($ins['full_name'] ?? '') ?></span>
            <?= status_badge($ins['status'] ?? '', 'inspector') ?>
            <?php if ($ins['is_archived']): ?>
            <span class="badge bg-dark ms-1">Archived</span>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <?php if (!$is_new && !$ins['is_archived']): ?>
            <form method="POST" action="/office/save_inspector.php" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="inspector_id" value="<?= (int)$inspector_id ?>">
                <input type="hidden" name="tab" value="archive">
                <button type="submit" class="btn btn-sm btn-outline-danger"
                        onclick="return confirm('Archive this inspector? They will no longer appear in dispatch lists.')">
                    <i class="bi bi-archive"></i> Archive
                </button>
            </form>
            <?php elseif (!$is_new && $ins['is_archived']): ?>
            <form method="POST" action="/office/save_inspector.php" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="inspector_id" value="<?= (int)$inspector_id ?>">
                <input type="hidden" name="tab" value="unarchive">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-counterclockwise"></i> Unarchive
                </button>
            </form>
            <?php endif; ?>
            <a href="/office/inspectors.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>
    <?php if (!$is_new): ?>
    <div class="fia-card-body" style="font-size:.82rem;">
        <div class="row g-2">
            <div class="col-6 col-md-3">
                <span class="text-muted">Inspector ID</span><br>
                <strong><?= (int)$ins['inspector_id'] ?></strong>
            </div>
            <div class="col-6 col-md-3">
                <span class="text-muted">Location</span><br>
                <?= h(implode(', ', array_filter([$ins['city'] ?? '', $ins['state_code'] ?? ''])) ?: '—') ?>
            </div>
            <div class="col-6 col-md-3">
                <span class="text-muted">Inspections</span><br>
                <?= count($insp_history) ?> on record
            </div>
            <div class="col-6 col-md-3">
                <span class="text-muted">Rating</span><br>
                <?= $ins['rating'] ? number_format((float)$ins['rating'], 1) : '—' ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TABS
     ══════════════════════════════════════════════════════════════════════ -->
<ul class="nav nav-tabs mb-0" id="inspTabs" role="tablist">
    <?php
    $tabs = ['detail' => 'Detail'];
    if (!$is_new) {
        $tabs['history'] = 'Inspection History <span class="badge bg-secondary ms-1">' . $insp_total . '</span>';
        $tabs['emails']  = 'Email History <span class="badge bg-secondary ms-1">' . $email_total . '</span>';
    }
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
     TAB 1 — DETAIL
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'detail' ? 'show active' : '' ?>" id="tab-detail" role="tabpanel">
<form method="POST" action="/office/save_inspector.php" id="form-detail" class="tab-form">
<input type="hidden" name="csrf_token"    value="<?= csrf_token() ?>">
<input type="hidden" name="inspector_id"  value="<?= $is_new ? '' : (int)$inspector_id ?>">
<input type="hidden" name="tab"           value="detail">
<div class="fia-card-body fia-form-section">

    <div class="row g-3">

        <!-- Identity -->
        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Identity</h6>
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label fw-semibold">Full Name <?= $is_new ? '<span class="text-danger">*</span>' : '' ?></label>
                    <input type="text" name="full_name" class="form-control form-control-sm"
                           value="<?= val($ins, 'full_name') ?>" required>
                </div>
                <div class="col-8">
                    <label class="form-label fw-semibold">Company</label>
                    <input type="text" name="company" class="form-control form-control-sm"
                           value="<?= val($ins, 'company') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <?php foreach ($status_options as $s): ?>
                        <option value="<?= $s ?>" <?= ($ins['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Rating</label>
                    <input type="number" step="0.1" min="0" max="10" name="rating"
                           class="form-control form-control-sm" value="<?= val($ins, 'rating') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Camera Type</label>
                    <input type="text" name="camera_type" class="form-control form-control-sm"
                           value="<?= val($ins, 'camera_type') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Appraiser ID</label>
                    <input type="text" name="appraiser_id" class="form-control form-control-sm"
                           value="<?= val($ins, 'appraiser_id') ?>">
                </div>
            </div>
        </div>

        <!-- Contact -->
        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Contact</h6>
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" name="email" class="form-control form-control-sm"
                           value="<?= val($ins, 'email') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Primary Phone</label>
                    <input type="text" name="phone_primary" class="form-control form-control-sm"
                           value="<?= val($ins, 'phone_primary') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Cell</label>
                    <input type="text" name="phone_cell" class="form-control form-control-sm"
                           value="<?= val($ins, 'phone_cell') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Fax</label>
                    <input type="text" name="fax" class="form-control form-control-sm"
                           value="<?= val($ins, 'fax') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Pager</label>
                    <input type="text" name="phone_pager" class="form-control form-control-sm"
                           value="<?= val($ins, 'phone_pager') ?>">
                </div>
                <div class="col-8">
                    <label class="form-label fw-semibold">Alternate Phone</label>
                    <input type="text" name="phone_alternate" class="form-control form-control-sm"
                           value="<?= val($ins, 'phone_alternate') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Alt Label</label>
                    <input type="text" name="phone_alt_label" class="form-control form-control-sm"
                           value="<?= val($ins, 'phone_alt_label') ?>">
                </div>
            </div>
        </div>

        <!-- Address -->
        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Address</h6>
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label fw-semibold">Street</label>
                    <input type="text" name="address" class="form-control form-control-sm"
                           value="<?= val($ins, 'address') ?>">
                </div>
                <div class="col-5">
                    <label class="form-label fw-semibold">City</label>
                    <input type="text" name="city" class="form-control form-control-sm"
                           value="<?= val($ins, 'city') ?>">
                </div>
                <div class="col-3">
                    <label class="form-label fw-semibold">State</label>
                    <input type="text" name="state_code" class="form-control form-control-sm"
                           value="<?= val($ins, 'state_code') ?>" maxlength="2">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Zip</label>
                    <input type="text" name="zip" class="form-control form-control-sm"
                           value="<?= val($ins, 'zip') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Country</label>
                    <input type="text" name="country" class="form-control form-control-sm"
                           value="<?= val($ins, 'country') ?>">
                </div>
            </div>
        </div>

        <!-- Fees -->
        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Fees</h6>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label fw-semibold">Base Fee</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" name="base_fee"
                               class="form-control form-control-sm" value="<?= val($ins, 'base_fee') ?>">
                    </div>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Online Billing</label>
                    <input type="text" name="online_billing" class="form-control form-control-sm"
                           value="<?= val($ins, 'online_billing') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Base Price Notes</label>
                    <input type="text" name="base_price_notes" class="form-control form-control-sm"
                           value="<?= val($ins, 'base_price_notes') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Mileage Fee Notes</label>
                    <input type="text" name="mileage_fee_notes" class="form-control form-control-sm"
                           value="<?= val($ins, 'mileage_fee_notes') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Picture Fee Notes</label>
                    <input type="text" name="picture_fee_notes" class="form-control form-control-sm"
                           value="<?= val($ins, 'picture_fee_notes') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">QuickBooks Ref</label>
                    <input type="text" name="quickbooks_ref" class="form-control form-control-sm"
                           value="<?= val($ins, 'quickbooks_ref') ?>">
                </div>
            </div>
        </div>

        <!-- Notes & Restrictions -->
        <div class="col-12">
            <h6 class="fw-bold mb-2">Notes &amp; Restrictions</h6>
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label fw-semibold text-danger">
                        <i class="bi bi-exclamation-triangle-fill"></i> Restrictions
                    </label>
                    <textarea name="restrictions" class="form-control form-control-sm" rows="3"><?= val($ins, 'restrictions') ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Inspector Notes</label>
                    <textarea name="inspector_notes" class="form-control form-control-sm" rows="3"><?= val($ins, 'inspector_notes') ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Availability / Comments</label>
                    <textarea name="comments" class="form-control form-control-sm" rows="3"><?= val($ins, 'comments') ?></textarea>
                </div>
            </div>
        </div>

    </div><!-- /.row -->

    <div class="mt-3">
        <button type="submit" class="btn btn-fia btn-sm save-btn">
            <i class="bi bi-check-circle"></i> <?= $is_new ? 'Create Inspector' : 'Save Detail' ?>
        </button>
    </div>
</div>
</form>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 2 — INSPECTION HISTORY
     ══════════════════════════════════════════════════════════════════════ -->
<?php if (!$is_new): ?>
<div class="tab-pane fade <?= $active_tab === 'history' ? 'show active' : '' ?>" id="tab-history" role="tabpanel">
<div class="fia-card-body">
    <?php if (empty($insp_history)): ?>
    <p class="text-muted">No inspections on record for this inspector.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="fia-table">
        <thead>
            <tr>
                <th>FIA #</th>
                <th>Status</th>
                <th>Type</th>
                <th>Created</th>
                <th>Inspected</th>
                <th>Vehicle</th>
                <th>Location</th>
                <th>Warranty Co</th>
                <th class="text-end">Fee</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($insp_history as $h): ?>
        <tr>
            <td>
                <a href="/office/inspection.php?fia=<?= (int)$h['fia_number'] ?>">
                    <?= (int)$h['fia_number'] ?>
                </a>
            </td>
            <td>
                <span style="font-size:.7rem;"><?= status_badge($h['status'] ?? '') ?></span>
            </td>
            <td style="font-size:.78rem;"><?= h($h['inspection_type'] ?? '—') ?></td>
            <td style="white-space:nowrap;">
                <?= $h['created_date'] ? date('m/d/Y', strtotime($h['created_date'])) : '—' ?>
            </td>
            <td style="white-space:nowrap;">
                <?= ($h['date_of_inspection'] && $h['date_of_inspection'] !== '0000-00-00')
                    ? date('m/d/Y', strtotime($h['date_of_inspection'])) : '—' ?>
            </td>
            <td class="text-start">
                <?= h(trim(($h['year'] ?? '') . ' ' . ($h['make'] ?? '') . ' ' . ($h['model'] ?? '')) ?: '—') ?>
            </td>
            <td>
                <?= h(implode(', ', array_filter([$h['city'] ?? '', $h['state_code'] ?? ''])) ?: '—') ?>
            </td>
            <td style="font-size:.78rem;"><?= h($h['warranty_co'] ?? '—') ?></td>
            <td class="text-end">
                <?= $h['inspection_fee'] ? '$' . number_format((float)$h['inspection_fee'], 2)
                    : ($h['base_fee']     ? '$' . number_format((float)$h['base_fee'], 2)     : '—') ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php if ($insp_pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top" style="font-size:.82rem;">
        <span class="text-muted">
            Showing <?= number_format(($hist_page - 1) * $per_page + 1) ?>–<?= number_format(min($hist_page * $per_page, $insp_total)) ?>
            of <?= number_format($insp_total) ?>
        </span>
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $hist_page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?id=<?= $inspector_id ?>&tab=history&hist_page=<?= $hist_page - 1 ?>">&#8249;</a>
            </li>
            <li class="page-item disabled">
                <span class="page-link"><?= $hist_page ?> / <?= $insp_pages ?></span>
            </li>
            <li class="page-item <?= $hist_page >= $insp_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="?id=<?= $inspector_id ?>&tab=history&hist_page=<?= $hist_page + 1 ?>">&#8250;</a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 3 — EMAIL HISTORY
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'emails' ? 'show active' : '' ?>" id="tab-emails" role="tabpanel">

<!-- Compose -->
<div class="fia-card-body border-bottom">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <strong style="font-size:.85rem;">Compose Email</strong>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="insp-toggle-compose">
            <i class="bi bi-chevron-down" id="insp-compose-chevron"></i>
        </button>
    </div>
    <div id="insp-compose-wrap" style="display:none;">
    <form method="POST" action="/office/send_entity_email.php" id="insp-compose-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="type"       value="inspector">
        <input type="hidden" name="entity_id"  value="<?= (int)$inspector_id ?>">
        <div class="row g-2">
            <div class="col-md-8">
                <label class="form-label fw-semibold" style="font-size:.82rem;">To</label>
                <input type="email" name="to" class="form-control form-control-sm"
                       value="<?= val($ins, 'email') ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" style="font-size:.82rem;">CC</label>
                <input type="email" name="cc" class="form-control form-control-sm" placeholder="optional">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" style="font-size:.82rem;">Subject</label>
                <input type="text" name="subject" class="form-control form-control-sm" required>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" style="font-size:.82rem;">Body</label>
                <textarea name="body" class="form-control form-control-sm" rows="8" required></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-fia btn-sm">
                    <i class="bi bi-send"></i> Send Email
                </button>
            </div>
        </div>
    </form>
    </div>
</div>

<div class="fia-card-body">
    <?php if (empty($email_history)): ?>
    <p class="text-muted">No emails on record for this inspector.</p>
    <?php else: ?>
    <table class="fia-table">
        <thead>
            <tr>
                <th>Sent</th>
                <th>Status</th>
                <th>FIA #</th>
                <th class="text-start">Subject</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($email_history as $em): ?>
        <tr>
            <td style="white-space:nowrap;">
                <?= $em['sent_at'] ? date('m/d/Y H:i', strtotime($em['sent_at'])) : '—' ?>
            </td>
            <td>
                <span class="badge bg-<?= $em['status'] === 'SENT' ? 'success' : 'secondary' ?>">
                    <?= h($em['status'] ?? '') ?>
                </span>
            </td>
            <td>
                <?php if ($em['fia_number']): ?>
                <a href="/office/inspection.php?fia=<?= (int)$em['fia_number'] ?>">
                    <?= (int)$em['fia_number'] ?>
                </a>
                <?php else: ?>—<?php endif; ?>
            </td>
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
    <?php if ($email_pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top" style="font-size:.82rem;">
        <span class="text-muted">
            Showing <?= number_format(($email_page - 1) * $per_page + 1) ?>–<?= number_format(min($email_page * $per_page, $email_total)) ?>
            of <?= number_format($email_total) ?>
        </span>
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $email_page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?id=<?= $inspector_id ?>&tab=emails&email_page=<?= $email_page - 1 ?>">&#8249;</a>
            </li>
            <li class="page-item disabled">
                <span class="page-link"><?= $email_page ?> / <?= $email_pages ?></span>
            </li>
            <li class="page-item <?= $email_page >= $email_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="?id=<?= $inspector_id ?>&tab=emails&email_page=<?= $email_page + 1 ?>">&#8250;</a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</div>
</div>
<?php endif; ?>

</div><!-- /.tab-content -->

<!-- ══════════════════════════════════════════════════════════════════════
     UNSAVED CHANGES MODAL
     ══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="unsavedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title">
                    <i class="bi bi-exclamation-triangle text-warning"></i> Unsaved Changes
                </h6>
            </div>
            <div class="modal-body" style="font-size:.88rem;">
                You have unsaved changes. What would you like to do?
            </div>
            <div class="modal-footer py-2 gap-2">
                <button type="button" class="btn btn-fia btn-sm" id="modal-save-btn">Save Now</button>
                <button type="button" class="btn btn-outline-danger btn-sm" id="modal-discard-btn">Discard</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    let dirty        = false;
    let activeFormId = 'form-detail';
    let pendingTab   = null;
    let _modal       = null;

    function getModal() {
        if (!_modal) _modal = new bootstrap.Modal(document.getElementById('unsavedModal'));
        return _modal;
    }
    function markDirty() { dirty = true; }
    function clearDirty() { dirty = false; }

    document.querySelectorAll('.tab-form input, .tab-form textarea, .tab-form select').forEach(el => {
        el.addEventListener('change', markDirty);
        el.addEventListener('input',  markDirty);
    });

    document.querySelectorAll('.fia-tab-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            if (dirty) {
                e.preventDefault();
                e.stopImmediatePropagation();
                pendingTab = this;
                getModal().show();
            } else {
                const tab = this.dataset.tab;
                history.replaceState(null, '', '?id=<?= $is_new ? '' : (int)$inspector_id ?>&tab=' + tab);
                activeFormId = 'form-' + tab;
            }
        });
    });

    document.getElementById('modal-save-btn').addEventListener('click', function () {
        getModal().hide();
        const form = document.getElementById(activeFormId);
        if (form) form.submit();
    });

    document.getElementById('modal-discard-btn').addEventListener('click', function () {
        clearDirty();
        getModal().hide();
        if (pendingTab) { pendingTab.click(); pendingTab = null; }
    });

    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    document.querySelectorAll('.save-btn').forEach(btn => {
        btn.addEventListener('click', clearDirty);
    });

    // Inspector email compose toggle
    document.getElementById('insp-toggle-compose')?.addEventListener('click', function () {
        const wrap = document.getElementById('insp-compose-wrap');
        const chev = document.getElementById('insp-compose-chevron');
        const open = wrap.style.display === 'block';
        wrap.style.display = open ? 'none' : 'block';
        chev.className = open ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
    });

})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
