<?php
/**
 * jobs.php — Inspector job list
 * Shows all active inspections assigned to the logged-in inspector.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_inspector();

$db           = get_db();
$inspector_id = (int)$_SESSION['inspector_id'];

// Filter by status
$valid_statuses = ['Assigned', 'Complete', ''];
$filter_status  = $_GET['status'] ?? 'Assigned';
if (!in_array($filter_status, $valid_statuses, true)) $filter_status = 'Assigned';

// Build query
$where  = ['i.inspector_id = ?', 'i.is_archived = FALSE'];
$params = [$inspector_id];
$types  = 'i';

if ($filter_status !== '') {
    $where[]  = 'i.status = ?';
    $params[] = $filter_status;
    $types   .= 's';
} else {
    $where[] = "i.status IN ('Assigned','Complete')";
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

$stmt = $db->prepare(
    "SELECT i.fia_number, i.status, i.inspection_type,
            i.year, i.make, i.model, i.vin,
            i.repair_shop, i.city, i.state_code, i.zip,
            i.phone_number, i.claim_number, i.contract_number,
            i.date_assigned, i.eta, i.quoted_fee,
            i.date_of_inspection,
            w.company_name AS warranty_co
       FROM inspections i
       LEFT JOIN warranty_co w ON w.warranty_co_id = i.warranty_co_id
      {$where_clause}
      ORDER BY i.date_assigned DESC, i.fia_number DESC"
);
$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) {
    error_log('Query failed [jobs.php/jobs]: ' . $db->error);
    $jobs = [];
} else {
    $jobs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

$page_title = 'My Jobs';
$active_nav = 'jobs';
$flash = null;
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">My Jobs</h5>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <select name="status" class="form-select form-select-sm" style="width:auto;"
                onchange="this.form.submit()">
            <option value="Assigned" <?= $filter_status === 'Assigned' ? 'selected' : '' ?>>Assigned</option>
            <option value="Complete" <?= $filter_status === 'Complete' ? 'selected' : '' ?>>Complete</option>
            <option value=""         <?= $filter_status === ''         ? 'selected' : '' ?>>All Active</option>
        </select>
    </form>
</div>

<?php if (empty($jobs)): ?>
<div class="insp-card">
    <div class="insp-card-body text-center text-muted py-4">
        <i class="bi bi-clipboard-x" style="font-size:2rem;"></i>
        <p class="mt-2 mb-0">No <?= $filter_status ? h($filter_status) . ' ' : '' ?>jobs found.</p>
    </div>
</div>
<?php else: ?>
<div class="insp-card">
    <div class="table-responsive">
        <table class="insp-table">
            <thead>
                <tr>
                    <th>FIA #</th>
                    <th>Status</th>
                    <th>Type</th>
                    <th>Warranty Co</th>
                    <th>Vehicle</th>
                    <th>Shop / Location</th>
                    <th>Assigned</th>
                    <th>ETA</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
            <tr>
                <td data-label="FIA #">
                    <a href="/inspector/job.php?fia=<?= (int)$job['fia_number'] ?>" class="fw-semibold">
                        #<?= (int)$job['fia_number'] ?>
                    </a>
                </td>
                <td data-label="Status">
                    <span class="badge bg-<?= $job['status'] === 'Assigned' ? 'primary' : 'warning text-dark' ?>">
                        <?= h($job['status']) ?>
                    </span>
                </td>
                <td data-label="Type" style="font-size:.78rem;"><?= h($job['inspection_type'] ?? '—') ?></td>
                <td data-label="Warranty Co" style="font-size:.78rem;"><?= h($job['warranty_co'] ?? '—') ?></td>
                <td data-label="Vehicle">
                    <?= h(trim(($job['year'] ?? '') . ' ' . ($job['make'] ?? '') . ' ' . ($job['model'] ?? '')) ?: '—') ?>
                </td>
                <td data-label="Location" style="font-size:.78rem;">
                    <?= h($job['repair_shop'] ?? '') ?><br>
                    <span class="text-muted"><?= h(implode(', ', array_filter([$job['city'] ?? '', $job['state_code'] ?? '']))) ?></span>
                    <?php
                    $map_q = implode(', ', array_filter([$job['repair_shop'] ?? '', $job['city'] ?? '', $job['state_code'] ?? '', $job['zip'] ?? '']));
                    if ($map_q): ?>
                    <a href="https://maps.google.com/?q=<?= urlencode($map_q) ?>" target="_blank" rel="noopener"
                       class="text-muted" title="Open in Google Maps" style="font-size:.75rem;">
                        <i class="bi bi-geo-alt"></i>
                    </a>
                    <?php endif; ?>
                </td>
                <td data-label="Assigned" style="white-space:nowrap;">
                    <?= $job['date_assigned'] ? date('m/d/Y', strtotime($job['date_assigned'])) : '—' ?>
                </td>
                <td data-label="ETA" style="white-space:nowrap;">
                    <?= ($job['eta'] && $job['eta'] !== '0000-00-00') ? date('m/d/Y', strtotime($job['eta'])) : '—' ?>
                </td>
                <td class="text-end" style="white-space:nowrap;">
                    <a href="/inspector/job.php?fia=<?= (int)$job['fia_number'] ?>"
                       class="btn btn-fia btn-sm py-0 me-1" title="View job">
                        <i class="bi bi-eye"></i>
                    </a>
                    <a href="/inspector/generate_worksheet.php?fia=<?= (int)$job['fia_number'] ?>"
                       target="_blank"
                       class="btn btn-outline-secondary btn-sm py-0" title="Print worksheet">
                        <i class="bi bi-printer"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
