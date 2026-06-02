<?php
/**
 * index.php — Office dashboard
 *
 * Displays inspections filtered by status tab (Unassigned / Complete / Billed).
 * The selected tab persists in session so returning users see the same view.
 * Also provides a search by FIA number, inspector name, or warranty company.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

$db = get_db();

// ── Filter / search state ─────────────────────────────────────────────────

// Valid status tabs
$valid_tabs = ['Unassigned', 'Complete', 'Billed'];

// Tab selection: POST or GET change → store in session; otherwise use session
if (isset($_GET['tab']) && in_array($_GET['tab'], $valid_tabs, true)) {
    $_SESSION['dash_tab'] = $_GET['tab'];
}
$active_tab = $_SESSION['dash_tab'] ?? 'Unassigned';

// Search terms (GET — bookmarkable)
$search_fia      = trim($_GET['fia']      ?? '');
$search_inspector = trim($_GET['inspector'] ?? '');
$search_warco    = trim($_GET['warco']    ?? '');
$search_claim    = trim($_GET['claim']    ?? '');
$search_contract = trim($_GET['contract'] ?? '');
$show_archived   = isset($_GET['archived']);
$is_search       = ($search_fia !== '' || $search_inspector !== '' || $search_warco !== ''
                    || $search_claim !== '' || $search_contract !== '');

// Pagination
$per_page    = 30;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($current_page - 1) * $per_page;

// ── Status counts for tab badges ─────────────────────────────────────────

$count_sql = "SELECT status, COUNT(*) AS cnt
              FROM inspections
              WHERE is_archived = FALSE
                AND status IN ('Unassigned','Complete','Billed')
              GROUP BY status";
$counts_raw = $db->query($count_sql);
$counts = ['Unassigned' => 0, 'Complete' => 0, 'Billed' => 0];
while ($row = $counts_raw->fetch_assoc()) {
    $counts[$row['status']] = (int)$row['cnt'];
}

// ── Overall active count (for collapsible panel) ──────────────────────────

$active_count_row = $db->query(
    "SELECT COUNT(*) AS cnt FROM inspections WHERE is_archived = FALSE"
)->fetch_assoc();
$active_count = (int)$active_count_row['cnt'];

// ── Main query ────────────────────────────────────────────────────────────

// Build WHERE dynamically
$where   = $show_archived ? [] : ['i.is_archived = FALSE'];
$params  = [];
$types   = '';

if ($is_search) {
    // Search overrides tab filter
    if ($search_fia !== '') {
        $where[] = 'i.fia_number = ?';
        $params[] = (int)$search_fia;
        $types   .= 'i';
    }
    if ($search_inspector !== '') {
        $where[] = 'insp.full_name LIKE ?';
        $params[] = '%' . $search_inspector . '%';
        $types   .= 's';
    }
    if ($search_warco !== '') {
        $where[] = 'w.company_name LIKE ?';
        $params[] = '%' . $search_warco . '%';
        $types   .= 's';
    }
    if ($search_claim !== '') {
        $where[] = 'i.claim_number LIKE ?';
        $params[] = '%' . $search_claim . '%';
        $types   .= 's';
    }
    if ($search_contract !== '') {
        $where[] = 'i.contract_number LIKE ?';
        $params[] = '%' . $search_contract . '%';
        $types   .= 's';
    }
} else {
    $where[] = 'i.status = ?';
    $params[] = $active_tab;
    $types   .= 's';
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

$base_sql = "FROM inspections i
             LEFT JOIN inspectors insp ON insp.inspector_id = i.inspector_id
             LEFT JOIN warranty_co w   ON w.warranty_co_id  = i.warranty_co_id
             {$where_clause}";

// Count for pagination
$count_stmt = $db->prepare("SELECT COUNT(*) AS cnt {$base_sql}");
if ($params) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_rows  = (int)$count_stmt->get_result()->fetch_assoc()['cnt'];
$total_pages = (int)ceil($total_rows / $per_page);
$current_page = min($current_page, max(1, $total_pages));
$count_stmt->close();

// Fetch rows
$list_params  = $params;
$list_types   = $types . 'ii';
$list_params[] = $per_page;
$list_params[] = ($current_page - 1) * $per_page;

$list_stmt = $db->prepare(
    "SELECT
        i.fia_number,
        i.inspection_type,
        i.status,
        i.created_date,
        i.date_assigned,
        i.claim_number,
        i.contract_number,
        i.year,
        i.make,
        i.model,
        i.city,
        i.state_code,
        i.repair_shop,
        insp.full_name  AS inspector_name,
        w.company_name  AS warranty_co
     {$base_sql}
     ORDER BY i.created_date DESC, i.fia_number DESC
     LIMIT ? OFFSET ?"
);
$list_stmt->bind_param($list_types, ...$list_params);
$list_stmt->execute();
$rows = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$list_stmt->close();

// Auto-redirect when search returns exactly one result
if ($is_search && count($rows) === 1) {
    header('Location: /office/inspection.php?fia=' . (int)$rows[0]['fia_number']);
    exit;
}

// ── URL helpers ───────────────────────────────────────────────────────────

// Preserve search params across pagination
function page_url(int $p): string {
    $q = array_filter([
        'fia'       => $_GET['fia']       ?? '',
        'inspector' => $_GET['inspector'] ?? '',
        'warco'     => $_GET['warco']     ?? '',
        'claim'     => $_GET['claim']     ?? '',
        'contract'  => $_GET['contract']  ?? '',
        'archived'  => $show_archived ? '1' : '',
        'tab'       => $_GET['tab']       ?? '',
        'page'      => $p > 1 ? $p : null,
    ]);
    return '/office/index.php' . ($q ? '?' . http_build_query($q) : '');
}

// ── Page output ───────────────────────────────────────────────────────────

$page_title = 'Dashboard';
$active_nav = 'dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Status tab bar ──────────────────────────────────────────────────── -->
<?php if (!$is_search): ?>
<ul class="nav nav-tabs mb-3">
    <?php foreach ($valid_tabs as $tab): ?>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === $tab ? 'active' : '' ?>"
           href="/office/index.php?tab=<?= urlencode($tab) ?>">
            <?= h($tab) ?>
            <?php if ($counts[$tab] > 0): ?>
            <span class="badge <?= $active_tab === $tab ? 'bg-light text-dark' : 'bg-secondary' ?> ms-1">
                <?= $counts[$tab] ?>
            </span>
            <?php endif; ?>
        </a>
    </li>
    <?php endforeach; ?>
    <li class="nav-item ms-auto align-self-center">
        <small class="text-muted">
            <a href="/office/index.php?tab=<?= urlencode($active_tab) ?>"
               class="text-muted text-decoration-none">
                &#8635; Refresh
            </a>
        </small>
    </li>
</ul>
<?php endif; ?>

<!-- ── Search bar ──────────────────────────────────────────────────────── -->
<form method="GET" action="/office/index.php" class="fia-search-bar mb-3 row g-2 align-items-end">
    <input type="hidden" name="tab" value="<?= h($active_tab) ?>">
    <div class="col-6 col-md-2">
        <label class="form-label mb-1 fw-semibold" style="font-size:.8rem;">FIA #</label>
        <input type="number" name="fia" class="form-control form-control-sm"
               value="<?= h($search_fia) ?>" placeholder="e.g. 12345">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label mb-1 fw-semibold" style="font-size:.8rem;">Claim #</label>
        <input type="text" name="claim" class="form-control form-control-sm"
               value="<?= h($search_claim) ?>" placeholder="Claim #…">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label mb-1 fw-semibold" style="font-size:.8rem;">Contract #</label>
        <input type="text" name="contract" class="form-control form-control-sm"
               value="<?= h($search_contract) ?>" placeholder="Contract #…">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label mb-1 fw-semibold" style="font-size:.8rem;">Inspector</label>
        <input type="text" name="inspector" class="form-control form-control-sm"
               value="<?= h($search_inspector) ?>" placeholder="Name contains…">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label mb-1 fw-semibold" style="font-size:.8rem;">Warranty Co</label>
        <input type="text" name="warco" class="form-control form-control-sm"
               value="<?= h($search_warco) ?>" placeholder="Company contains…">
    </div>
    <div class="col-6 col-md-1 d-flex align-items-end pb-1">
        <div class="form-check mb-0">
            <input class="form-check-input" type="checkbox" name="archived" id="chk-archived"
                   value="1" <?= $show_archived ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="chk-archived" style="font-size:.8rem;">
                Archived
            </label>
        </div>
    </div>
    <div class="col-6 col-md-1 d-flex gap-2">
        <button type="submit" class="btn btn-fia btn-sm flex-grow-1">Search</button>
        <?php if ($is_search || $show_archived): ?>
        <a href="/office/index.php?tab=<?= urlencode($active_tab) ?>"
           class="btn btn-outline-secondary btn-sm">Clear</a>
        <?php endif; ?>
    </div>
</form>

<?php if ($is_search): ?>
<p class="text-muted mb-2" style="font-size:.82rem;">
    Search results — <?= $total_rows ?> record<?= $total_rows !== 1 ? 's' : '' ?> found.
    <a href="/office/index.php?tab=<?= urlencode($active_tab) ?>" class="ms-2">← Back to <?= h($active_tab) ?></a>
</p>
<?php endif; ?>

<!-- ── Overall active count (collapsible) ──────────────────────────────── -->
<div class="mb-3">
    <a class="text-muted text-decoration-none" style="font-size:.82rem;"
       data-bs-toggle="collapse" href="#activeCollapse" role="button"
       aria-expanded="false" aria-controls="activeCollapse">
        <i class="bi bi-chevron-right" id="collapseIcon"></i>
        Total active inspections: <strong><?= $active_count ?></strong>
    </a>
    <div class="collapse" id="activeCollapse">
        <div class="fia-legend mt-2" style="font-size:.8rem;">
            <?php
            $breakdown_res = $db->query(
                "SELECT status, COUNT(*) AS cnt
                 FROM inspections
                 WHERE is_archived = FALSE
                 GROUP BY status
                 ORDER BY cnt DESC"
            );
            while ($br = $breakdown_res->fetch_assoc()): ?>
            <span class="me-3">
                <strong><?= h($br['status']) ?></strong>: <?= (int)$br['cnt'] ?>
            </span>
            <?php endwhile; ?>
        </div>
    </div>
</div>

<!-- ── Inspections table ────────────────────────────────────────────────── -->
<div class="fia-card">
    <div class="fia-page-header d-flex justify-content-between align-items-center">
        <span>
            <?= $is_search ? 'Search Results' : h($active_tab) . ' Inspections' ?>
        </span>
        <span class="subtext">
            <?= $total_rows ?> record<?= $total_rows !== 1 ? 's' : '' ?>
            <?php if ($total_pages > 1): ?>
            &mdash; page <?= $current_page ?> of <?= $total_pages ?>
            <?php endif; ?>
        </span>
    </div>

    <?php if (empty($rows)): ?>
    <div class="fia-card-body text-center text-muted py-4">
        <i class="bi bi-clipboard-x fs-3 d-block mb-2"></i>
        No inspections found.
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="fia-table">
            <thead>
                <tr>
                    <th>FIA #</th>
                    <th>Type</th>
                    <th>Created</th>
                    <th>Warranty Co</th>
                    <th>Claim #</th>
                    <th>Contract #</th>
                    <th>Vehicle</th>
                    <th>Location</th>
                    <th>Inspector</th>
                    <?php if ($is_search): ?><th>Status</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td data-label="FIA #">
                        <a href="/office/inspection.php?fia=<?= (int)$row['fia_number'] ?>">
                            <?= (int)$row['fia_number'] ?>
                        </a>
                    </td>
                    <td data-label="Type">
                        <?= h($row['inspection_type'] ?? '—') ?>
                    </td>
                    <td data-label="Created">
                        <?= $row['created_date'] ? date('m/d/Y', strtotime($row['created_date'])) : '—' ?>
                    </td>
                    <td data-label="Warranty Co" class="text-start">
                        <?= h($row['warranty_co'] ?? '—') ?>
                    </td>
                    <td data-label="Claim #">
                        <?= h($row['claim_number'] ?? '—') ?>
                    </td>
                    <td data-label="Contract #">
                        <?= h($row['contract_number'] ?? '—') ?>
                    </td>
                    <td data-label="Vehicle" class="text-start">
                        <?= h(trim(($row['year'] ?? '') . ' ' . ($row['make'] ?? '') . ' ' . ($row['model'] ?? '')) ?: '—') ?>
                    </td>
                    <td data-label="Location">
                        <?php
                        $loc = array_filter([$row['city'] ?? '', $row['state_code'] ?? '']);
                        echo h($loc ? implode(', ', $loc) : '—');
                        ?>
                    </td>
                    <td data-label="Inspector">
                        <?= $row['inspector_name']
                            ? h($row['inspector_name'])
                            : '<span class="text-danger">Unassigned</span>' ?>
                    </td>
                    <?php if ($is_search): ?>
                    <td data-label="Status">
                        <span class="badge bg-secondary"><?= h($row['status'] ?? '') ?></span>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="fia-card-body fia-pagination d-flex justify-content-center">
        <nav aria-label="Inspections pagination">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= page_url($current_page - 1) ?>">&lsaquo;</a>
                </li>
                <?php
                $win_start = max(1, $current_page - 3);
                $win_end   = min($total_pages, $current_page + 3);
                if ($win_start > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?= page_url(1) ?>">1</a></li>
                    <?php if ($win_start > 2): ?>
                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                <?php endif; ?>
                <?php for ($p = $win_start; $p <= $win_end; $p++): ?>
                <li class="page-item <?= $p === $current_page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= page_url($p) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($win_end < $total_pages): ?>
                    <?php if ($win_end < $total_pages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="<?= page_url($total_pages) ?>"><?= $total_pages ?></a></li>
                <?php endif; ?>
                <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= page_url($current_page + 1) ?>">&rsaquo;</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
// Rotate chevron when collapsible opens/closes
document.getElementById('activeCollapse').addEventListener('show.bs.collapse', function () {
    document.getElementById('collapseIcon').classList.replace('bi-chevron-right', 'bi-chevron-down');
});
document.getElementById('activeCollapse').addEventListener('hide.bs.collapse', function () {
    document.getElementById('collapseIcon').classList.replace('bi-chevron-down', 'bi-chevron-right');
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
