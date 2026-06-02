<?php
/**
 * warranty_co.php — Warranty company detail / edit
 *
 * Tabs: Contacts | Rates/Photos | Inspection History
 * Pass ?id=N to edit, ?new=1 to create.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

$db = get_db();

$is_new = isset($_GET['new']);
$wc_id  = (int)($_GET['id'] ?? 0);

// ── Load record ───────────────────────────────────────────────────────────

if ($is_new) {
    $wc = [
        'warranty_co_id' => null, 'company_name' => '', 'login_username' => '',
        'quickbooks_ref' => '', 'tax_id' => '',
        'address' => '', 'city' => '', 'state_code' => '', 'zip' => '', 'country' => 'United States',
        'fia_phone' => '', 'fax' => '',
        'inspector_phone' => '', 'inspector_phone_ext' => '',
        'supervisor_name' => '', 'supervisor_email' => '', 'supervisor_ext' => '',
        'rate_base_national' => '', 'rate_base_florida' => '', 'rate_base_canada' => '',
        'photo_instructions' => '', 'special_instructions' => '', 'notes' => '', 'other_notes' => '',
        'is_archived' => 0,
    ];
} else {
    if (!$wc_id) { header('Location: /office/warranty_cos.php'); exit; }
    $stmt = $db->prepare("SELECT * FROM warranty_co WHERE warranty_co_id = ? LIMIT 1");
    $stmt->bind_param('i', $wc_id);
    $stmt->execute();
    $wc = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$wc) { header('Location: /office/warranty_cos.php'); exit; }
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
if (!$is_new && $wc_id) {
    $ct = $db->prepare(
        "SELECT COUNT(*) FROM inspections WHERE warranty_co_id = ? AND is_archived = FALSE"
    );
    $ct->bind_param('i', $wc_id);
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
                i.claim_number, i.contract_number,
                insp.full_name AS inspector_name,
                i.base_fee, i.inspection_fee
           FROM inspections i
           LEFT JOIN inspectors insp ON insp.inspector_id = i.inspector_id
          WHERE i.warranty_co_id = ?
            AND i.is_archived = FALSE
          ORDER BY i.created_date DESC, i.fia_number DESC
          LIMIT ? OFFSET ?"
    );
    $h_stmt->bind_param('iii', $wc_id, $per_page, $h_offset);
    $h_stmt->execute();
    $insp_history = $h_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $h_stmt->close();
}

// ── Contacts list ─────────────────────────────────────────────────────────

$wc_contacts = [];
if (!$is_new && $wc_id) {
    $c_stmt = $db->prepare(
        "SELECT contact_id, contact_name, title, email, phone, phone_ext, notes
           FROM contacts
          WHERE warranty_co_id = ?
            AND is_archived = FALSE
          ORDER BY contact_id"
    );
    $c_stmt->bind_param('i', $wc_id);
    $c_stmt->execute();
    $wc_contacts = $c_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $c_stmt->close();
}

// ── Fee schedule ─────────────────────────────────────────────────────────

$fee_schedule = [];
if (!$is_new && $wc_id) {
    $f_stmt = $db->prepare(
        "SELECT state_code, fee_base
           FROM fee_schedule
          WHERE warranty_co_id = ?
            AND is_archived = FALSE
          ORDER BY state_code"
    );
    $f_stmt->bind_param('i', $wc_id);
    $f_stmt->execute();
    $fee_schedule = $f_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $f_stmt->close();
}

// ── Email history ─────────────────────────────────────────────────────────

$wc_emails = [];
if (!$is_new && $wc_id) {
    $ct = $db->prepare(
        "SELECT COUNT(*) FROM emails WHERE warranty_co_id = ?"
    );
    $ct->bind_param('i', $wc_id);
    $ct->execute();
    $ct->bind_result($email_total);
    $ct->fetch();
    $ct->close();
    $email_pages = max(1, (int)ceil($email_total / $per_page));
    $email_page  = min($email_page, $email_pages);
    $e_offset    = ($email_page - 1) * $per_page;

    $em_stmt = $db->prepare(
        "SELECT email_id, fia_number, sent_at, status, to_address, subject, body_text
           FROM emails
          WHERE warranty_co_id = ?
          ORDER BY sent_at DESC
          LIMIT ? OFFSET ?"
    );
    $em_stmt->bind_param('iii', $wc_id, $per_page, $e_offset);
    $em_stmt->execute();
    $wc_emails = $em_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $em_stmt->close();
}

// ── Active tab ────────────────────────────────────────────────────────────

$valid_tabs = ['details', 'contacts', 'rates', 'history', 'emails'];
$tab_key    = $is_new ? 'new' : 'wc_' . $wc_id;

if (isset($_GET['tab']) && in_array($_GET['tab'], $valid_tabs, true)) {
    $_SESSION['wc_tab_' . $tab_key] = $_GET['tab'];
}
$active_tab = $_SESSION['wc_tab_' . $tab_key] ?? 'details';

// ── Flash ─────────────────────────────────────────────────────────────────

$flash = null;
if (isset($_GET['saved']))        $flash = ['type' => 'success', 'msg' => 'Saved successfully.'];
elseif (isset($_GET['created'])) $flash = ['type' => 'success', 'msg' => 'Warranty company created.'];
elseif (isset($_GET['err'])) {
    $flash = ['type' => 'danger', 'msg' => match($_GET['err']) {
        'sendfail' => 'Email send failed — please try again.',
        'invalid'  => 'Invalid recipient, subject, or body.',
        default    => 'Save failed — please try again.',
    }];
}

// ── Helper ────────────────────────────────────────────────────────────────

function val(array $row, string $key): string {
    return htmlspecialchars((string)($row[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}

// ── Page output ───────────────────────────────────────────────────────────

$page_title = $is_new ? 'New Warranty Co' : h($wc['company_name'] ?? 'Warranty Co');
$active_nav = 'clients';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════
     HEADER
     ══════════════════════════════════════════════════════════════════════ -->
<div class="fia-card mb-3">
    <div class="fia-page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <?php if ($is_new): ?>
            <span class="fw-bold fs-5">New Warranty Company</span>
            <?php else: ?>
            <span class="fw-bold fs-5"><?= h($wc['company_name'] ?? '') ?></span>
            <?php if ($wc['is_archived']): ?>
            <span class="badge bg-dark ms-2">Archived</span>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <?php if (!$is_new && !$wc['is_archived']): ?>
            <form method="POST" action="/office/save_warranty_co.php" class="d-inline">
                <input type="hidden" name="csrf_token"    value="<?= csrf_token() ?>">
                <input type="hidden" name="warranty_co_id" value="<?= $wc_id ?>">
                <input type="hidden" name="tab"           value="archive">
                <button type="submit" class="btn btn-sm btn-outline-danger"
                        onclick="return confirm('Archive this warranty company?')">
                    <i class="bi bi-archive"></i> Archive
                </button>
            </form>
            <?php elseif (!$is_new && $wc['is_archived']): ?>
            <form method="POST" action="/office/save_warranty_co.php" class="d-inline">
                <input type="hidden" name="csrf_token"    value="<?= csrf_token() ?>">
                <input type="hidden" name="warranty_co_id" value="<?= $wc_id ?>">
                <input type="hidden" name="tab"           value="unarchive">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-counterclockwise"></i> Unarchive
                </button>
            </form>
            <?php endif; ?>
            <a href="/office/warranty_cos.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>
    <?php if (!$is_new): ?>
    <div class="fia-card-body" style="font-size:.82rem;">
        <div class="row g-2">
            <div class="col-6 col-md-3">
                <span class="text-muted">ID</span><br>
                <strong><?= (int)$wc['warranty_co_id'] ?></strong>
            </div>
            <div class="col-6 col-md-3">
                <span class="text-muted">Location</span><br>
                <?= h(implode(', ', array_filter([$wc['city'] ?? '', $wc['state_code'] ?? ''])) ?: '—') ?>
            </div>
            <div class="col-6 col-md-3">
                <span class="text-muted">Inspections</span><br>
                <?= number_format($insp_total) ?> on record
            </div>
            <div class="col-6 col-md-3">
                <span class="text-muted">Supervisor</span><br>
                <?= h($wc['supervisor_name'] ?? '—') ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TABS
     ══════════════════════════════════════════════════════════════════════ -->
<ul class="nav nav-tabs mb-0" role="tablist">
    <?php
    $tabs = ['details' => 'Details', 'contacts' => 'Contacts', 'rates' => 'Rates / Photos'];
    if (!$is_new) {
        $tabs['history'] = 'Inspection History <span class="badge bg-secondary ms-1">' . $insp_total . '</span>';
        $tabs['emails']  = 'Emails <span class="badge bg-secondary ms-1">' . $email_total . '</span>';
    }
    foreach ($tabs as $slug => $label):
        $is_active = ($active_tab === $slug);
    ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $is_active ? 'active' : '' ?> fia-tab-btn"
                data-bs-toggle="tab" data-bs-target="#tab-<?= $slug ?>"
                data-tab="<?= $slug ?>" type="button" role="tab">
            <?= $label ?>
        </button>
    </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content fia-card border-top-0" style="border-radius:0 0 4px 4px;">

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 1 — DETAILS
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'details' ? 'show active' : '' ?>" id="tab-details" role="tabpanel">
<form method="POST" action="/office/save_warranty_co.php" id="form-details" class="tab-form">
<input type="hidden" name="csrf_token"     value="<?= csrf_token() ?>">
<input type="hidden" name="warranty_co_id" value="<?= $is_new ? '' : $wc_id ?>">
<input type="hidden" name="tab"            value="details">
<div class="fia-card-body">
    <div class="row g-3">

        <!-- Company -->
        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Company</h6>
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label fw-semibold">Company Name <?= $is_new ? '<span class="text-danger">*</span>' : '' ?></label>
                    <input type="text" name="company_name" class="form-control form-control-sm"
                           value="<?= val($wc, 'company_name') ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Address</label>
                    <input type="text" name="address" class="form-control form-control-sm"
                           value="<?= val($wc, 'address') ?>">
                </div>
                <div class="col-5">
                    <label class="form-label fw-semibold">City</label>
                    <input type="text" name="city" class="form-control form-control-sm"
                           value="<?= val($wc, 'city') ?>">
                </div>
                <div class="col-3">
                    <label class="form-label fw-semibold">State</label>
                    <input type="text" name="state_code" class="form-control form-control-sm"
                           value="<?= val($wc, 'state_code') ?>" maxlength="2">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Zip</label>
                    <input type="text" name="zip" class="form-control form-control-sm"
                           value="<?= val($wc, 'zip') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Country</label>
                    <input type="text" name="country" class="form-control form-control-sm"
                           value="<?= val($wc, 'country') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Login Username</label>
                    <input type="text" name="login_username" class="form-control form-control-sm"
                           value="<?= val($wc, 'login_username') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">QuickBooks Ref</label>
                    <input type="text" name="quickbooks_ref" class="form-control form-control-sm"
                           value="<?= val($wc, 'quickbooks_ref') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Tax ID</label>
                    <input type="text" name="tax_id" class="form-control form-control-sm"
                           value="<?= val($wc, 'tax_id') ?>">
                </div>
            </div>
        </div>

        <!-- Contacts -->
        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Phones &amp; Supervisor</h6>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label fw-semibold">FIA Phone</label>
                    <input type="text" name="fia_phone" class="form-control form-control-sm"
                           value="<?= val($wc, 'fia_phone') ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Fax</label>
                    <input type="text" name="fax" class="form-control form-control-sm"
                           value="<?= val($wc, 'fax') ?>">
                </div>
                <div class="col-8">
                    <label class="form-label fw-semibold">Inspector Dial</label>
                    <input type="text" name="inspector_phone" class="form-control form-control-sm"
                           value="<?= val($wc, 'inspector_phone') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Ext</label>
                    <input type="text" name="inspector_phone_ext" class="form-control form-control-sm"
                           value="<?= val($wc, 'inspector_phone_ext') ?>">
                </div>
                <div class="col-12"><hr class="my-1"></div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Supervisor Name</label>
                    <input type="text" name="supervisor_name" class="form-control form-control-sm"
                           value="<?= val($wc, 'supervisor_name') ?>">
                </div>
                <div class="col-8">
                    <label class="form-label fw-semibold">Supervisor Email</label>
                    <input type="email" name="supervisor_email" class="form-control form-control-sm"
                           value="<?= val($wc, 'supervisor_email') ?>">
                </div>
                <div class="col-4">
                    <label class="form-label fw-semibold">Sup. Ext</label>
                    <input type="text" name="supervisor_ext" class="form-control form-control-sm"
                           value="<?= val($wc, 'supervisor_ext') ?>">
                </div>
                <div class="col-12"><hr class="my-1"></div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control form-control-sm" rows="3"><?= val($wc, 'notes') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Other Notes</label>
                    <textarea name="other_notes" class="form-control form-control-sm" rows="2"><?= val($wc, 'other_notes') ?></textarea>
                </div>
            </div>
        </div>

    </div>
    <div class="mt-3">
        <button type="submit" class="btn btn-fia btn-sm save-btn">
            <i class="bi bi-check-circle"></i> <?= $is_new ? 'Create Warranty Co' : 'Save Details' ?>
        </button>
    </div>
</div>
</form>
</div><!-- /#tab-details -->

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 2 — CONTACTS
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'contacts' ? 'show active' : '' ?>" id="tab-contacts" role="tabpanel">
<?php if (!$is_new): ?>
<div class="fia-card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <strong style="font-size:.85rem;">Contact List</strong>
        <button type="button" class="btn btn-fia btn-sm" id="btn-add-contact">
            <i class="bi bi-person-plus"></i> Add Contact
        </button>
    </div>

    <div id="contact-list">
    <?php if (empty($wc_contacts)): ?>
    <p class="text-muted mb-0" style="font-size:.82rem;" id="no-contacts-msg">No contacts on record.</p>
    <?php else: ?>
    <table class="fia-table" id="contacts-table">
        <thead>
            <tr>
                <th class="text-start">Name</th>
                <th class="text-start">Title</th>
                <th class="text-start">Email</th>
                <th>Phone</th>
                <th>Ext</th>
                <th>Notes</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="contacts-tbody">
        <?php foreach ($wc_contacts as $c): ?>
        <tr id="contact-row-<?= (int)$c['contact_id'] ?>">
            <td class="text-start fw-semibold"><?= h($c['contact_name'] ?? '') ?></td>
            <td class="text-start" style="font-size:.78rem;"><?= h($c['title'] ?? '') ?></td>
            <td class="text-start" style="font-size:.78rem;">
                <?php if ($c['email']): ?>
                <a href="mailto:<?= h($c['email']) ?>"><?= h($c['email']) ?></a>
                <?php endif; ?>
            </td>
            <td style="white-space:nowrap;"><?= h($c['phone'] ?? '') ?></td>
            <td><?= h($c['phone_ext'] ?? '') ?></td>
            <td style="font-size:.78rem; max-width:200px;"><?= h($c['notes'] ?? '') ?></td>
            <td style="white-space:nowrap;">
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 btn-edit-contact"
                        data-id="<?= (int)$c['contact_id'] ?>"
                        data-name="<?= h($c['contact_name'] ?? '') ?>"
                        data-title="<?= h($c['title'] ?? '') ?>"
                        data-email="<?= h($c['email'] ?? '') ?>"
                        data-phone="<?= h($c['phone'] ?? '') ?>"
                        data-ext="<?= h($c['phone_ext'] ?? '') ?>"
                        data-notes="<?= h($c['notes'] ?? '') ?>">
                    <i class="bi bi-pencil"></i>
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 ms-1 btn-delete-contact"
                        data-id="<?= (int)$c['contact_id'] ?>">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="fia-card-body text-muted" style="font-size:.82rem;">Save the warranty company first to add contacts.</div>
<?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 2 — RATES / PHOTOS
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'rates' ? 'show active' : '' ?>" id="tab-rates" role="tabpanel">
<form method="POST" action="/office/save_warranty_co.php" id="form-rates" class="tab-form">
<input type="hidden" name="csrf_token"     value="<?= csrf_token() ?>">
<input type="hidden" name="warranty_co_id" value="<?= $is_new ? '' : $wc_id ?>">
<input type="hidden" name="tab"            value="rates">
<div class="fia-card-body">
    <div class="row g-3">

        <div class="col-md-4">
            <h6 class="fw-bold mb-2">Base Rates</h6>
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label fw-semibold">National</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" name="rate_base_national"
                               class="form-control form-control-sm"
                               value="<?= val($wc, 'rate_base_national') ?>">
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Florida</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" name="rate_base_florida"
                               class="form-control form-control-sm"
                               value="<?= val($wc, 'rate_base_florida') ?>">
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Canada</label>
                    <input type="text" name="rate_base_canada" class="form-control form-control-sm"
                           value="<?= val($wc, 'rate_base_canada') ?>"
                           placeholder="e.g. $65 CAD">
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <h6 class="fw-bold mb-2">Instructions</h6>
            <div class="row g-2">
                <div class="col-12">
                    <label class="form-label fw-semibold">Photo Instructions</label>
                    <textarea name="photo_instructions" class="form-control form-control-sm"
                              rows="4"><?= val($wc, 'photo_instructions') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Special Instructions</label>
                    <textarea name="special_instructions" class="form-control form-control-sm"
                              rows="4"><?= val($wc, 'special_instructions') ?></textarea>
                </div>
            </div>
        </div>

    </div>
    <div class="mt-3">
        <button type="submit" class="btn btn-fia btn-sm save-btn">
            <i class="bi bi-check-circle"></i> Save Rates / Photos
        </button>
    </div>
</div>
</form>

<?php if (!$is_new): ?>
<!-- Fee schedule — separate from the save form -->
<div class="fia-card-body border-top">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <strong style="font-size:.85rem;">State Fee Overrides</strong>
        <button type="button" class="btn btn-fia btn-sm" id="btn-add-fee">
            <i class="bi bi-plus-circle"></i> Add State Fee
        </button>
    </div>
    <?php if (empty($fee_schedule)): ?>
    <p class="text-muted mb-0" style="font-size:.82rem;">No state fee overrides on record.</p>
    <?php else: ?>
    <table class="fia-table" style="max-width:400px;">
        <thead>
            <tr>
                <th>State</th>
                <th class="text-end">Base Fee</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($fee_schedule as $f): ?>
        <tr>
            <td class="fw-semibold"><?= h($f['state_code']) ?></td>
            <td class="text-end"><?= $f['fee_base'] !== null ? '$' . number_format((float)$f['fee_base'], 2) : '—' ?></td>
            <td style="white-space:nowrap;">
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2 btn-edit-fee"
                        data-state="<?= h($f['state_code']) ?>"
                        data-fee="<?= h($f['fee_base'] ?? '') ?>">
                    <i class="bi bi-pencil"></i>
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2 ms-1 btn-delete-fee"
                        data-state="<?= h($f['state_code']) ?>">
                    <i class="bi bi-trash"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 3 — INSPECTION HISTORY
     ══════════════════════════════════════════════════════════════════════ -->
<?php if (!$is_new): ?>
<div class="tab-pane fade <?= $active_tab === 'history' ? 'show active' : '' ?>" id="tab-history" role="tabpanel">
<div class="fia-card-body">
    <?php if (empty($insp_history)): ?>
    <p class="text-muted">No inspections on record for this warranty company.</p>
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
                <th>Claim #</th>
                <th>Contract #</th>
                <th class="text-start">Vehicle</th>
                <th>Location</th>
                <th>Inspector</th>
                <th class="text-end">Fee</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($insp_history as $h): ?>
        <tr>
            <td><a href="/office/inspection.php?fia=<?= (int)$h['fia_number'] ?>"><?= (int)$h['fia_number'] ?></a></td>
            <td><span style="font-size:.7rem;"><?= status_badge($h['status'] ?? '') ?></span></td>
            <td style="font-size:.78rem;"><?= h($h['inspection_type'] ?? '—') ?></td>
            <td style="white-space:nowrap;"><?= $h['created_date'] ? date('m/d/Y', strtotime($h['created_date'])) : '—' ?></td>
            <td style="white-space:nowrap;">
                <?= ($h['date_of_inspection'] && $h['date_of_inspection'] !== '0000-00-00')
                    ? date('m/d/Y', strtotime($h['date_of_inspection'])) : '—' ?>
            </td>
            <td><?= h($h['claim_number'] ?? '—') ?></td>
            <td><?= h($h['contract_number'] ?? '—') ?></td>
            <td class="text-start"><?= h(trim(($h['year'] ?? '') . ' ' . ($h['make'] ?? '') . ' ' . ($h['model'] ?? '')) ?: '—') ?></td>
            <td><?= h(implode(', ', array_filter([$h['city'] ?? '', $h['state_code'] ?? ''])) ?: '—') ?></td>
            <td style="font-size:.78rem;"><?= h($h['inspector_name'] ?? '—') ?></td>
            <td class="text-end">
                <?= $h['inspection_fee'] ? '$' . number_format((float)$h['inspection_fee'], 2)
                    : ($h['base_fee']    ? '$' . number_format((float)$h['base_fee'], 2) : '—') ?>
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
                <a class="page-link" href="?id=<?= $wc_id ?>&tab=history&hist_page=<?= $hist_page - 1 ?>">&#8249;</a>
            </li>
            <li class="page-item disabled">
                <span class="page-link"><?= $hist_page ?> / <?= $insp_pages ?></span>
            </li>
            <li class="page-item <?= $hist_page >= $insp_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="?id=<?= $wc_id ?>&tab=history&hist_page=<?= $hist_page + 1 ?>">&#8250;</a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</div>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 5 — EMAILS
     ══════════════════════════════════════════════════════════════════════ -->
<?php if (!$is_new): ?>
<div class="tab-pane fade <?= $active_tab === 'emails' ? 'show active' : '' ?>" id="tab-emails" role="tabpanel">

    <!-- Compose -->
    <div class="fia-card-body border-bottom">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <strong style="font-size:.85rem;">Compose Email</strong>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="wc-toggle-compose">
                <i class="bi bi-chevron-down" id="wc-compose-chevron"></i>
            </button>
        </div>
        <div id="wc-compose-wrap" style="display:none;">
        <form method="POST" action="/office/send_entity_email.php">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="type"       value="warco">
            <input type="hidden" name="entity_id"  value="<?= $wc_id ?>">
            <div class="row g-2">
                <div class="col-md-8">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">To</label>
                    <?php if (empty($wc_contacts)): ?>
                    <input type="email" name="to" class="form-control form-control-sm"
                           placeholder="Enter email address" required>
                    <?php else: ?>
                    <select name="to" class="form-select form-select-sm" required>
                        <option value="">— Select contact —</option>
                        <?php foreach ($wc_contacts as $wcc): if (!$wcc['email']) continue; ?>
                        <option value="<?= h($wcc['email']) ?>">
                            <?= h($wcc['contact_name']) ?> &lt;<?= h($wcc['email']) ?>&gt;
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
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

    <!-- History -->
    <div class="fia-card-body">
        <?php if (empty($wc_emails)): ?>
        <p class="text-muted mb-0">No emails on record for this warranty company.</p>
        <?php else: ?>
        <table class="fia-table">
            <thead>
                <tr>
                    <th>Sent</th>
                    <th>Status</th>
                    <th>FIA #</th>
                    <th class="text-start">To</th>
                    <th class="text-start">Subject</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($wc_emails as $em): ?>
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
                    <a href="/office/inspection.php?fia=<?= (int)$em['fia_number'] ?>"><?= (int)$em['fia_number'] ?></a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="text-start" style="font-size:.78rem;"><?= h($em['to_address'] ?? '') ?></td>
                <td class="text-start"><?= h($em['subject'] ?? '') ?></td>
                <td>
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                            data-bs-toggle="collapse"
                            data-bs-target="#wc-email-body-<?= (int)$em['email_id'] ?>">
                        <i class="bi bi-chevron-down"></i>
                    </button>
                </td>
            </tr>
            <tr class="collapse" id="wc-email-body-<?= (int)$em['email_id'] ?>">
                <td colspan="6">
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
                    <a class="page-link" href="?id=<?= $wc_id ?>&tab=emails&email_page=<?= $email_page - 1 ?>">&#8249;</a>
                </li>
                <li class="page-item disabled">
                    <span class="page-link"><?= $email_page ?> / <?= $email_pages ?></span>
                </li>
                <li class="page-item <?= $email_page >= $email_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?id=<?= $wc_id ?>&tab=emails&email_page=<?= $email_page + 1 ?>">&#8250;</a>
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
<!-- ══════════════════════════════════════════════════════════════════════
     CONTACT ADD / EDIT MODAL
     ══════════════════════════════════════════════════════════════════════ -->
<?php if (!$is_new): ?>
<div class="modal fade" id="contactModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="contactModalTitle">Add Contact</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="contact-id-input" value="">
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Name</label>
                    <input type="text" id="contact-name-input" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Title</label>
                    <input type="text" id="contact-title-input" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Email</label>
                    <input type="email" id="contact-email-input" class="form-control form-control-sm">
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-8">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Phone</label>
                        <input type="text" id="contact-phone-input" class="form-control form-control-sm">
                    </div>
                    <div class="col-4">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Ext</label>
                        <input type="text" id="contact-ext-input" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Notes</label>
                    <textarea id="contact-notes-input" class="form-control form-control-sm" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-fia btn-sm" id="contact-save-btn">Save</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Fee add/edit modal -->
<?php if (!$is_new): ?>
<div class="modal fade" id="feeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="feeModalTitle">Add State Fee</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="fee-orig-state" value="">
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">State Code</label>
                    <input type="text" id="fee-state-input" class="form-control form-control-sm"
                           maxlength="2" placeholder="e.g. FL" style="text-transform:uppercase;">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Base Fee</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" id="fee-amount-input" class="form-control form-control-sm">
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-fia btn-sm" id="fee-save-btn">Save</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="unsavedModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="bi bi-exclamation-triangle text-warning"></i> Unsaved Changes</h6>
            </div>
            <div class="modal-body" style="font-size:.88rem;">You have unsaved changes. What would you like to do?</div>
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
    let dirty = false, activeFormId = 'form-details', pendingTab = null, _modal = null;
    function getModal() { if (!_modal) _modal = new bootstrap.Modal(document.getElementById('unsavedModal')); return _modal; }
    function markDirty() { dirty = true; }
    function clearDirty() { dirty = false; }

    document.querySelectorAll('.tab-form input, .tab-form textarea, .tab-form select').forEach(el => {
        el.addEventListener('change', markDirty);
        el.addEventListener('input', markDirty);
    });
    document.querySelectorAll('.fia-tab-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            if (dirty) {
                e.preventDefault(); e.stopImmediatePropagation();
                pendingTab = this; getModal().show();
            } else {
                history.replaceState(null, '', '?id=<?= $is_new ? '' : $wc_id ?>&tab=' + this.dataset.tab);
                activeFormId = 'form-' + this.dataset.tab;
            }
        });
    });
    document.getElementById('modal-save-btn').addEventListener('click', () => { getModal().hide(); document.getElementById(activeFormId)?.submit(); });
    document.getElementById('modal-discard-btn').addEventListener('click', () => { clearDirty(); getModal().hide(); if (pendingTab) { pendingTab.click(); pendingTab = null; } });
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    document.querySelectorAll('.save-btn').forEach(btn => btn.addEventListener('click', clearDirty));

    // Warranty co email compose toggle
    document.getElementById('wc-toggle-compose')?.addEventListener('click', function () {
        const wrap = document.getElementById('wc-compose-wrap');
        const chev = document.getElementById('wc-compose-chevron');
        const open = wrap.style.display === 'block';
        wrap.style.display = open ? 'none' : 'block';
        chev.className = open ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
    });

    <?php if (!$is_new): ?>
    // ── Fee schedule AJAX ─────────────────────────────────────────────────

    let feeModal = null;
    function getFeeModal() {
        if (!feeModal) feeModal = new bootstrap.Modal(document.getElementById('feeModal'));
        return feeModal;
    }

    function openFeeModal(origState = '', state = '', fee = '') {
        document.getElementById('feeModalTitle').textContent = origState ? 'Edit State Fee' : 'Add State Fee';
        document.getElementById('fee-orig-state').value  = origState;
        document.getElementById('fee-state-input').value = state;
        document.getElementById('fee-amount-input').value = fee;
        // Lock state field when editing
        document.getElementById('fee-state-input').readOnly = !!origState;
        getFeeModal().show();
    }

    document.getElementById('btn-add-fee')?.addEventListener('click', () => openFeeModal());

    document.addEventListener('click', function(e) {
        const editFee = e.target.closest('.btn-edit-fee');
        if (editFee) openFeeModal(editFee.dataset.state, editFee.dataset.state, editFee.dataset.fee);

        const delFee = e.target.closest('.btn-delete-fee');
        if (delFee && confirm('Remove fee for ' + delFee.dataset.state + '?')) {
            feeAction('fee_delete', { state_code: delFee.dataset.state });
        }
    });

    document.getElementById('fee-save-btn')?.addEventListener('click', function() {
        const origState = document.getElementById('fee-orig-state').value;
        const state     = document.getElementById('fee-state-input').value.trim().toUpperCase();
        const fee       = document.getElementById('fee-amount-input').value.trim();
        if (!state) { alert('Enter a state code.'); return; }
        if (!fee)   { alert('Enter a fee amount.'); return; }
        feeAction(origState ? 'fee_update' : 'fee_add', { orig_state: origState, state_code: state, fee_base: fee });
        getFeeModal().hide();
    });

    function feeAction(action, data) {
        const fd = new FormData();
        fd.append('csrf_token',    CSRF);
        fd.append('warranty_co_id', WC_ID);
        fd.append('tab', action);
        for (const [k, v] of Object.entries(data)) fd.append(k, v);
        fetch('/office/save_warranty_co.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.ok) window.location.href = '/office/warranty_co.php?id=<?= $wc_id ?>&tab=rates';
                else alert('Error: ' + (res.error ?? 'unknown'));
            })
            .catch(() => alert('Network error.'));
    }

    // ── Contact list AJAX ─────────────────────────────────────────────────

    const WC_ID       = <?= $wc_id ?>;
    const CSRF        = '<?= csrf_token() ?>';
    let   contactModal = null;

    function getContactModal() {
        if (!contactModal) contactModal = new bootstrap.Modal(document.getElementById('contactModal'));
        return contactModal;
    }

    function openContactModal(d = {}) {
        document.getElementById('contactModalTitle').textContent = d.id ? 'Edit Contact' : 'Add Contact';
        document.getElementById('contact-id-input').value    = d.id    ?? '';
        document.getElementById('contact-name-input').value  = d.name  ?? '';
        document.getElementById('contact-title-input').value = d.title ?? '';
        document.getElementById('contact-email-input').value = d.email ?? '';
        document.getElementById('contact-phone-input').value = d.phone ?? '';
        document.getElementById('contact-ext-input').value   = d.ext   ?? '';
        document.getElementById('contact-notes-input').value = d.notes ?? '';
        getContactModal().show();
    }

    document.getElementById('btn-add-contact')?.addEventListener('click', () => openContactModal());

    document.addEventListener('click', function(e) {
        const edit = e.target.closest('.btn-edit-contact');
        if (edit) {
            openContactModal({
                id: edit.dataset.id, name: edit.dataset.name,
                title: edit.dataset.title, email: edit.dataset.email,
                phone: edit.dataset.phone, ext: edit.dataset.ext,
                notes: edit.dataset.notes,
            });
        }
        const del = e.target.closest('.btn-delete-contact');
        if (del && confirm('Delete this contact?')) {
            contactAction('delete', { contact_id: del.dataset.id });
        }
    });

    document.getElementById('contact-save-btn')?.addEventListener('click', function() {
        const id    = document.getElementById('contact-id-input').value;
        const name  = document.getElementById('contact-name-input').value.trim();
        const title = document.getElementById('contact-title-input').value.trim();
        const email = document.getElementById('contact-email-input').value.trim();
        const phone = document.getElementById('contact-phone-input').value.trim();
        const ext   = document.getElementById('contact-ext-input').value.trim();
        const notes = document.getElementById('contact-notes-input').value.trim();
        if (!name && !email) { alert('Enter at least a name or email.'); return; }
        contactAction(id ? 'update' : 'add', { contact_id: id, name, title, email, phone, ext, notes });
        getContactModal().hide();
    });

    function contactAction(action, data) {
        const fd = new FormData();
        fd.append('csrf_token',    CSRF);
        fd.append('warranty_co_id', WC_ID);
        fd.append('tab',           'contact_' + action);
        for (const [k, v] of Object.entries(data)) fd.append(k, v);

        fetch('/office/save_warranty_co.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    // Reload the contacts section without full page refresh
                    window.location.href = '/office/warranty_co.php?id=<?= $wc_id ?>&tab=contacts';
                } else {
                    alert('Error: ' + (res.error ?? 'unknown'));
                }
            })
            .catch(() => alert('Network error.'));
    }
    <?php endif; ?>

})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
