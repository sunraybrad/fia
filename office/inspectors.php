<?php
/**
 * inspectors.php — Inspector roster
 *
 * Lists all inspectors with search and status filter.
 * Default view: Active inspectors.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

$db = get_db();

// ── Filter state ──────────────────────────────────────────────────────────

$valid_statuses = ['Active', 'Inactive', 'Prospective', 'NO', ''];
$search_name    = trim($_GET['name']   ?? '');
$search_city    = trim($_GET['city']   ?? '');
$search_state   = trim(strtoupper($_GET['state'] ?? ''));
$filter_status  = $_GET['status'] ?? 'Active';
if (!in_array($filter_status, $valid_statuses, true)) $filter_status = 'Active';

$is_search = ($search_name !== '' || $search_city !== '' || $search_state !== '');

// ── Pagination ────────────────────────────────────────────────────────────

$per_page     = 40;
$current_page = max(1, (int)($_GET['page'] ?? 1));

// ── Status counts ─────────────────────────────────────────────────────────

$counts_res = $db->query(
    "SELECT status, COUNT(*) AS cnt FROM inspectors WHERE is_archived = FALSE GROUP BY status ORDER BY cnt DESC"
);
$counts = [];
while ($r = $counts_res->fetch_assoc()) {
    $counts[$r['status']] = (int)$r['cnt'];
}

// ── Main query ────────────────────────────────────────────────────────────

$where  = ['i.is_archived = FALSE'];
$params = [];
$types  = '';

if ($filter_status !== '') {
    $where[]  = 'i.status = ?';
    $params[] = $filter_status;
    $types   .= 's';
}
if ($search_name !== '') {
    $where[]  = '(i.full_name LIKE ? OR i.company LIKE ?)';
    $params[] = '%' . $search_name . '%';
    $params[] = '%' . $search_name . '%';
    $types   .= 'ss';
}
if ($search_city !== '') {
    $where[]  = 'i.city LIKE ?';
    $params[] = '%' . $search_city . '%';
    $types   .= 's';
}
if ($search_state !== '') {
    $where[]  = 'i.state_code = ?';
    $params[] = $search_state;
    $types   .= 's';
}

$where_clause = 'WHERE ' . implode(' AND ', $where);
$base_sql = "FROM inspectors i {$where_clause}";

// Total count
$cnt_stmt = $db->prepare("SELECT COUNT(*) AS cnt {$base_sql}");
if ($params) $cnt_stmt->bind_param($types, ...$params);
$cnt_stmt->execute();
$total_rows  = (int)$cnt_stmt->get_result()->fetch_assoc()['cnt'];
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$current_page = min($current_page, $total_pages);
$cnt_stmt->close();

// Rows
$list_params  = $params;
$list_types   = $types . 'ii';
$list_params[] = $per_page;
$list_params[] = ($current_page - 1) * $per_page;

$list_stmt = $db->prepare(
    "SELECT i.inspector_id, i.full_name, i.company,
            i.city, i.state_code, i.zip,
            i.phone_primary, i.phone_cell,
            i.email, i.rating, i.base_fee,
            i.status, i.restrictions
     {$base_sql}
     ORDER BY i.full_name
     LIMIT ? OFFSET ?"
);
$list_stmt->bind_param($list_types, ...$list_params);
$list_stmt->execute();
$rows = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$list_stmt->close();

// Auto-redirect when search returns exactly one result
if ($is_search && count($rows) === 1) {
    header('Location: /office/inspector.php?id=' . (int)$rows[0]['inspector_id']);
    exit;
}

// ── URL helper ────────────────────────────────────────────────────────────

function page_url(int $p): string {
    $q = array_filter([
        'name'     => $_GET['name']   ?? '',
        'city'     => $_GET['city']   ?? '',
        'state'    => $_GET['state']  ?? '',
        'status'   => $_GET['status'] ?? '',

        'page'     => $p > 1 ? $p : null,
    ]);
    return '/office/inspectors.php' . ($q ? '?' . http_build_query($q) : '');
}

// status_badge() is defined globally in config.php

// ── Page output ───────────────────────────────────────────────────────────

$page_title = 'Inspectors';
$active_nav = 'inspectors';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Toolbar ──────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">Inspector Roster</h5>
    <a href="/office/inspector.php?new=1" class="btn btn-fia btn-sm">
        <i class="bi bi-person-plus"></i> New Inspector
    </a>
</div>

<!-- ── Search / filter bar ──────────────────────────────────────────────── -->
<form method="GET" action="/office/inspectors.php" class="fia-search-bar mb-3 row g-2 align-items-end">
    <div class="col-6 col-md-3">
        <label class="form-label mb-1 fw-semibold" style="font-size:.8rem;">Name / Company</label>
        <input type="text" name="name" class="form-control form-control-sm"
               value="<?= h($search_name) ?>" placeholder="Contains…">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label mb-1 fw-semibold" style="font-size:.8rem;">City</label>
        <input type="text" name="city" class="form-control form-control-sm"
               value="<?= h($search_city) ?>" placeholder="Contains…">
    </div>
    <div class="col-4 col-md-1">
        <label class="form-label mb-1 fw-semibold" style="font-size:.8rem;">State</label>
        <input type="text" name="state" class="form-control form-control-sm"
               value="<?= h($search_state) ?>" placeholder="FL" maxlength="2">
    </div>
    <div class="col-4 col-md-2">
        <label class="form-label mb-1 fw-semibold" style="font-size:.8rem;">Status</label>
        <select name="status" class="form-select form-select-sm">
            <option value="" <?= $filter_status === '' ? 'selected' : '' ?>>All Statuses</option>
            <?php foreach (['Active','Inactive','Prospective','NO'] as $s): ?>
            <option value="<?= $s ?>" <?= $filter_status === $s ? 'selected' : '' ?>>
                <?= $s ?> <?= isset($counts[$s]) ? '(' . $counts[$s] . ')' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-4 col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-fia btn-sm flex-grow-1">
            <i class="bi bi-search"></i> Search
        </button>
        <?php if ($is_search || $filter_status !== 'Active'): ?>
        <a href="/office/inspectors.php" class="btn btn-outline-secondary btn-sm">Clear</a>
        <?php endif; ?>
    </div>
    <div class="col-12 col-md-2 text-muted text-end" style="font-size:.8rem;">
        <?= $total_rows ?> record<?= $total_rows !== 1 ? 's' : '' ?>
        <?php if ($total_pages > 1): ?>
        &mdash; p.<?= $current_page ?>/<?= $total_pages ?>
        <?php endif; ?>
    </div>
</form>

<!-- ── Inspector table ──────────────────────────────────────────────────── -->
<div class="fia-card">
    <div class="fia-page-header d-flex justify-content-between align-items-center">
        <span>
            <?= $filter_status !== '' ? h($filter_status) . ' ' : '' ?>Inspectors
        </span>
        <span class="subtext" style="font-size:.8rem; font-weight:400;">
            <?= $total_rows ?> record<?= $total_rows !== 1 ? 's' : '' ?>
        </span>
    </div>

    <?php if (empty($rows)): ?>
    <div class="fia-card-body text-center text-muted py-4">
        <i class="bi bi-people fs-3 d-block mb-2"></i>
        No inspectors found.
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="fia-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Company</th>
                    <th>City / State</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th class="text-center">Rating</th>
                    <th class="text-end">Base Fee</th>
                    <th class="text-center">Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td data-label="Name" class="fw-semibold">
                    <a href="/office/inspector.php?id=<?= (int)$row['inspector_id'] ?>">
                        <?= h($row['full_name'] ?? '—') ?>
                    </a>
                    <?php if ($row['restrictions']): ?>
                    <i class="bi bi-exclamation-triangle-fill text-danger ms-1"
                       title="<?= h($row['restrictions']) ?>"></i>
                    <?php endif; ?>
                </td>
                <td data-label="Company"><?= h($row['company'] ?? '—') ?></td>
                <td data-label="Location">
                    <?= h(implode(', ', array_filter([$row['city'] ?? '', $row['state_code'] ?? ''])) ?: '—') ?>
                </td>
                <td data-label="Phone" style="white-space:nowrap;">
                    <?= h($row['phone_cell'] ?: ($row['phone_primary'] ?? '—')) ?>
                </td>
                <td data-label="Email" style="font-size:.78rem;">
                    <?php if ($row['email']): ?>
                    <a href="mailto:<?= h($row['email']) ?>"><?= h($row['email']) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td data-label="Rating" class="text-center">
                    <?= $row['rating'] ? number_format((float)$row['rating'], 1) : '—' ?>
                </td>
                <td data-label="Base Fee" class="text-end">
                    <?= $row['base_fee'] ? '$' . number_format((float)$row['base_fee'], 2) : '—' ?>
                </td>
                <td data-label="Status" class="text-center">
                    <?= status_badge($row['status'] ?? '', 'inspector') ?>
                </td>
                <td>
                    <a href="/office/inspector.php?id=<?= (int)$row['inspector_id'] ?>"
                       class="btn btn-outline-secondary btn-sm py-0 px-2">
                        Edit
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="fia-card-body d-flex justify-content-center">
        <nav aria-label="Inspector pagination">
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
                    <li class="page-item">
                        <a class="page-link" href="<?= page_url($total_pages) ?>"><?= $total_pages ?></a>
                    </li>
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
