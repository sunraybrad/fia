<?php
/**
 * index.php — Warranty company inspection list
 *
 * Shows only inspections belonging to the logged-in warranty company.
 * Search by FIA#, claim number, or contract number.
 * Status filter: All / Unassigned / Assigned / Complete / Billed / Invoiced
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_warco();

$db       = get_db();
$warco_id = (int)$_SESSION['warco_id'];

// ── Filter state ──────────────────────────────────────────────────────────

$search_fia      = trim($_GET['fia']      ?? '');
$search_claim    = trim($_GET['claim']    ?? '');
$search_contract = trim($_GET['contract'] ?? '');
$filter_status   = trim($_GET['status']   ?? '');

$valid_statuses  = ['', 'Unassigned', 'Assigned', 'Complete', 'Billed', 'Invoiced'];
if (!in_array($filter_status, $valid_statuses, true)) $filter_status = '';

$is_search = ($search_fia !== '' || $search_claim !== '' || $search_contract !== '');

// Pagination
$per_page     = 30;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset       = ($current_page - 1) * $per_page;

// ── Build WHERE ───────────────────────────────────────────────────────────

$where  = ['i.warranty_co_id = ?', 'i.is_archived = FALSE'];
$params = [$warco_id];
$types  = 'i';

// Status counts (for badge display)
$count_stmt = $db->prepare(
    "SELECT status, COUNT(*) AS cnt
       FROM inspections
      WHERE warranty_co_id = ? AND is_archived = FALSE
        AND status IN ('Unassigned','Assigned','Complete','Billed','Invoiced')
      GROUP BY status"
);
$count_stmt->bind_param('i', $warco_id);
$counts = ['Unassigned' => 0, 'Assigned' => 0, 'Complete' => 0, 'Billed' => 0, 'Invoiced' => 0];
if (!$count_stmt->execute()) {
    error_log('Query failed [client/index.php/counts]: ' . $db->error);
} else {
    $counts_raw = $count_stmt->get_result();
    while ($row = $counts_raw->fetch_assoc()) {
        if (isset($counts[$row['status']])) $counts[$row['status']] = (int)$row['cnt'];
    }
}
$count_stmt->close();

if ($search_fia !== '') {
    $where[]  = 'i.fia_number = ?';
    $params[] = (int)$search_fia;
    $types   .= 'i';
}
if ($search_claim !== '') {
    $where[]  = 'i.claim_number LIKE ?';
    $params[] = '%' . $search_claim . '%';
    $types   .= 's';
}
if ($search_contract !== '') {
    $where[]  = 'i.contract_number LIKE ?';
    $params[] = '%' . $search_contract . '%';
    $types   .= 's';
}
if ($filter_status !== '') {
    $where[]  = 'i.status = ?';
    $params[] = $filter_status;
    $types   .= 's';
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

$base_sql = "FROM inspections i
             LEFT JOIN inspectors insp ON insp.inspector_id = i.inspector_id
             $where_clause";

// Count for pagination
$count_sql  = "SELECT COUNT(*) AS cnt $base_sql";
$count_stmt = $db->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
if (!$count_stmt->execute()) {
    error_log('Query failed [client/index.php/total_rows]: ' . $db->error);
    $total_rows = 0;
} else {
    $total_rows = (int)$count_stmt->get_result()->fetch_assoc()['cnt'];
}
$count_stmt->close();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
if ($current_page > $total_pages) $current_page = $total_pages;

// Auto-redirect single search result
if ($is_search && $total_rows === 1) {
    $single_stmt = $db->prepare("SELECT i.fia_number $base_sql LIMIT 1");
    $single_stmt->bind_param($types, ...$params);
    if (!$single_stmt->execute()) {
        error_log('Query failed [client/index.php/single]: ' . $db->error);
        $single = null;
    } else {
        $single = $single_stmt->get_result()->fetch_assoc();
    }
    $single_stmt->close();
    if ($single) {
        header('Location: /client/inspection.php?fia=' . $single['fia_number']);
        exit;
    }
}

// Main query
$list_sql  = "SELECT i.fia_number, i.status, i.claim_number, i.contract_number,
                     i.year, i.make, i.model, i.vin,
                     i.repair_shop, i.city, i.state_code,
                     i.created_date, i.date_called_in, i.date_of_inspection, i.date_assigned,
                     insp.full_name AS inspector_name
              $base_sql
              ORDER BY i.created_date DESC, i.fia_number DESC
              LIMIT ? OFFSET ?";
$list_params = array_merge($params, [$per_page, $offset]);
$list_types  = $types . 'ii';
$list_stmt   = $db->prepare($list_sql);
$list_stmt->bind_param($list_types, ...$list_params);
if (!$list_stmt->execute()) {
    error_log('Query failed [client/index.php/list]: ' . $db->error);
    $inspections = [];
} else {
    $inspections = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$list_stmt->close();

// ── Helpers ───────────────────────────────────────────────────────────────

function status_cls(string $status): string {
    $map = [
        'Unassigned' => 'bg-light text-dark',
        'Assigned'   => 'bg-primary',
        'Complete'   => 'bg-success',
        'Billed'     => 'bg-warning text-dark',
        'Invoiced'   => 'bg-secondary',
    ];
    return $map[$status] ?? 'bg-light text-dark';
}

function warco_status_badge(string $status): string {
    return '<span class="badge ' . status_cls($status) . '">'
        . htmlspecialchars($status, ENT_QUOTES) . '</span>';
}

function build_warco_url(array $overrides = []): string {
    $params = array_filter([
        'fia'      => $_GET['fia']      ?? '',
        'claim'    => $_GET['claim']    ?? '',
        'contract' => $_GET['contract'] ?? '',
        'status'   => $_GET['status']   ?? '',
        'page'     => $_GET['page']     ?? '',
    ]);
    $params = array_merge($params, $overrides);
    return '/client/index.php?' . http_build_query(array_filter($params));
}

$page_title = 'My Inspections';
$active_nav = 'inspections';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0">
        <i class="bi bi-clipboard-check"></i>
        My Inspections
        <small class="text-muted fw-normal ms-2" style="font-size:.75rem;">
            <?= number_format($total_rows) ?> result<?= $total_rows !== 1 ? 's' : '' ?>
        </small>
    </h4>
</div>

<!-- Status badges summary -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <?php foreach ($counts as $st => $cnt): ?>
    <a href="<?= build_warco_url(['status' => $st, 'page' => '']) ?>"
       class="badge text-decoration-none <?= status_cls($st) ?> fs-6"
       style="font-size:.8rem !important; padding:.35em .65em;">
        <?= h($st) ?> <span class="opacity-75"><?= $cnt ?></span>
    </a>
    <?php endforeach; ?>
    <?php if ($filter_status !== ''): ?>
    <a href="<?= build_warco_url(['status' => '', 'page' => '']) ?>"
       class="badge bg-light text-dark text-decoration-none" style="font-size:.8rem; padding:.35em .65em;">
        Clear filter &times;
    </a>
    <?php endif; ?>
</div>

<!-- Search form -->
<form method="GET" action="/client/index.php" class="fia-card mb-3">
    <div class="fia-card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">FIA #</label>
                <input type="number" name="fia" class="form-control form-control-sm"
                       value="<?= h($search_fia) ?>" placeholder="123456">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">Claim #</label>
                <input type="text" name="claim" class="form-control form-control-sm"
                       value="<?= h($search_claim) ?>" placeholder="Claim number">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">Contract #</label>
                <input type="text" name="contract" class="form-control form-control-sm"
                       value="<?= h($search_contract) ?>" placeholder="Contract number">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-semibold mb-1" style="font-size:.8rem;">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach (['Unassigned','Assigned','Complete','Billed','Invoiced'] as $st): ?>
                    <option value="<?= $st ?>" <?= $filter_status === $st ? 'selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-auto d-flex gap-2">
                <button type="submit" class="btn btn-fia btn-sm">
                    <i class="bi bi-search"></i> Search
                </button>
                <?php if ($is_search || $filter_status !== ''): ?>
                <a href="/client/index.php" class="btn btn-outline-secondary btn-sm">Clear</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<!-- Results table -->
<div class="fia-card">
    <div class="table-responsive">
        <table class="fia-table">
            <thead>
                <tr>
                    <th>FIA #</th>
                    <th>Status</th>
                    <th>Requested</th>
                    <th>Received</th>
                    <th>Claim #</th>
                    <th>Contract #</th>
                    <th>Vehicle</th>
                    <th>Shop</th>
                    <th>Insp. Date</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($inspections)): ?>
            <tr>
                <td colspan="7" class="text-center text-muted py-4">
                    <?= $is_search || $filter_status !== '' ? 'No inspections match your search.' : 'No inspections found.' ?>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($inspections as $ins): ?>
            <tr class="inspection-row" style="cursor:pointer;"
                onclick="window.location='/client/inspection.php?fia=<?= (int)$ins['fia_number'] ?>'">
                <td class="fw-semibold"><?= (int)$ins['fia_number'] ?></td>
                <td><?= warco_status_badge($ins['status'] ?? '') ?></td>
                <td style="font-size:.82rem;">
                    <?= ($ins['date_called_in'] && $ins['date_called_in'] !== '0000-00-00')
                        ? date('m/d/Y', strtotime($ins['date_called_in'])) : '—' ?>
                </td>
                <td style="font-size:.82rem;">
                    <?= ($ins['created_date'] && $ins['created_date'] !== '0000-00-00')
                        ? date('m/d/Y', strtotime($ins['created_date'])) : '—' ?>
                </td>
                <td><?= h($ins['claim_number'] ?? '') ?></td>
                <td><?= h($ins['contract_number'] ?? '') ?></td>
                <td style="font-size:.82rem;">
                    <?= h(trim(($ins['year'] ?? '') . ' ' . ($ins['make'] ?? '') . ' ' . ($ins['model'] ?? ''))) ?>
                </td>
                <td style="font-size:.82rem;">
                    <?= h($ins['repair_shop'] ?? '') ?>
                    <?php if ($ins['city'] || $ins['state_code']): ?>
                    <span class="text-muted">— <?= h(trim(($ins['city'] ?? '') . ', ' . ($ins['state_code'] ?? ''), ', ')) ?></span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.82rem;">
                    <?= ($ins['date_of_inspection'] && $ins['date_of_inspection'] !== '0000-00-00')
                        ? date('m/d/Y', strtotime($ins['date_of_inspection'])) : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="fia-card-body py-2 border-top">
        <nav aria-label="Pagination">
            <ul class="pagination pagination-sm mb-0 justify-content-center flex-wrap">
                <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= build_warco_url(['page' => $current_page - 1]) ?>">‹</a>
                </li>
                <?php
                $start = max(1, $current_page - 3);
                $end   = min($total_pages, $current_page + 3);
                if ($start > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?= build_warco_url(['page' => 1]) ?>">1</a></li>
                    <?php if ($start > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                <?php endif; ?>
                <?php for ($p = $start; $p <= $end; $p++): ?>
                <li class="page-item <?= $p === $current_page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= build_warco_url(['page' => $p]) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($end < $total_pages): ?>
                    <?php if ($end < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
                    <li class="page-item"><a class="page-link" href="<?= build_warco_url(['page' => $total_pages]) ?>"><?= $total_pages ?></a></li>
                <?php endif; ?>
                <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= build_warco_url(['page' => $current_page + 1]) ?>">›</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
