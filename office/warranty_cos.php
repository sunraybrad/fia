<?php
/**
 * warranty_cos.php — Warranty company roster
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

$db = get_db();

// ── Filter state ──────────────────────────────────────────────────────────

$search_name   = trim($_GET['name']  ?? '');
$search_state  = trim(strtoupper($_GET['state'] ?? ''));
$filter_status = $_GET['status'] ?? 'active';   // active | archived | all
if (!in_array($filter_status, ['active', 'archived', 'all'], true)) $filter_status = 'active';
$is_search     = ($search_name !== '' || $search_state !== '');

// ── Pagination ────────────────────────────────────────────────────────────

$per_page     = 40;
$current_page = max(1, (int)($_GET['page'] ?? 1));

// ── Main query ────────────────────────────────────────────────────────────

$where  = match($filter_status) {
    'archived' => ['is_archived = TRUE'],
    'all'      => [],
    default    => ['is_archived = FALSE'],
};
$params = [];
$types  = '';

if ($search_name !== '') {
    $where[]  = 'company_name LIKE ?';
    $params[] = '%' . $search_name . '%';
    $types   .= 's';
}
if ($search_state !== '') {
    $where[]  = 'state_code = ?';
    $params[] = $search_state;
    $types   .= 's';
}

$where_clause = 'WHERE ' . implode(' AND ', $where);
$base_sql     = "FROM warranty_co {$where_clause}";

$cnt_stmt = $db->prepare("SELECT COUNT(*) AS cnt {$base_sql}");
if ($params) $cnt_stmt->bind_param($types, ...$params);
$cnt_stmt->execute();
$total_rows  = (int)$cnt_stmt->get_result()->fetch_assoc()['cnt'];
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$current_page = min($current_page, $total_pages);
$cnt_stmt->close();

// Auto-redirect on single search result
$list_params   = $params;
$list_types    = $types . 'ii';
$list_params[] = $per_page;
$list_params[] = ($current_page - 1) * $per_page;

$list_stmt = $db->prepare(
    "SELECT warranty_co_id, company_name, city, state_code, zip,
            fia_phone, supervisor_name, supervisor_email,
            rate_base_national, rate_base_florida, rate_base_canada,
            is_archived
     {$base_sql}
     ORDER BY company_name
     LIMIT ? OFFSET ?"
);
$list_stmt->bind_param($list_types, ...$list_params);
$list_stmt->execute();
$rows = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$list_stmt->close();

if ($is_search && count($rows) === 1) {
    header('Location: /office/warranty_co.php?id=' . (int)$rows[0]['warranty_co_id']);
    exit;
}

// ── URL helper ────────────────────────────────────────────────────────────

function page_url(int $p): string {
    $q = array_filter([
        'name'   => $_GET['name']   ?? '',
        'state'  => $_GET['state']  ?? '',
        'status' => $filter_status !== 'active' ? $filter_status : '',
        'page'   => $p > 1 ? $p : null,
    ]);
    return '/office/warranty_cos.php' . ($q ? '?' . http_build_query($q) : '');
}

// ── Page output ───────────────────────────────────────────────────────────

$page_title = 'Warranty Companies';
$active_nav = 'clients';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Toolbar ──────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Warranty Companies</h5>
    <a href="/office/warranty_co.php?new=1" class="btn btn-fia btn-sm">
        <i class="bi bi-building-add"></i> New Warranty Co
    </a>
</div>

<!-- ── Search bar ───────────────────────────────────────────────────────── -->
<form method="GET" action="/office/warranty_cos.php" class="fia-search-bar mb-3 row g-2 align-items-end">
    <div class="col-6 col-md-4">
        <label class="form-label mb-1 fw-semibold" style="font-size:.8rem;">Company Name</label>
        <input type="text" name="name" class="form-control form-control-sm"
               value="<?= h($search_name) ?>" placeholder="Contains…">
    </div>
    <div class="col-4 col-md-2">
        <label class="form-label mb-1 fw-semibold" style="font-size:.8rem;">State</label>
        <input type="text" name="state" class="form-control form-control-sm"
               value="<?= h($search_state) ?>" placeholder="FL" maxlength="2">
    </div>
    <div class="col-4 col-md-2">
        <label class="form-label mb-1 fw-semibold" style="font-size:.8rem;">Status</label>
        <select name="status" class="form-select form-select-sm">
            <option value="active"   <?= $filter_status === 'active'   ? 'selected' : '' ?>>Active</option>
            <option value="archived" <?= $filter_status === 'archived' ? 'selected' : '' ?>>Archived</option>
            <option value="all"      <?= $filter_status === 'all'      ? 'selected' : '' ?>>All</option>
        </select>
    </div>
    <div class="col-4 col-md-2 d-flex gap-2 align-items-end">
        <button type="submit" class="btn btn-fia btn-sm flex-grow-1">
            <i class="bi bi-search"></i> Search
        </button>
        <?php if ($is_search || $filter_status !== 'active'): ?>
        <a href="/office/warranty_cos.php" class="btn btn-outline-secondary btn-sm">Clear</a>
        <?php endif; ?>
    </div>
    <div class="col-12 col-md-4 text-muted text-end" style="font-size:.8rem;">
        <?= $total_rows ?> record<?= $total_rows !== 1 ? 's' : '' ?>
        <?php if ($total_pages > 1): ?>
        &mdash; p.<?= $current_page ?>/<?= $total_pages ?>
        <?php endif; ?>
    </div>
</form>

<!-- ── Table ────────────────────────────────────────────────────────────── -->
<div class="fia-card">
    <div class="fia-page-header d-flex justify-content-between align-items-center">
        <span>Warranty Companies</span>
        <span style="font-size:.8rem; font-weight:400;"><?= $total_rows ?> record<?= $total_rows !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($rows)): ?>
    <div class="fia-card-body text-center text-muted py-4">
        <i class="bi bi-building fs-3 d-block mb-2"></i>
        No warranty companies found.
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="fia-table">
            <thead>
                <tr>
                    <th class="text-start">Company</th>
                    <th>City / State</th>
                    <th>FIA Phone</th>
                    <th>Supervisor</th>
                    <th class="text-end">National</th>
                    <th class="text-end">Florida</th>
                    <th class="text-end">Canada</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td class="text-start fw-semibold">
                    <a href="/office/warranty_co.php?id=<?= (int)$row['warranty_co_id'] ?>">
                        <?= h($row['company_name'] ?? '—') ?>
                    </a>
                    <?php if ($row['is_archived']): ?>
                    <span class="badge bg-dark ms-1" style="font-size:.7rem;">Archived</span>
                    <?php endif; ?>
                </td>
                <td><?= h(implode(', ', array_filter([$row['city'] ?? '', $row['state_code'] ?? ''])) ?: '—') ?></td>
                <td style="white-space:nowrap;"><?= h($row['fia_phone'] ?? '—') ?></td>
                <td style="font-size:.78rem;">
                    <?= h($row['supervisor_name'] ?? '—') ?>
                    <?php if ($row['supervisor_email']): ?>
                    <br><a href="mailto:<?= h($row['supervisor_email']) ?>" style="font-size:.75rem;"><?= h($row['supervisor_email']) ?></a>
                    <?php endif; ?>
                </td>
                <td class="text-end"><?= $row['rate_base_national'] ? '$' . number_format((float)$row['rate_base_national'], 2) : '—' ?></td>
                <td class="text-end"><?= $row['rate_base_florida']  ? '$' . number_format((float)$row['rate_base_florida'],  2) : '—' ?></td>
                <td class="text-end"><?= h($row['rate_base_canada'] ?? '—') ?></td>
                <td>
                    <a href="/office/warranty_co.php?id=<?= (int)$row['warranty_co_id'] ?>"
                       class="btn btn-outline-secondary btn-sm py-0 px-2">Edit</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="fia-card-body d-flex justify-content-center">
        <nav aria-label="Warranty company pagination">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= page_url($current_page - 1) ?>">&lsaquo;</a>
                </li>
                <?php
                $ws = max(1, $current_page - 3);
                $we = min($total_pages, $current_page + 3);
                if ($ws > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?= page_url(1) ?>">1</a></li>
                    <?php if ($ws > 2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
                <?php endif; ?>
                <?php for ($p = $ws; $p <= $we; $p++): ?>
                <li class="page-item <?= $p === $current_page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= page_url($p) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <?php if ($we < $total_pages): ?>
                    <?php if ($we < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
