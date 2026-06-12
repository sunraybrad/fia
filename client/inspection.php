<?php
/**
 * inspection.php — View-only inspection detail for warranty companies
 *
 * Tabs: Vehicle & Shop | Findings 1 | Findings 2 | Tire Inspection | Photos
 * All fields read-only. Print button opens print.php in new tab.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_warco();

$db       = get_db();
$warco_id = (int)$_SESSION['warco_id'];
$fia      = (int)($_GET['fia'] ?? 0);
if (!$fia) { header('Location: /client/index.php'); exit; }

// ── Load inspection (must belong to this warco) ───────────────────────────

$stmt = $db->prepare(
    "SELECT i.*,
            insp.full_name    AS inspector_name,
            insp.phone_primary AS inspector_phone,
            insp.phone_cell   AS inspector_cell
       FROM inspections i
       LEFT JOIN inspectors insp ON insp.inspector_id = i.inspector_id
      WHERE i.fia_number      = ?
        AND i.warranty_co_id  = ?
        AND i.is_archived     = FALSE
      LIMIT 1"
);
$stmt->bind_param('ii', $fia, $warco_id);
if (!$stmt->execute()) {
    error_log('Query failed [client/inspection.php/inspection ' . $fia . ']: ' . $db->error);
    header('Location: /client/index.php');
    exit;
}
$ins = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ins) { header('Location: /client/index.php'); exit; }

// ── Load tire record ──────────────────────────────────────────────────────

$tire = null;
if ($ins['inspection_type'] === 'Tire Inspection') {
    $ts = $db->prepare("SELECT * FROM inspection_tires WHERE fia_number = ? LIMIT 1");
    $ts->bind_param('i', $fia);
    if (!$ts->execute()) {
        error_log('Query failed [client/inspection.php/tire ' . $fia . ']: ' . $db->error);
        $tire = null;
    } else {
        $tire = $ts->get_result()->fetch_assoc();
    }
    $ts->close();
}

// ── Load photos ───────────────────────────────────────────────────────────

$pics_stmt = $db->prepare(
    "SELECT picture_id, image_path, caption, uploaded_at
       FROM pictures
      WHERE fia_number  = ?
        AND is_archived = FALSE
      ORDER BY uploaded_at, picture_id"
);
$pics_stmt->bind_param('i', $fia);
if (!$pics_stmt->execute()) {
    error_log('Query failed [client/inspection.php/photos ' . $fia . ']: ' . $db->error);
    $photos = [];
} else {
    $photos = $pics_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$pics_stmt->close();

// ── Active tab ────────────────────────────────────────────────────────────

$valid_tabs  = ['vehicle', 'findings1', 'findings2', 'tire', 'photos'];
$default_tab = 'vehicle';
if (isset($_GET['tab']) && in_array($_GET['tab'], $valid_tabs, true)) {
    $_SESSION['warco_tab_' . $fia] = $_GET['tab'];
}
$active_tab = $_SESSION['warco_tab_' . $fia] ?? $default_tab;

// ── Helpers ───────────────────────────────────────────────────────────────

function is_video(string $path): bool {
    return (bool)preg_match('/\.(mp4|mov|avi|wmv|mpeg|mpg)$/i', $path);
}

function ro(array $row, string $key, string $fallback = '—'): string {
    $v = trim((string)($row[$key] ?? ''));
    return $v !== '' ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $fallback;
}

function fdate(?string $d, string $fallback = '—'): string {
    return ($d && $d !== '0000-00-00') ? date('m/d/Y', strtotime($d)) : $fallback;
}
function ftime(?string $t, string $fallback = '—'): string {
    return ($t && $t !== '00:00:00') ? date('g:i A', strtotime($t)) : $fallback;
}

$vPix_base = DEV_MODE ? '//fiainspectors.com/vPix/' : '/vPix/';

// Status badge
$status_map = [
    'Unassigned' => 'bg-light text-dark',
    'Assigned'   => 'bg-primary',
    'Complete'   => 'bg-success',
    'Billed'     => 'bg-warning text-dark',
    'Invoiced'   => 'bg-secondary',
];
$status_cls = $status_map[$ins['status'] ?? ''] ?? 'bg-light text-dark';

$page_title = 'FIA #' . $fia;
$active_nav = 'inspections';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Inspection header ─────────────────────────────────────────────────── -->
<div class="fia-card mb-3">
    <div class="fia-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <span class="fw-bold">FIA #<?= $fia ?></span>
            <span class="ms-2 badge <?= $status_cls ?>"><?= h($ins['status'] ?? '') ?></span>
            <span class="ms-2 text-light opacity-75" style="font-size:.85rem;">
                <?= h($ins['inspection_type'] ?? 'Inspection') ?>
            </span>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="/client/print.php?fia=<?= $fia ?>" target="_blank" class="btn btn-sm btn-outline-light py-0">
                <i class="bi bi-printer"></i> Print / PDF
            </a>
            <a href="/client/index.php" class="btn btn-sm btn-outline-light py-0">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>
    <div class="fia-card-body" style="font-size:.85rem;">
        <div class="row g-2">
            <div class="col-6 col-md-3">
                <span class="text-muted d-block">Vehicle</span>
                <strong><?= h(trim(($ins['year'] ?? '') . ' ' . ($ins['make'] ?? '') . ' ' . ($ins['model'] ?? ''))) ?></strong>
            </div>
            <div class="col-6 col-md-3">
                <span class="text-muted d-block">Claim #</span>
                <?= ro($ins, 'claim_number') ?>
            </div>
            <div class="col-6 col-md-3">
                <span class="text-muted d-block">Contract #</span>
                <?= ro($ins, 'contract_number') ?>
            </div>
            <div class="col-6 col-md-3">
                <span class="text-muted d-block">Date of Inspection</span>
                <?= fdate($ins['date_of_inspection']) ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Tabs ──────────────────────────────────────────────────────────────── -->
<div class="fia-card">
<ul class="nav nav-tabs mb-0" id="warcorTabs" role="tablist">
<?php
$tabs = [
    'vehicle'   => 'Vehicle &amp; Shop',
    'findings1' => 'Findings 1',
    'findings2' => 'Findings 2',
    'tire'      => 'Tire Inspection',
    'photos'    => 'Photos <span class="badge bg-secondary ms-1">' . count($photos) . '</span>',
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
     TAB 1 — VEHICLE & SHOP
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'vehicle' ? 'show active' : '' ?>" id="tab-vehicle" role="tabpanel">
<div class="fia-card-body">
    <div class="row g-3">

        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Vehicle</h6>
            <table class="table table-sm table-borderless mb-0" style="font-size:.85rem;">
                <tbody>
                <tr><td class="text-muted" width="40%">Year / Make / Model</td>
                    <td><?= ro($ins,'year') ?> <?= ro($ins,'make','') ?> <?= ro($ins,'model','') ?></td></tr>
                <tr><td class="text-muted">Color</td><td><?= ro($ins,'color') ?></td></tr>
                <tr><td class="text-muted">Mileage</td><td><?= ro($ins,'mileage') ?></td></tr>
                <tr><td class="text-muted">Current Mileage</td><td><?= ro($ins,'current_mileage') ?></td></tr>
                <tr><td class="text-muted">VIN</td><td><?= ro($ins,'vin') ?></td></tr>
                <tr><td class="text-muted">Complete VIN</td><td><?= ro($ins,'complete_vin') ?></td></tr>
                <tr><td class="text-muted">Tag / State</td><td><?= ro($ins,'tag') ?> <?= ro($ins,'tag_state','') ?></td></tr>
                <tr><td class="text-muted">Labor Rate</td><td><?= ro($ins,'labor_rate') ?></td></tr>
                </tbody>
            </table>
        </div>

        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Repair Shop</h6>
            <table class="table table-sm table-borderless mb-0" style="font-size:.85rem;">
                <tbody>
                <tr><td class="text-muted" width="40%">Shop Name</td><td><?= ro($ins,'repair_shop') ?></td></tr>
                <tr><td class="text-muted">Address</td><td><?= ro($ins,'address') ?></td></tr>
                <tr><td class="text-muted">City / State / Zip</td>
                    <td><?= ro($ins,'city','') ?><?= $ins['city'] ? ', ' : '' ?><?= ro($ins,'state_code','') ?> <?= ro($ins,'zip','') ?></td></tr>
                <tr><td class="text-muted">Phone</td><td><?= ro($ins,'phone_number') ?></td></tr>
                <tr><td class="text-muted">Contact</td><td><?= ro($ins,'contact') ?></td></tr>
                <tr><td class="text-muted">Shop Rep</td><td><?= ro($ins,'shop_rep_name') ?></td></tr>
                <tr><td class="text-muted">Signed Report</td><td><?= ro($ins,'did_shop_sign_report') ?></td></tr>
                <?php if ($ins['shop_comments']): ?>
                <tr><td class="text-muted">Comments</td><td><?= h($ins['shop_comments']) ?></td></tr>
                <?php endif; ?>
                <tr><td class="text-muted">Verbal To</td><td><?= ro($ins,'verbal_to') ?></td></tr>
                <tr><td class="text-muted">Called In By</td><td><?= ro($ins,'called_in_by') ?></td></tr>
                </tbody>
            </table>
        </div>

    </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 2 — FINDINGS 1
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'findings1' ? 'show active' : '' ?>" id="tab-findings1" role="tabpanel">
<div class="fia-card-body">
    <div class="row g-3">

        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Fluid Conditions</h6>
            <?php
            $fluids = [
                'engine_oil'     => 'Engine Oil',
                'coolant'        => 'Coolant',
                'brake_fluid'    => 'Brake Fluid',
                'power_steering' => 'Power Steering',
                'trans_fluid'    => 'Trans Fluid',
            ];
            ?>
            <table class="fia-table">
                <thead><tr><th class="text-start">Fluid</th><th>Condition</th><th>Level</th></tr></thead>
                <tbody>
                <?php foreach ($fluids as $key => $label): ?>
                <tr>
                    <td class="text-start" style="font-size:.82rem;"><?= $label ?></td>
                    <td><?= ro($ins, $key . '_cond', '') ?></td>
                    <td><?= ro($ins, $key . '_level', '') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h6 class="fw-bold mb-2 mt-3">Drivetrain</h6>
            <table class="table table-sm table-borderless mb-0" style="font-size:.85rem;">
                <tbody>
                <tr><td class="text-muted" width="45%">Engine Size</td><td><?= ro($ins,'engine_size') ?></td></tr>
                <tr><td class="text-muted">Transmission</td><td><?= ro($ins,'transmission_type') ?></td></tr>
                <tr><td class="text-muted">Drive Train</td><td><?= ro($ins,'drive_train') ?></td></tr>
                <tr><td class="text-muted">Towed/Driven</td><td><?= ro($ins,'towed_driven') ?></td></tr>
                <tr><td class="text-muted">Tire Size</td><td><?= ro($ins,'insp_tire_size') ?></td></tr>
                <tr><td class="text-muted">Oversize Tires</td><td><?= ro($ins,'oversize_tires') ?></td></tr>
                </tbody>
            </table>
        </div>

        <div class="col-md-6">
            <h6 class="fw-bold mb-2">Inspection Details</h6>
            <table class="table table-sm table-borderless mb-0" style="font-size:.85rem;">
                <tbody>
                <tr><td class="text-muted" width="45%">Date of Inspection</td><td><?= fdate($ins['date_of_inspection']) ?></td></tr>
                <tr><td class="text-muted">Time</td><td><?= ftime($ins['time_of_inspection']) ?></td></tr>
                <tr><td class="text-muted">RO Number</td><td><?= ro($ins,'ro_no') ?></td></tr>
                <tr><td class="text-muted">RO Date</td><td><?= fdate($ins['ro_date']) ?></td></tr>
                <tr><td class="text-muted">Commercial Use</td><td><?= ro($ins,'commercial_use') ?></td></tr>
                <tr><td class="text-muted">Impact Damage</td><td><?= ro($ins,'impact_damage') ?></td></tr>
                <tr><td class="text-muted">Service History</td><td><?= ro($ins,'service_history_avail') ?></td></tr>
                <tr><td class="text-muted">Modifications</td><td><?= ro($ins,'modifications') ?></td></tr>
                </tbody>
            </table>
        </div>

        <?php if ($ins['customer_complaint']): ?>
        <div class="col-12">
            <h6 class="fw-bold mb-1">Customer Complaint</h6>
            <div class="p-2 bg-light rounded" style="font-size:.85rem; white-space:pre-wrap;"><?= h($ins['customer_complaint']) ?></div>
        </div>
        <?php endif; ?>

        <?php if ($ins['overall_condition']): ?>
        <div class="col-12">
            <h6 class="fw-bold mb-1">Overall Condition of Vehicle</h6>
            <div class="p-2 bg-light rounded" style="font-size:.85rem; white-space:pre-wrap;"><?= h($ins['overall_condition']) ?></div>
        </div>
        <?php endif; ?>

    </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 3 — FINDINGS 2
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'findings2' ? 'show active' : '' ?>" id="tab-findings2" role="tabpanel">
<div class="fia-card-body">
    <div class="row g-3">

        <div class="col-md-6">
            <table class="table table-sm table-borderless mb-0" style="font-size:.85rem;">
                <tbody>
                <tr><td class="text-muted" width="45%">Date Called In</td><td><?= fdate($ins['date_called_in']) ?></td></tr>
                <tr><td class="text-muted">Time Called In</td><td><?= ftime($ins['time_called_in']) ?></td></tr>
                <tr><td class="text-muted">Torn Down</td><td><?= ro($ins,'is_vehicle_torn_down') ?></td></tr>
                <tr><td class="text-muted">Amount of Teardown</td><td><?= ro($ins,'amount_of_teardown') ?></td></tr>
                <tr><td class="text-muted">Collision Damage</td><td><?= ro($ins,'collision_damage') ?></td></tr>
                <tr><td class="text-muted">Failed/Damaged</td><td><?= ro($ins,'failed_damaged') ?></td></tr>
                <tr><td class="text-muted">Abuse Apparent</td><td><?= ro($ins,'abuse_apparent') ?></td></tr>
                <tr><td class="text-muted">Service Related</td><td><?= ro($ins,'is_service_related') ?></td></tr>
                <tr><td class="text-muted">Shop of Failure</td><td><?= ro($ins,'shop_of_failure') ?></td></tr>
                <tr><td class="text-muted">Report Called Into</td><td><?= ro($ins,'report_called_into') ?></td></tr>
                </tbody>
            </table>
        </div>

        <div class="col-md-6">
            <?php foreach ([
                'cause_of_failure'         => 'Cause of Failure',
                'corrective_action_needed' => 'Corrective Action Needed',
                'recommended_repairs'      => 'Recommended Repairs',
            ] as $field => $label): ?>
            <?php if ($ins[$field]): ?>
            <div class="mb-3">
                <h6 class="fw-bold mb-1"><?= $label ?></h6>
                <div class="p-2 bg-light rounded" style="font-size:.85rem; white-space:pre-wrap;"><?= h($ins[$field]) ?></div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <?php if ($ins['inspectors_report']): ?>
        <div class="col-12">
            <h6 class="fw-bold mb-1">Inspector's Report</h6>
            <div class="p-2 bg-light rounded" style="font-size:.85rem; white-space:pre-wrap;"><?= h($ins['inspectors_report']) ?></div>
        </div>
        <?php endif; ?>

    </div>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 4 — TIRE INSPECTION
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'tire' ? 'show active' : '' ?>" id="tab-tire" role="tabpanel">
<div class="fia-card-body">
<?php if ($ins['inspection_type'] !== 'Tire Inspection'): ?>
    <p class="text-muted">This is not a tire inspection.</p>
<?php else: ?>
    <?php $t = $tire ?? []; ?>

    <div class="row g-2 mb-3" style="font-size:.85rem;">
        <div class="col-6 col-md-3">
            <span class="text-muted d-block">General Tire Size</span>
            <?= h($t['tire_size_general'] ?? '—') ?>
        </div>
        <div class="col-6 col-md-3">
            <span class="text-muted d-block">Factory Tire Size</span>
            <?= h($t['tire_factory_size'] ?? '—') ?>
        </div>
        <div class="col-6 col-md-3">
            <span class="text-muted d-block">Brand (all same)</span>
            <?= h($t['tire_brand_same'] ?? '—') ?>
        </div>
        <div class="col-6 col-md-3">
            <span class="text-muted d-block">Size (all same)</span>
            <?= h($t['tire_size_same'] ?? '—') ?>
        </div>
    </div>

    <?php $positions = ['lf' => 'Left Front', 'lr' => 'Left Rear', 'rf' => 'Right Front', 'rr' => 'Right Rear']; ?>
    <div class="table-responsive">
    <table class="fia-table">
        <thead>
            <tr>
                <th class="text-start">Position</th>
                <th>Brand</th><th>Size</th><th>Type</th><th>DOT</th>
                <th>Tread C</th><th>Tread L</th><th>Tread R</th>
                <th>Fail</th><th>Run Flat</th><th>Wheel Fail</th><th>OFC</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($positions as $pos => $label): ?>
        <tr>
            <td class="text-start fw-semibold" style="font-size:.82rem;"><?= $label ?></td>
            <td><?= h($t['tire_brand_' . $pos] ?? '') ?></td>
            <td><?= h($t['tire_size_'  . $pos] ?? '') ?></td>
            <td><?= h($t['tire_type_'  . $pos] ?? '') ?></td>
            <td><?= h($t['tire_dot_'   . $pos] ?? '') ?></td>
            <td><?= h($t['tire_tread_' . $pos . '_c'] ?? '') ?></td>
            <td><?= h($t['tire_tread_' . $pos . '_l'] ?? '') ?></td>
            <td><?= h($t['tire_tread_' . $pos . '_r'] ?? '') ?></td>
            <td class="text-center"><?= !empty($t['tire_fail_'    . $pos]) ? '<i class="bi bi-check-lg text-danger"></i>' : '' ?></td>
            <td class="text-center"><?= !empty($t['tire_runflat_' . $pos]) ? '<i class="bi bi-check-lg text-primary"></i>' : '' ?></td>
            <td class="text-center"><?= !empty($t['wheel_fail_'   . $pos]) ? '<i class="bi bi-check-lg text-danger"></i>' : '' ?></td>
            <td><?= h($t['tire_ofc_' . $pos] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     TAB 5 — PHOTOS
     ══════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade <?= $active_tab === 'photos' ? 'show active' : '' ?>" id="tab-photos" role="tabpanel">
<div class="fia-card-body">
<?php if (empty($photos)): ?>
    <p class="text-muted mb-0" style="font-size:.85rem;">No photos available for this inspection.</p>
<?php else: ?>
    <div class="photo-grid">
    <?php foreach ($photos as $i => $pic):
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $pic['image_path'])) continue;
        $src = $vPix_base . $fia . '/' . h($pic['image_path']);
    ?>
    <div class="photo-item" data-media-index="<?= $i ?>">
        <?php if (is_video($pic['image_path'])): ?>
        <video src="<?= $src ?>" controls preload="metadata"
               class="media-thumb"
               data-src="<?= $src ?>" data-type="video" data-caption="<?= h($pic['caption'] ?? '') ?>"
               style="width:100%;height:140px;object-fit:cover;display:block;background:#000;cursor:pointer;"></video>
        <?php else: ?>
        <img src="<?= $src ?>" alt="Photo <?= $i + 1 ?>"
             class="media-thumb"
             data-src="<?= $src ?>" data-type="image" data-caption="<?= h($pic['caption'] ?? '') ?>"
             onerror="this.onerror=null;this.src='/images/photo_missing.png'"
             style="cursor:pointer;">
        <?php endif; ?>
        <?php if ($pic['caption']): ?>
        <div class="photo-caption">
            <span style="padding:.25rem .5rem; font-size:.75rem;"><?= h($pic['caption']) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>
</div>

</div><!-- /.tab-content -->
</div><!-- /.fia-card -->

<!-- ── Media lightbox modal ─────────────────────────────────────────────── -->
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

<script>
// Persist active tab in session via fetch
document.querySelectorAll('[data-tab]').forEach(btn => {
    btn.addEventListener('shown.bs.tab', () => {
        fetch('/client/set_tab.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'fia=<?= $fia ?>&tab=' + btn.dataset.tab
                + '&csrf_token=<?= csrf_token() ?>'
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
