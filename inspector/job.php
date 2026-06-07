<?php
/**
 * job.php — Inspection detail for inspectors
 * Replaces: display_A.php, display_report.php, Inspect_photos.php
 *
 * Sections:
 *   1. Job Info (read-only: shop, vehicle, warranty co)
 *   2. Findings form (editable: dates, fluids, report fields)
 *   3. Photo management (grid + drag-drop upload + captions)
 *   4. Complete button
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_inspector();

$db           = get_db();
$inspector_id = (int)$_SESSION['inspector_id'];
$fia          = (int)($_GET['fia'] ?? 0);

if (!$fia) {
    header('Location: /inspector/jobs.php');
    exit;
}

// ── Load inspection (verify it belongs to this inspector) ─────────────────

$stmt = $db->prepare(
    "SELECT i.*,
            w.company_name  AS warranty_co,
            w.supervisor_name, w.supervisor_email,
            w.photo_instructions, w.special_instructions
       FROM inspections i
       LEFT JOIN warranty_co w ON w.warranty_co_id = i.warranty_co_id
      WHERE i.fia_number    = ?
        AND i.inspector_id  = ?
        AND i.is_archived   = FALSE
      LIMIT 1"
);
$stmt->bind_param('ii', $fia, $inspector_id);
if (!$stmt->execute()) {
    error_log('Query failed [job.php/inspection ' . $fia . ']: ' . $db->error);
    header('Location: /inspector/jobs.php?err=1');
    exit;
}
$ins = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ins) {
    header('Location: /inspector/jobs.php');
    exit;
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
    error_log('Query failed [job.php/photos ' . $fia . ']: ' . $db->error);
    $photos = [];
} else {
    $photos = $pics_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$pics_stmt->close();

// ── Complete lock ─────────────────────────────────────────────────────────

$is_complete = ($ins['status'] === 'Complete');

// ── Flash ─────────────────────────────────────────────────────────────────

$flash = null;
if ($is_complete && !isset($_GET['complete'])) {
    $flash = ['type' => 'info', 'msg' => 'This inspection is marked Complete and is read-only. Contact the office if changes are needed.'];
}
if (isset($_GET['saved']))     $flash = ['type' => 'success', 'msg' => 'Report saved.'];
elseif (isset($_GET['uploaded'])) {
    $n = (int)$_GET['uploaded'];
    $flash = ['type' => 'success', 'msg' => $n . ' photo' . ($n !== 1 ? 's' : '') . ' uploaded.'];
}
elseif (isset($_GET['complete'])) $flash = ['type' => 'success', 'msg' => 'Inspection marked Complete.'];
elseif (isset($_GET['locked']))   $flash = ['type' => 'warning', 'msg' => 'This inspection is Complete. No changes were saved. Contact the office to re-open it.'];
elseif (isset($_GET['err']))      $flash = ['type' => 'danger',  'msg' => 'An error occurred. Please try again.'];

// ── Helpers ───────────────────────────────────────────────────────────────

function is_video(string $path): bool {
    return (bool)preg_match('/\.(mp4|mov|avi|wmv|mpeg|mpg)$/i', $path);
}

function val(array $row, string $key): string {
    return htmlspecialchars((string)($row[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}
function fdate(?string $d): string {
    return ($d && $d !== '0000-00-00') ? date('Y-m-d', strtotime($d)) : '';
}
function ftime(?string $t): string {
    return ($t && $t !== '00:00:00') ? date('H:i', strtotime($t)) : '';
}

// On dev, photos live on the production server; on production /vPix/ is served locally.
$vPix_base = DEV_MODE ? '//fiainspectors.com/vPix/' : '/vPix/';

$page_title = 'Job #' . $fia;
$active_nav = 'jobs';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Job header ───────────────────────────────────────────────────────── -->
<div class="insp-card mb-3">
    <div class="insp-card-header d-flex justify-content-between align-items-center">
        <span>FIA #<?= $fia ?> — <?= h($ins['inspection_type'] ?? 'Inspection') ?></span>
        <div class="d-flex gap-1">
            <a href="/inspector/generate_worksheet.php?fia=<?= $fia ?>"
               target="_blank"
               class="btn btn-sm btn-outline-light py-0" title="Print worksheet">
                <i class="bi bi-printer"></i> Worksheet
            </a>
            <a href="/inspector/jobs.php" class="btn btn-sm btn-outline-light py-0">
                <i class="bi bi-arrow-left"></i> Jobs
            </a>
        </div>
    </div>
    <div class="insp-card-body" style="font-size:.85rem;">
        <div class="row g-2">
            <div class="col-6 col-md-3">
                <span class="text-muted d-block">Warranty Co</span>
                <strong><?= h($ins['warranty_co'] ?? '—') ?></strong>
            </div>
            <div class="col-6 col-md-3">
                <span class="text-muted d-block">Claim #</span>
                <?= h($ins['claim_number'] ?? '—') ?>
            </div>
            <div class="col-6 col-md-3">
                <span class="text-muted d-block">Status</span>
                <span class="badge bg-<?= $ins['status'] === 'Assigned' ? 'primary' : 'warning text-dark' ?>">
                    <?= h($ins['status']) ?>
                </span>
            </div>
            <div class="col-6 col-md-3">
                <span class="text-muted d-block">ETA</span>
                <?= ($ins['eta'] && $ins['eta'] !== '0000-00-00') ? date('m/d/Y', strtotime($ins['eta'])) : '—' ?>
            </div>
            <div class="col-12 col-md-6">
                <span class="text-muted d-block">Shop</span>
                <strong><?= h($ins['repair_shop'] ?? '—') ?></strong><br>
                <?= h($ins['address'] ?? '') ?>
                <?= h(implode(', ', array_filter([$ins['city'] ?? '', $ins['state_code'] ?? '']))) ?>
                <?= h($ins['zip'] ?? '') ?><br>
                <?php if ($ins['phone_number']): ?>
                <i class="bi bi-telephone"></i>
                <a href="tel:<?= h($ins['phone_number']) ?>"><?= h($ins['phone_number']) ?></a><br>
                <?php endif; ?>
                <?php
                $map_addr = implode(', ', array_filter([
                    $ins['address']    ?? '',
                    $ins['city']       ?? '',
                    $ins['state_code'] ?? '',
                    $ins['zip']        ?? '',
                ]));
                $gmaps_url = 'https://maps.google.com/?q=' . urlencode($map_addr);
                ?>
                <?php if ($map_addr): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm mt-1 me-1"
                        id="map-modal-btn"
                        data-address="<?= h($map_addr) ?>"
                        title="View on map">
                    <i class="bi bi-map"></i> Map
                </button>
                <a href="<?= h($gmaps_url) ?>" target="_blank" rel="noopener"
                   class="btn btn-outline-secondary btn-sm mt-1" title="Open in Google Maps">
                    <i class="bi bi-box-arrow-up-right"></i> Google Maps
                </a>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-6">
                <span class="text-muted d-block">Vehicle</span>
                <strong><?= h(trim(($ins['year'] ?? '') . ' ' . ($ins['make'] ?? '') . ' ' . ($ins['model'] ?? ''))) ?></strong><br>
                <?php if ($ins['vin']): ?>VIN: <?= h($ins['vin']) ?><br><?php endif; ?>
                <?php if ($ins['color']): ?>Color: <?= h($ins['color']) ?><?php endif; ?>
            </div>
            <?php if ($ins['special_instructions'] || $ins['photo_instructions']): ?>
            <div class="col-12">
                <div class="alert alert-danger py-2 mb-0" style="font-size:.82rem;">
                    <?php if ($ins['special_instructions']): ?>
                    <strong>Special Instructions:</strong> <?= h($ins['special_instructions']) ?><br>
                    <?php endif; ?>
                    <?php if ($ins['photo_instructions']): ?>
                    <strong>Photo Instructions:</strong> <?= h($ins['photo_instructions']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Findings form ────────────────────────────────────────────────────── -->
<div class="insp-card mb-3">
    <div class="insp-card-header">Inspection Report</div>
    <div class="insp-card-body fia-form-section">
    <form method="POST" action="/inspector/save_job.php" id="findings-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="fia"        value="<?= $fia ?>">
        <input type="hidden" name="action"     value="findings">
        <?php if ($is_complete): ?><fieldset disabled><?php endif; ?>

        <div class="row g-3">

            <!-- Dates & basic info -->
            <div class="col-md-6">
                <h6 class="fw-bold mb-2">Inspection Details</h6>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Date of Inspection</label>
                        <input type="date" name="date_of_inspection" class="form-control form-control-sm"
                               value="<?= fdate($ins['date_of_inspection']) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Time</label>
                        <input type="time" name="time_of_inspection" class="form-control form-control-sm"
                               value="<?= ftime($ins['time_of_inspection']) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">RO Number</label>
                        <input type="text" name="ro_no" class="form-control form-control-sm"
                               value="<?= val($ins, 'ro_no') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">RO Date</label>
                        <input type="date" name="ro_date" class="form-control form-control-sm"
                               value="<?= fdate($ins['ro_date']) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Mileage</label>
                        <input type="text" name="mileage" class="form-control form-control-sm"
                               value="<?= val($ins, 'mileage') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Current Mileage</label>
                        <input type="text" name="current_mileage" class="form-control form-control-sm"
                               value="<?= val($ins, 'current_mileage') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Towed / Driven</label>
                        <select name="towed_driven" class="form-select form-select-sm">
                            <?php foreach (['', 'Towed', 'Driven'] as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= ($ins['towed_driven'] ?? '') === $opt ? 'selected' : '' ?>>
                                <?= $opt === '' ? '— select —' : h($opt) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Engine Size</label>
                        <input type="text" name="engine_size" class="form-control form-control-sm"
                               value="<?= val($ins, 'engine_size') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Transmission</label>
                        <input type="hidden" name="transmission_type" id="transmission_type"
                               value="<?= val($ins, 'transmission_type') ?>">
                        <div class="d-flex gap-2">
                            <select id="transmission_select" class="form-select form-select-sm" style="width:auto;flex:0 0 auto;">
                                <?php foreach (['', 'Automatic', 'Manual', 'CVT', 'Allison', 'N/A'] as $opt): ?>
                                <option value="<?= h($opt) ?>"><?= $opt === '' ? '— type —' : h($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" id="transmission_detail" class="form-control form-control-sm"
                                   placeholder="Detail (e.g. 5-speed, 4L60E)">
                        </div>
                        <small class="text-muted" style="font-size:.72rem;">Saved as: <span id="transmission_preview" class="fw-semibold"></span></small>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Drive Train</label>
                        <select name="drive_train" class="form-select form-select-sm">
                            <?php foreach (['', 'Front WD', 'Rear WD', 'All WD', '4x4'] as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= ($ins['drive_train'] ?? '') === $opt ? 'selected' : '' ?>>
                                <?= $opt === '' ? '— select —' : h($opt) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Commercial Use</label>
                        <select name="commercial_use" class="form-select form-select-sm">
                            <?php foreach (['', 'Yes', 'No'] as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= ($ins['commercial_use'] ?? '') === $opt ? 'selected' : '' ?>>
                                <?= $opt === '' ? '— select —' : h($opt) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Impact Damage</label>
                        <select name="impact_damage" class="form-select form-select-sm">
                            <?php foreach (['', 'Yes', 'No'] as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= ($ins['impact_damage'] ?? '') === $opt ? 'selected' : '' ?>>
                                <?= $opt === '' ? '— select —' : h($opt) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Service History Avail</label>
                        <select name="service_history_avail" class="form-select form-select-sm">
                            <?php foreach (['', 'Yes', 'No', 'N/A'] as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= ($ins['service_history_avail'] ?? '') === $opt ? 'selected' : '' ?>>
                                <?= $opt === '' ? '— select —' : h($opt) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Did Shop Sign Report</label>
                        <select name="did_shop_sign_report" class="form-select form-select-sm">
                            <?php foreach (['', 'Yes', 'No'] as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= ($ins['did_shop_sign_report'] ?? '') === $opt ? 'selected' : '' ?>>
                                <?= $opt === '' ? '— select —' : h($opt) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Shop Rep Name</label>
                        <input type="text" name="shop_rep_name" class="form-control form-control-sm"
                               value="<?= val($ins, 'shop_rep_name') ?>">
                    </div>
                </div>
            </div>

            <!-- Fluid conditions -->
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
                $fluid_conditions = ['', 'New', 'Good', 'Fair', 'Poor', 'Burnt', 'Contaminated', 'N/A'];
                $fluid_levels     = ['', 'Full', 'Good', 'Low', 'Empty', 'Drained', 'Needs Service', 'N/A'];
                ?>
                <table class="insp-table mb-3">
                    <thead><tr><th style="text-align: right;">Fluid</th><th>Condition</th><th>Level</th></tr></thead>
                    <tbody>
                    <?php foreach ($fluids as $key => $label):
                        $ck   = $key . '_cond';
                        $lk   = $key . '_level';
                        $cval = $ins[$ck] ?? '';
                        $lval = $ins[$lk] ?? '';
                    ?>
                    <tr>
                        <td style="font-size:.8rem; text-align: right;"><?= $label ?></td>
                        <td>
                            <select name="<?= $ck ?>" class="form-select form-select-sm" <?= $is_complete ? 'disabled' : '' ?>>
                                <?php foreach ($fluid_conditions as $opt): ?>
                                <option value="<?= h($opt) ?>" <?= $cval === $opt ? 'selected' : '' ?>>
                                    <?= $opt === '' ? '— select —' : h($opt) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="<?= $lk ?>" class="form-select form-select-sm" <?= $is_complete ? 'disabled' : '' ?>>
                                <?php foreach ($fluid_levels as $opt): ?>
                                <option value="<?= h($opt) ?>" <?= $lval === $opt ? 'selected' : '' ?>>
                                    <?= $opt === '' ? '— select —' : h($opt) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label fw-semibold">Date Called In</label>
                        <input type="date" name="date_called_in" class="form-control form-control-sm"
                               value="<?= fdate($ins['date_called_in']) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Time Called In</label>
                        <input type="time" name="time_called_in" class="form-control form-control-sm"
                               value="<?= ftime($ins['time_called_in']) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Is Vehicle Torn Down</label>
                        <select name="is_vehicle_torn_down" class="form-select form-select-sm">
                            <?php foreach (['', 'Yes', 'No'] as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= ($ins['is_vehicle_torn_down'] ?? '') === $opt ? 'selected' : '' ?>>
                                <?= $opt === '' ? '— select —' : h($opt) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Amount of Teardown</label>
                        <select name="amount_of_teardown" class="form-select form-select-sm">
                            <?php foreach (['', 'None', 'Partial', 'Full'] as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= ($ins['amount_of_teardown'] ?? '') === $opt ? 'selected' : '' ?>>
                                <?= $opt === '' ? '— select —' : h($opt) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Abuse Apparent</label>
                        <select name="abuse_apparent" class="form-select form-select-sm">
                            <?php foreach (['', 'Yes', 'No'] as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= ($ins['abuse_apparent'] ?? '') === $opt ? 'selected' : '' ?>>
                                <?= $opt === '' ? '— select —' : h($opt) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-semibold">Collision Damage</label>
                        <select name="collision_damage" class="form-select form-select-sm">
                            <?php foreach (['', 'Yes', 'No'] as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= ($ins['collision_damage'] ?? '') === $opt ? 'selected' : '' ?>>
                                <?= $opt === '' ? '— select —' : h($opt) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Report text fields -->
            <div class="col-12">
                <h6 class="fw-bold mb-2">Report</h6>
                <div class="row g-2">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Customer Complaint</label>
                        <textarea name="customer_complaint" class="form-control form-control-sm" rows="3"><?= val($ins, 'customer_complaint') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Cause of Failure</label>
                        <textarea name="cause_of_failure" class="form-control form-control-sm" rows="3"><?= val($ins, 'cause_of_failure') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Corrective Action Needed</label>
                        <textarea name="corrective_action_needed" class="form-control form-control-sm" rows="3"><?= val($ins, 'corrective_action_needed') ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Overall Condition of Vehicle</label>
                        <textarea name="overall_condition" class="form-control form-control-sm" rows="2"><?= val($ins, 'overall_condition') ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Recommended Repairs</label>
                        <textarea name="recommended_repairs" class="form-control form-control-sm" rows="3"><?= val($ins, 'recommended_repairs') ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Inspector's Report</label>
                        <textarea name="inspectors_report" class="form-control form-control-sm" rows="5"><?= val($ins, 'inspectors_report') ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Shop Comments</label>
                        <textarea name="shop_comments" class="form-control form-control-sm" rows="2"><?= val($ins, 'shop_comments') ?></textarea>
                    </div>
                </div>
            </div>

        </div><!-- /.row -->

        <?php if ($is_complete): ?></fieldset><?php endif; ?>

        <?php if (!$is_complete): ?>
        <div class="mt-3 d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-fia">
                <i class="bi bi-check-circle"></i> Save Report
            </button>
            <?php if ($ins['status'] === 'Assigned'): ?>
            <button type="submit" name="action" value="complete"
                    class="btn btn-success"
                    onclick="return confirm('Mark this inspection as Complete? Make sure you have saved your report and uploaded all photos first.')">
                <i class="bi bi-flag-fill"></i> Mark Complete
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </form>
    </div>
</div>

<!-- ── Photo management ──────────────────────────────────────────────────── -->
<div class="insp-card mb-3">
    <div class="insp-card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-images"></i> Photos (<?= count($photos) ?>)</span>
    </div>
    <div class="insp-card-body">

        <!-- Upload zone (hidden when Complete) -->
        <?php if (!$is_complete): ?>
        <form method="POST" action="/inspector/upload.php"
              enctype="multipart/form-data" id="upload-form">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="fia"        value="<?= $fia ?>">

            <div class="upload-zone mb-3" id="drop-zone">
                <i class="bi bi-cloud-upload"></i>
                <p class="mb-1 fw-semibold">Drag &amp; drop photos or videos here</p>
                <p class="text-muted mb-2" style="font-size:.82rem;">Photos up to 20 MB &nbsp;·&nbsp; Videos up to 150 MB (MP4, MOV)</p>
                <input type="file" name="photos[]" id="file-input"
                       accept="image/jpeg,image/png,image/heic,image/webp,video/mp4,video/quicktime,video/*"
                       multiple class="d-none">
                <button type="button" class="btn btn-outline-primary btn-sm" id="browse-btn">
                    <i class="bi bi-folder2-open"></i> Browse
                </button>
            </div>

            <div id="upload-preview" class="photo-grid mb-2" style="display:none;"></div>

            <button type="submit" class="btn btn-fia btn-sm" id="upload-btn" style="display:none;">
                <i class="bi bi-upload"></i> Upload <span id="upload-count"></span> Photo(s)
            </button>
        </form>
        <?php endif; ?>

        <!-- Existing photos -->
        <?php if (!empty($photos)): ?>
        <hr>
        <form method="POST" action="/inspector/save_job.php" id="captions-form">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="fia"        value="<?= $fia ?>">
            <input type="hidden" name="action"     value="captions">

            <div class="photo-grid">
            <?php foreach ($photos as $i => $pic):
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
                           placeholder="Add caption…"
                           <?= $is_complete ? 'disabled' : '' ?>>
                </div>
                <div style="font-size:.7rem; color:#888; padding:0 0.4rem 0.3rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                     title="<?= h($pic['image_path']) ?>">
                    <?= h($pic['image_path']) ?>
                </div>
                <?php if (!$is_complete): ?>
                <div class="photo-actions">
                    <button type="button"
                            class="btn btn-outline-danger btn-sm py-0 px-1 btn-delete-photo"
                            data-id="<?= (int)$pic['picture_id'] ?>"
                            title="Delete photo">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>

            <?php if (!$is_complete): ?>
            <div class="mt-3">
                <button type="submit" class="btn btn-fia btn-sm">
                    <i class="bi bi-check-circle"></i> Save Captions
                </button>
            </div>
            <?php endif; ?>
        </form>
        <?php else: ?>
        <p class="text-muted mt-2 mb-0" style="font-size:.85rem;">No photos uploaded yet.</p>
        <?php endif; ?>

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
<form method="POST" action="/inspector/save_job.php" id="delete-photo-form">
    <input type="hidden" name="csrf_token"  value="<?= csrf_token() ?>">
    <input type="hidden" name="fia"         value="<?= $fia ?>">
    <input type="hidden" name="action"      value="delete_photo">
    <input type="hidden" name="picture_id"  id="delete-picture-id" value="">
</form>

<script>
(function () {
    // ── Existing filenames (for duplicate detection) ───────────────────────
    const uploadedNames = new Set(
        <?= json_encode(array_map(fn($p) => strtolower(basename($p['image_path'])), $photos)) ?>
    );

    // ── Upload zone ───────────────────────────────────────────────────────

    const dropZone    = document.getElementById('drop-zone');
    const fileInput   = document.getElementById('file-input');
    const browseBtn   = document.getElementById('browse-btn');
    const preview     = document.getElementById('upload-preview');
    const uploadBtn   = document.getElementById('upload-btn');
    const uploadCount = document.getElementById('upload-count');

    // Use DataTransfer to manage the queue so items can be removed individually
    let dt = new DataTransfer();

    browseBtn.addEventListener('click', () => fileInput.click());

    dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('drag-over');
        addFiles(e.dataTransfer.files);
    });
    dropZone.addEventListener('click', e => {
        if (e.target !== browseBtn && !browseBtn.contains(e.target)) fileInput.click();
    });

    fileInput.addEventListener('change', () => {
        addFiles(fileInput.files);
        // Reset so the same file can be re-selected after removal
        fileInput.value = '';
    });

    function addFiles(incoming) {
        Array.from(incoming).forEach(f => dt.items.add(f));
        fileInput.files = dt.files;
        renderPreviews();
    }

    function removeFile(index) {
        const fresh = new DataTransfer();
        Array.from(dt.files).forEach((f, i) => { if (i !== index) fresh.items.add(f); });
        dt = fresh;
        fileInput.files = dt.files;
        renderPreviews();
    }

    function renderPreviews() {
        preview.innerHTML = '';
        const files = dt.files;
        if (!files.length) {
            preview.style.display = 'none';
            uploadBtn.style.display = 'none';
            return;
        }

        Array.from(files).forEach((file, idx) => {
            const isDuplicate = uploadedNames.has(file.name.toLowerCase());

            const div = document.createElement('div');
            div.className = 'photo-item';
            div.style.position = 'relative';

            // Thumbnail
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
                media.style.cssText = 'width:100%;height:140px;object-fit:cover;display:block;';
            }
            div.appendChild(media);

            // Filename label + duplicate warning
            const cap = document.createElement('div');
            cap.className = 'photo-caption';
            cap.style.cssText = 'padding:0.3rem 0.5rem;font-size:.75rem;color:#666;word-break:break-all;';
            cap.textContent = file.name;
            if (isDuplicate) {
                const warn = document.createElement('span');
                warn.className = 'badge bg-danger ms-1';
                warn.title = 'A photo with this filename is already uploaded';
                warn.textContent = 'Already uploaded';
                cap.appendChild(warn);
            }
            div.appendChild(cap);

            // Remove button
            const actions = document.createElement('div');
            actions.className = 'photo-actions';
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-outline-danger btn-sm py-0 px-1';
            removeBtn.title = 'Remove from queue';
            removeBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
            removeBtn.addEventListener('click', () => removeFile(idx));
            actions.appendChild(removeBtn);
            div.appendChild(actions);

            preview.appendChild(div);
        });

        preview.style.display = 'grid';
        uploadBtn.style.display = '';
        uploadCount.textContent = files.length;
    }

    // ── Transmission type: dropdown + detail text ────────────────────────

    (function () {
        const hidden   = document.getElementById('transmission_type');
        const select   = document.getElementById('transmission_select');
        const detail   = document.getElementById('transmission_detail');
        const preview  = document.getElementById('transmission_preview');
        if (!hidden) return;

        const options  = ['Automatic', 'Manual', 'CVT', 'Allison', 'N/A'];

        // Parse saved value back into dropdown + detail on page load
        const saved = hidden.value.trim();
        if (saved) {
            let matched = '';
            let rest    = saved;
            for (const opt of options) {
                if (saved.toUpperCase().startsWith(opt.toUpperCase())) {
                    matched = opt;
                    rest    = saved.slice(opt.length).trim();
                    break;
                }
            }
            select.value  = matched;
            detail.value  = rest;
        }

        function sync() {
            const type   = select.value.trim();
            const extra  = detail.value.trim();
            const combined = extra ? (type ? type + ' ' + extra : extra) : type;
            hidden.value       = combined;
            preview.textContent = combined || '—';
        }

        sync();
        select.addEventListener('change', sync);
        detail.addEventListener('input',  sync);
    })();

    // ── Delete uploaded photo ─────────────────────────────────────────────

    document.querySelectorAll('.btn-delete-photo').forEach(btn => {
        btn.addEventListener('click', function () {
            if (!confirm('Delete this photo? This cannot be undone.')) return;
            document.getElementById('delete-picture-id').value = this.dataset.id;
            document.getElementById('delete-photo-form').submit();
        });
    });

})();
</script>

<!-- ── Map modal ──────────────────────────────────────────────────────────── -->
<div class="modal fade" id="mapModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <span class="fw-semibold" id="map-modal-title">
                    <i class="bi bi-geo-alt-fill text-danger"></i>
                    <span id="map-modal-addr"></span>
                </span>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" style="height:420px;">
                <div id="leaflet-map" style="height:100%;width:100%;"></div>
                <div id="map-loading" class="d-flex align-items-center justify-content-center"
                     style="height:100%;display:none!important;">
                    <div class="text-muted"><i class="bi bi-hourglass-split"></i> Loading map…</div>
                </div>
                <div id="map-error" class="d-flex align-items-center justify-content-center p-4"
                     style="height:100%;display:none!important;">
                    <div class="text-center text-muted">
                        <i class="bi bi-exclamation-circle" style="font-size:2rem;"></i>
                        <p class="mt-2 mb-2">Could not locate this address.</p>
                        <a id="map-error-link" href="#" target="_blank" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-box-arrow-up-right"></i> Open in Google Maps
                        </a>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2 justify-content-between">
                <small class="text-muted" id="map-modal-addr-footer"></small>
                <a id="map-gmaps-link" href="#" target="_blank" rel="noopener"
                   class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-box-arrow-up-right"></i> Open in Google Maps
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Leaflet CSS + JS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>

<script>
(function () {
    const mapBtn = document.getElementById('map-modal-btn');
    if (!mapBtn) return;

    let leafletMap = null;

    mapBtn.addEventListener('click', function () {
        const address  = this.dataset.address;
        const gmapsUrl = 'https://maps.google.com/?q=' + encodeURIComponent(address);

        document.getElementById('map-modal-addr').textContent        = address;
        document.getElementById('map-modal-addr-footer').textContent = address;
        document.getElementById('map-gmaps-link').href               = gmapsUrl;
        document.getElementById('map-error-link').href               = gmapsUrl;

        // Show loading state
        document.getElementById('leaflet-map').style.display  = 'none';
        document.getElementById('map-loading').style.display  = 'flex';
        document.getElementById('map-error').style.display    = 'none';

        const modal = new bootstrap.Modal(document.getElementById('mapModal'));
        modal.show();

        // Geocode via Nominatim (OpenStreetMap) — free, no API key
        const encoded = encodeURIComponent(address);
        fetch(`https://nominatim.openstreetmap.org/search?q=${encoded}&format=json&limit=1`, {
            headers: { 'Accept-Language': 'en' }
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('map-loading').style.display = 'none';
            if (!data.length) {
                document.getElementById('map-error').style.display = 'flex';
                return;
            }
            const lat = parseFloat(data[0].lat);
            const lon = parseFloat(data[0].lon);

            document.getElementById('leaflet-map').style.display = 'block';

            if (leafletMap) {
                leafletMap.remove();
                leafletMap = null;
            }
            leafletMap = L.map('leaflet-map').setView([lat, lon], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
            }).addTo(leafletMap);
            L.marker([lat, lon])
                .addTo(leafletMap)
                .bindPopup(address)
                .openPopup();

            // Force redraw after modal animation completes
            document.getElementById('mapModal').addEventListener('shown.bs.modal', () => {
                leafletMap && leafletMap.invalidateSize();
            }, { once: true });
        })
        .catch(() => {
            document.getElementById('map-loading').style.display = 'none';
            document.getElementById('map-error').style.display   = 'flex';
        });
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
