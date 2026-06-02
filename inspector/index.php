<?php
/**
 * index.php — Inspector portal dashboard
 * Shows active messages + job summary counts.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_inspector();

$db          = get_db();
$inspector_id = (int)$_SESSION['inspector_id'];

// ── Messages for inspectors ───────────────────────────────────────────────

$msgs_stmt = $db->prepare(
    "SELECT message_id, posted_date, category, subject, message_body
       FROM messages
      WHERE audience = 'Inspectors'
        AND is_archived = FALSE
      ORDER BY posted_date DESC
      LIMIT 10"
);
if (!$msgs_stmt->execute()) {
    error_log('Query failed [index.php/messages]: ' . $db->error);
    $messages = [];
} else {
    $messages = $msgs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$msgs_stmt->close();

// ── Job counts ────────────────────────────────────────────────────────────

$counts_stmt = $db->prepare(
    "SELECT status, COUNT(*) AS cnt
       FROM inspections
      WHERE inspector_id = ?
        AND is_archived  = FALSE
        AND status IN ('Assigned','Complete')
      GROUP BY status"
);
$counts_stmt->bind_param('i', $inspector_id);
if (!$counts_stmt->execute()) {
    error_log('Query failed [index.php/counts]: ' . $db->error);
    $counts_raw = [];
} else {
    $counts_raw = $counts_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$counts_stmt->close();

$counts = ['Assigned' => 0, 'Complete' => 0];
foreach ($counts_raw as $r) {
    $counts[$r['status']] = (int)$r['cnt'];
}

// ── Recent jobs ───────────────────────────────────────────────────────────

$recent_stmt = $db->prepare(
    "SELECT i.fia_number, i.status, i.inspection_type,
            i.year, i.make, i.model,
            i.repair_shop, i.city, i.state_code,
            i.date_assigned, i.eta,
            w.company_name AS warranty_co
       FROM inspections i
       LEFT JOIN warranty_co w ON w.warranty_co_id = i.warranty_co_id
      WHERE i.inspector_id = ?
        AND i.is_archived  = FALSE
        AND i.status IN ('Assigned','Complete')
      ORDER BY i.date_assigned DESC
      LIMIT 5"
);
$recent_stmt->bind_param('i', $inspector_id);
if (!$recent_stmt->execute()) {
    error_log('Query failed [index.php/recent_jobs]: ' . $db->error);
    $recent_jobs = [];
} else {
    $recent_jobs = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$recent_stmt->close();

$page_title = 'Dashboard';
$active_nav = 'dashboard';
$flash = null;
if (isset($_GET['saved']))  $flash = ['type' => 'success', 'msg' => 'Saved successfully.'];
if (isset($_GET['err']))    $flash = ['type' => 'danger',  'msg' => 'An error occurred. Please try again.'];

require_once __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-3">

    <!-- Welcome -->
    <div class="col-12">
        <div class="insp-card">
            <div class="insp-card-body d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0 fw-bold">Welcome, <?= h($_SESSION['inspector_name']) ?></h5>
                    <small class="text-muted"><?= date('l, F j, Y') ?></small>
                </div>
                <a href="/inspector/jobs.php" class="btn btn-fia btn-sm">
                    <i class="bi bi-clipboard-check"></i> View All Jobs
                </a>
            </div>
        </div>
    </div>

    <!-- Job counts -->
    <div class="col-6 col-md-3">
        <div class="insp-card text-center">
            <div class="insp-card-body py-3">
                <div style="font-size:2rem; font-weight:700; color:var(--fia-blue);">
                    <?= $counts['Assigned'] ?>
                </div>
                <div class="text-muted" style="font-size:.82rem;">Assigned</div>
                <a href="/inspector/jobs.php?status=Assigned" class="stretched-link"></a>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="insp-card text-center">
            <div class="insp-card-body py-3">
                <div style="font-size:2rem; font-weight:700; color:#f0ad4e;">
                    <?= $counts['Complete'] ?>
                </div>
                <div class="text-muted" style="font-size:.82rem;">Complete (pending billing)</div>
                <a href="/inspector/jobs.php?status=Complete" class="stretched-link"></a>
            </div>
        </div>
    </div>

    <!-- Recent jobs -->
    <?php if (!empty($recent_jobs)): ?>
    <div class="col-12 col-md-6">
        <div class="insp-card h-100">
            <div class="insp-card-header">Recent Jobs</div>
            <div class="insp-card-body p-0">
                <table class="insp-table">
                    <tbody>
                    <?php foreach ($recent_jobs as $j): ?>
                    <tr>
                        <td data-label="FIA #">
                            <a href="/inspector/job.php?fia=<?= (int)$j['fia_number'] ?>" class="fw-semibold">
                                #<?= (int)$j['fia_number'] ?>
                            </a>
                        </td>
                        <td data-label="Vehicle">
                            <?= h(trim(($j['year'] ?? '') . ' ' . ($j['make'] ?? '') . ' ' . ($j['model'] ?? '')) ?: '—') ?>
                        </td>
                        <td data-label="Status">
                            <span class="badge bg-<?= $j['status'] === 'Assigned' ? 'primary' : 'warning' ?>">
                                <?= h($j['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Messages -->
<?php if (!empty($messages)): ?>
<div class="insp-card">
    <div class="insp-card-header">
        <i class="bi bi-megaphone"></i> Messages from FIA
    </div>
    <div class="insp-card-body">
        <?php foreach ($messages as $msg): ?>
        <div class="msg-card">
            <div class="msg-subject"><?= h($msg['subject'] ?? '') ?></div>
            <div class="msg-date">
                <?= $msg['posted_date'] ? date('m/d/Y', strtotime($msg['posted_date'])) : '' ?>
                <?php if ($msg['category']): ?>
                <span class="badge bg-secondary ms-1" style="font-size:.7rem;"><?= h($msg['category']) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($msg['message_body']): ?>
            <div class="mt-1" style="font-size:.82rem; white-space:pre-line;"><?= h($msg['message_body']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="insp-card">
    <div class="insp-card-body text-muted" style="font-size:.85rem;">
        <i class="bi bi-chat-square"></i> No messages from FIA at this time.
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
