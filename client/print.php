<?php
/**
 * print.php — Print-friendly inspection report for warranty companies
 *
 * Opens in new tab from inspection.php. No nav. Print CSS hides controls.
 * Renders all fields + photo grid in a single scrollable page.
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

// Load inspection (must belong to this warco)
$stmt = $db->prepare(
    "SELECT i.*,
            w.company_name   AS warranty_co_name,
            w.supervisor_name, w.supervisor_email,
            insp.full_name   AS inspector_name,
            insp.phone_primary AS inspector_phone,
            insp.phone_cell  AS inspector_cell
       FROM inspections i
       LEFT JOIN warranty_co w    ON w.warranty_co_id  = i.warranty_co_id
       LEFT JOIN inspectors  insp ON insp.inspector_id = i.inspector_id
      WHERE i.fia_number     = ?
        AND i.warranty_co_id = ?
        AND i.is_archived    = FALSE
      LIMIT 1"
);
$stmt->bind_param('ii', $fia, $warco_id);
if (!$stmt->execute()) {
    error_log('Query failed [client/print.php/inspection ' . $fia . ']: ' . $db->error);
    header('Location: /client/index.php');
    exit;
}
$ins = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$ins) { header('Location: /client/index.php'); exit; }

// Load tire record
$tire = null;
if ($ins['inspection_type'] === 'Tire Inspection') {
    $ts = $db->prepare("SELECT * FROM inspection_tires WHERE fia_number = ? LIMIT 1");
    $ts->bind_param('i', $fia);
    if (!$ts->execute()) {
        error_log('Query failed [client/print.php/tire ' . $fia . ']: ' . $db->error);
        $tire = null;
    } else {
        $tire = $ts->get_result()->fetch_assoc();
    }
    $ts->close();
}

// Load photos
$pics_stmt = $db->prepare(
    "SELECT picture_id, image_path, caption
       FROM pictures
      WHERE fia_number  = ?
        AND is_archived = FALSE
      ORDER BY uploaded_at, picture_id"
);
$pics_stmt->bind_param('i', $fia);
if (!$pics_stmt->execute()) {
    error_log('Query failed [client/print.php/photos ' . $fia . ']: ' . $db->error);
    $photos = [];
} else {
    $photos = $pics_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$pics_stmt->close();

// Helpers
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
$warco_name = $_SESSION['warco_name'] ?? '';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FIA Inspection #<?= $fia ?> — <?= h($ins['warranty_co_name'] ?? '') ?></title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <link rel="stylesheet" href="/css/fia.css">

    <style>
        /* ── Screen styles ── */
        body { background: #f5f5f5; }

        .print-page {
            max-width: 900px;
            margin: 1.5rem auto;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 2rem;
        }

        .print-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #6699CC;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }

        .print-header img { height: 48px; }

        .print-toolbar {
            display: flex;
            gap: .5rem;
            justify-content: flex-end;
            margin-bottom: 1rem;
        }

        .section-title {
            font-size: .95rem;
            font-weight: 700;
            color: #333;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: .25rem;
            margin-bottom: .75rem;
            margin-top: 1.25rem;
        }
        .section-title:first-child { margin-top: 0; }

        .field-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: .25rem .75rem;
            font-size: .83rem;
        }
        .field-item .label { color: #888; font-size: .75rem; margin-bottom: 0; }
        .field-item .value { font-weight: 500; }

        .narrative {
            background: #f9f9f9;
            border: 1px solid #e8e8e8;
            border-radius: 4px;
            padding: .5rem .75rem;
            font-size: .83rem;
            white-space: pre-wrap;
            margin-bottom: .75rem;
        }
        .narrative-label { font-size: .8rem; font-weight: 700; color: #555; margin-bottom: .2rem; }

        /* Photo grid */
        .print-photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: .75rem;
            margin-top: .5rem;
        }
        .print-photo-item img,
        .print-photo-item video {
            width: 100%;
            height: 130px;
            object-fit: cover;
            border-radius: 4px;
            display: block;
            background: #000;
        }
        .print-photo-caption {
            font-size: .72rem;
            color: #555;
            text-align: center;
            margin-top: .2rem;
        }

        /* Tire table */
        .tire-table th, .tire-table td {
            font-size: .78rem;
            padding: .25rem .4rem;
        }

        /* ── Print styles ── */
        @media print {
            body { background: #fff; font-size: 10pt; }

            .print-toolbar { display: none !important; }

            .print-page {
                max-width: 100%;
                margin: 0;
                border: none;
                border-radius: 0;
                padding: 0;
            }

            .section-title { color: #000; }
            .narrative { background: #fff; border-color: #ccc; }

            .print-photo-grid {
                grid-template-columns: repeat(4, 1fr);
            }
            .print-photo-item img,
            .print-photo-item video {
                height: 110px;
            }

            /* Avoid breaking inside a photo item */
            .print-photo-item { break-inside: avoid; }

            /* Avoid breaking inside narrative blocks */
            .narrative, .field-grid { break-inside: avoid; }

            a { color: #000 !important; text-decoration: none !important; }
        }
    </style>
</head>
<body>

<div class="print-toolbar d-print-none">
    <button class="btn btn-fia btn-sm" onclick="window.print()">
        <i class="bi bi-printer"></i> Print / Save as PDF
    </button>
    <a href="/client/inspection.php?fia=<?= $fia ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left"></i> Back
    </a>
</div>

<div class="print-page">

    <!-- Report header -->
    <div class="print-header">
        <div>
            <img src="/images/logo_horiz_600.jpg" alt="Florida Inspection Associates">
            <div style="font-size:.8rem; color:#666; margin-top:.3rem;">
                Florida Inspection Associates
            </div>
        </div>
        <div class="text-end">
            <div style="font-size:1.2rem; font-weight:700; color:#333;">
                Inspection Report
            </div>
            <div style="font-size:.85rem; color:#555; margin-top:.25rem;">
                FIA #<?= $fia ?> &nbsp;·&nbsp;
                <span class="badge <?= match($ins['status'] ?? '') {
                    'Complete'   => 'bg-success',
                    'Billed'     => 'bg-warning text-dark',
                    'Invoiced'   => 'bg-secondary',
                    'Unassigned' => 'bg-light text-dark',
                    default      => 'bg-primary'
                } ?>"><?= h($ins['status'] ?? '') ?></span>
            </div>
            <div style="font-size:.75rem; color:#888; margin-top:.2rem;">
                Printed <?= date('m/d/Y g:i A') ?>
            </div>
        </div>
    </div>

    <!-- ── Summary ─────────────────────────────────────────────────────── -->
    <div class="field-grid mb-3">
        <?php
        $summary = [
            'Warranty Company'  => ro($ins, 'warranty_co_name'),
            'Inspection Type'   => ro($ins, 'inspection_type'),
            'Claim #'           => ro($ins, 'claim_number'),
            'Contract #'        => ro($ins, 'contract_number'),
            'Date of Inspection'=> fdate($ins['date_of_inspection']),
            'Inspector'         => ro($ins, 'inspector_name'),
        ];
        foreach ($summary as $label => $value): ?>
        <div class="field-item">
            <p class="label"><?= $label ?></p>
            <p class="value mb-0"><?= $value ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Vehicle ─────────────────────────────────────────────────────── -->
    <div class="section-title">Vehicle</div>
    <div class="field-grid mb-3">
        <?php
        $vehicle_fields = [
            'Year'           => ro($ins, 'year'),
            'Make'           => ro($ins, 'make'),
            'Model'          => ro($ins, 'model'),
            'Color'          => ro($ins, 'color'),
            'Mileage'        => ro($ins, 'mileage'),
            'Current Mileage'=> ro($ins, 'current_mileage'),
            'VIN'            => ro($ins, 'vin'),
            'Complete VIN'   => ro($ins, 'complete_vin'),
            'Tag'            => ro($ins, 'tag'),
            'Tag State'      => ro($ins, 'tag_state'),
            'Labor Rate'     => ro($ins, 'labor_rate'),
        ];
        foreach ($vehicle_fields as $label => $value): ?>
        <div class="field-item">
            <p class="label"><?= $label ?></p>
            <p class="value mb-0"><?= $value ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Repair Shop ─────────────────────────────────────────────────── -->
    <div class="section-title">Repair Shop</div>
    <div class="field-grid mb-3">
        <?php
        $shop_fields = [
            'Shop Name'      => ro($ins, 'repair_shop'),
            'Address'        => ro($ins, 'address'),
            'City'           => ro($ins, 'city'),
            'State'          => ro($ins, 'state_code'),
            'Zip'            => ro($ins, 'zip'),
            'Phone'          => ro($ins, 'phone_number'),
            'Contact'        => ro($ins, 'contact'),
            'Shop Rep'       => ro($ins, 'shop_rep_name'),
            'Signed Report'  => ro($ins, 'did_shop_sign_report'),
            'Called In By'   => ro($ins, 'called_in_by'),
            'Verbal To'      => ro($ins, 'verbal_to'),
        ];
        foreach ($shop_fields as $label => $value): ?>
        <div class="field-item">
            <p class="label"><?= $label ?></p>
            <p class="value mb-0"><?= $value ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($ins['shop_comments']): ?>
    <div class="narrative-label">Shop Comments</div>
    <div class="narrative mb-3"><?= h($ins['shop_comments']) ?></div>
    <?php endif; ?>

    <!-- ── Inspection Details ──────────────────────────────────────────── -->
    <div class="section-title">Inspection Details</div>
    <div class="field-grid mb-3">
        <?php
        $insp_fields = [
            'Date of Inspection'  => fdate($ins['date_of_inspection']),
            'Time'                => ftime($ins['time_of_inspection']),
            'Date Called In'      => fdate($ins['date_called_in']),
            'Time Called In'      => ftime($ins['time_called_in']),
            'RO Number'           => ro($ins, 'ro_no'),
            'RO Date'             => fdate($ins['ro_date']),
            'Commercial Use'      => ro($ins, 'commercial_use'),
            'Impact Damage'       => ro($ins, 'impact_damage'),
            'Service History'     => ro($ins, 'service_history_avail'),
            'Towing'              => ro($ins, 'towing'),
            'Modifications'       => ro($ins, 'modifications'),
            'Engine Size'         => ro($ins, 'engine_size'),
            'Transmission'        => ro($ins, 'transmission_type'),
            'Drive Train'         => ro($ins, 'drive_train'),
            'Towed/Driven'        => ro($ins, 'towed_driven'),
            'Tire Size'           => ro($ins, 'insp_tire_size'),
            'Oversize Tires'      => ro($ins, 'oversize_tires'),
        ];
        foreach ($insp_fields as $label => $value): ?>
        <div class="field-item">
            <p class="label"><?= $label ?></p>
            <p class="value mb-0"><?= $value ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Fluid Conditions ────────────────────────────────────────────── -->
    <div class="section-title">Fluid Conditions</div>
    <table class="table table-sm table-bordered mb-3" style="font-size:.82rem;">
        <thead class="table-light">
            <tr><th>Fluid</th><th>Condition</th><th>Level</th></tr>
        </thead>
        <tbody>
        <?php
        $fluids = [
            'engine_oil'     => 'Engine Oil',
            'coolant'        => 'Coolant',
            'brake_fluid'    => 'Brake Fluid',
            'power_steering' => 'Power Steering',
            'trans_fluid'    => 'Trans Fluid',
        ];
        foreach ($fluids as $key => $label): ?>
        <tr>
            <td><?= $label ?></td>
            <td><?= ro($ins, $key . '_cond', '') ?></td>
            <td><?= ro($ins, $key . '_level', '') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- ── Narrative fields ────────────────────────────────────────────── -->
    <?php
    $narratives = [
        'customer_complaint'       => 'Customer Complaint',
        'overall_condition'        => 'Overall Condition of Vehicle',
        'is_vehicle_torn_down'     => 'Torn Down / Amount',
        'cause_of_failure'         => 'Cause of Failure',
        'corrective_action_needed' => 'Corrective Action Needed',
        'recommended_repairs'      => 'Recommended Repairs',
        'inspectors_report'        => "Inspector's Report",
    ];
    foreach ($narratives as $field => $label):
        if (empty(trim($ins[$field] ?? ''))) continue;
    ?>
    <div class="narrative-label"><?= $label ?></div>
    <div class="narrative"><?= h($ins[$field]) ?></div>
    <?php endforeach; ?>

    <!-- ── Additional findings fields ──────────────────────────────────── -->
    <?php
    $findings_extra = array_filter([
        'Collision Damage'   => ro($ins, 'collision_damage', ''),
        'Failed/Damaged'     => ro($ins, 'failed_damaged', ''),
        'Abuse Apparent'     => ro($ins, 'abuse_apparent', ''),
        'Service Related'    => ro($ins, 'is_service_related', ''),
        'Shop of Failure'    => ro($ins, 'shop_of_failure', ''),
        'Report Called Into' => ro($ins, 'report_called_into', ''),
    ], fn($v) => $v !== '' && $v !== '—');
    if (!empty($findings_extra)): ?>
    <div class="section-title">Additional Findings</div>
    <div class="field-grid mb-3">
        <?php foreach ($findings_extra as $label => $value): ?>
        <div class="field-item">
            <p class="label"><?= $label ?></p>
            <p class="value mb-0"><?= $value ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Tire Inspection ─────────────────────────────────────────────── -->
    <?php if ($ins['inspection_type'] === 'Tire Inspection' && $tire): ?>
    <?php $t = $tire; ?>
    <div class="section-title">Tire Inspection</div>
    <div class="field-grid mb-2">
        <?php foreach ([
            'General Tire Size' => h($t['tire_size_general'] ?? '—'),
            'Factory Tire Size' => h($t['tire_factory_size'] ?? '—'),
            'Brand (all same)'  => h($t['tire_brand_same']   ?? '—'),
            'Size (all same)'   => h($t['tire_size_same']    ?? '—'),
        ] as $label => $value): ?>
        <div class="field-item">
            <p class="label"><?= $label ?></p>
            <p class="value mb-0"><?= $value ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="table-responsive mb-3">
        <table class="table table-sm table-bordered tire-table">
            <thead class="table-light">
                <tr>
                    <th>Position</th><th>Brand</th><th>Size</th><th>Type</th><th>DOT</th>
                    <th>Tread C</th><th>Tread L</th><th>Tread R</th>
                    <th>Fail</th><th>Run Flat</th><th>Wheel Fail</th><th>OFC</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (['lf' => 'Left Front', 'lr' => 'Left Rear', 'rf' => 'Right Front', 'rr' => 'Right Rear'] as $pos => $label): ?>
            <tr>
                <td class="fw-semibold"><?= $label ?></td>
                <td><?= h($t['tire_brand_' . $pos] ?? '') ?></td>
                <td><?= h($t['tire_size_'  . $pos] ?? '') ?></td>
                <td><?= h($t['tire_type_'  . $pos] ?? '') ?></td>
                <td><?= h($t['tire_dot_'   . $pos] ?? '') ?></td>
                <td><?= h($t['tire_tread_' . $pos . '_c'] ?? '') ?></td>
                <td><?= h($t['tire_tread_' . $pos . '_l'] ?? '') ?></td>
                <td><?= h($t['tire_tread_' . $pos . '_r'] ?? '') ?></td>
                <td class="text-center"><?= !empty($t['tire_fail_'    . $pos]) ? '✓' : '' ?></td>
                <td class="text-center"><?= !empty($t['tire_runflat_' . $pos]) ? '✓' : '' ?></td>
                <td class="text-center"><?= !empty($t['wheel_fail_'   . $pos]) ? '✓' : '' ?></td>
                <td><?= h($t['tire_ofc_' . $pos] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── Photos ──────────────────────────────────────────────────────── -->
    <?php if (!empty($photos)): ?>
    <div class="section-title">Photos &amp; Videos (<?= count($photos) ?>)</div>
    <div class="print-photo-grid">
        <?php foreach ($photos as $pic):
            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $pic['image_path'])) continue;
            $src = $vPix_base . $fia . '/' . h($pic['image_path']);
        ?>
        <div class="print-photo-item">
            <?php if (is_video($pic['image_path'])): ?>
            <video src="<?= $src ?>" preload="metadata"
                   style="width:100%;height:130px;object-fit:cover;border-radius:4px;background:#000;"></video>
            <div class="print-photo-caption">
                <i class="bi bi-camera-video"></i> <?= h($pic['caption'] ?? '') ?>
            </div>
            <?php else: ?>
            <img src="<?= $src ?>" alt="Photo"
                 onerror="this.onerror=null;this.src='/images/photo_missing.png'">
            <?php if ($pic['caption']): ?>
            <div class="print-photo-caption"><?= h($pic['caption']) ?></div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Report footer ───────────────────────────────────────────────── -->
    <div style="border-top:1px solid #ddd; margin-top:2rem; padding-top:.75rem;
                font-size:.72rem; color:#888; display:flex; justify-content:space-between;">
        <span>Florida Inspection Associates &mdash; Confidential</span>
        <span>FIA #<?= $fia ?> &mdash; <?= h($warco_name) ?></span>
    </div>

</div><!-- /.print-page -->

<link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script>
// Auto-trigger print dialog if ?autoprint=1
if (new URLSearchParams(location.search).get('autoprint') === '1') {
    window.addEventListener('load', () => window.print());
}
</script>
</body>
</html>
