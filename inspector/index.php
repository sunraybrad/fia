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

function category_badge(string $cat): string {
    if ($cat === '') return '';
    $upper = strtoupper($cat);
    if (str_contains($upper, 'URGENT'))    $cls = 'bg-danger';
    elseif (str_contains($upper, 'IMPORTANT')) $cls = 'bg-warning text-dark';
    else                                        $cls = 'bg-secondary';
    return '<span class="badge ' . $cls . ' ms-1" style="font-size:.68rem;">' . h($cat) . '</span>';
}

$db          = get_db();
$inspector_id = (int)$_SESSION['inspector_id'];

// ── Messages for inspectors (with read state) ─────────────────────────────

$msgs_stmt = $db->prepare(
    "SELECT m.message_id, m.posted_date, m.category, m.subject, m.message_body,
            (mr.read_id IS NOT NULL) AS is_read
       FROM messages m
       LEFT JOIN message_reads mr
              ON mr.message_id   = m.message_id
             AND mr.inspector_id = ?
      WHERE m.audience    IN ('Inspectors', 'Both')
        AND m.is_archived = FALSE
      ORDER BY is_read ASC, m.posted_date DESC
      LIMIT 20"
);
$msgs_stmt->bind_param('i', $inspector_id);
if (!$msgs_stmt->execute()) {
    error_log('Query failed [index.php/messages]: ' . $db->error);
    $messages = [];
} else {
    $messages = $msgs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$msgs_stmt->close();

$unread_count = count(array_filter($messages, fn($m) => !$m['is_read']));

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

// When a status has exactly 1 record, grab the fia_number for direct linking
$single = ['Assigned' => null, 'Complete' => null];
foreach (['Assigned', 'Complete'] as $s) {
    if ($counts[$s] === 1) {
        $s_stmt = $db->prepare(
            "SELECT fia_number FROM inspections
              WHERE inspector_id = ? AND is_archived = FALSE AND status = ?
              LIMIT 1"
        );
        $s_stmt->bind_param('is', $inspector_id, $s);
        $s_stmt->execute();
        $single[$s] = $s_stmt->get_result()->fetch_row()[0] ?? null;
        $s_stmt->close();
    }
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

<!-- ── Welcome bar ───────────────────────────────────────────────────────── -->
<div class="insp-card mb-3">
    <div class="insp-card-body d-flex justify-content-between align-items-center py-2">
        <div>
            <span class="fw-bold"><?= h($_SESSION['inspector_name']) ?></span>
            <span class="text-muted ms-2" style="font-size:.82rem;"><?= date('l, F j, Y') ?></span>
        </div>
        <a href="/inspector/jobs.php?status=" class="btn btn-fia btn-sm">
            <i class="bi bi-clipboard-check"></i> View All Jobs
        </a>
    </div>
</div>

<!-- ── Job count pills ───────────────────────────────────────────────────── -->
<div class="d-flex gap-2 mb-3 flex-wrap">

    <a href="<?= $single['Assigned'] ? '/inspector/job.php?fia=' . (int)$single['Assigned'] : '/inspector/jobs.php?status=Assigned' ?>"
       class="text-decoration-none">
        <div class="dash-stat-pill">
            <span class="dash-stat-num" style="color:var(--fia-blue);"><?= $counts['Assigned'] ?></span>
            <span class="dash-stat-label">Assigned</span>
        </div>
    </a>

    <a href="<?= $single['Complete'] ? '/inspector/job.php?fia=' . (int)$single['Complete'] : '/inspector/jobs.php?status=Complete' ?>"
       class="text-decoration-none">
        <div class="dash-stat-pill">
            <span class="dash-stat-num" style="color:#d97706;"><?= $counts['Complete'] ?></span>
            <span class="dash-stat-label">Complete / pending billing</span>
        </div>
    </a>

</div>

<!-- ── Messages ──────────────────────────────────────────────────────────── -->
<?php if ($unread_count > 0): ?>
<!-- Unread messages: panel open, prominent -->
<div class="insp-card mb-3" id="msg-panel">
    <div class="insp-card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="bi bi-megaphone-fill text-danger me-1"></i>
            Messages from FIA
            <span class="badge bg-danger ms-1" id="unread-badge"><?= $unread_count ?></span>
        </span>
        <button type="button" class="btn btn-link btn-sm text-muted p-0 text-decoration-none"
                id="mark-all-btn" style="font-size:.8rem;">
            Mark all read
        </button>
    </div>
    <div class="insp-card-body p-0" id="msg-list">
        <?php foreach ($messages as $msg):
            $is_read = (bool)$msg['is_read'];
            $msg_id  = (int)$msg['message_id'];
        ?>
        <div class="msg-item <?= $is_read ? 'msg-read' : 'msg-unread' ?>"
             id="msg-<?= $msg_id ?>" data-id="<?= $msg_id ?>">

            <?php if ($is_read): ?>
            <div class="msg-summary d-flex justify-content-between align-items-center"
                 role="button" tabindex="0"
                 onclick="toggleMsg(<?= $msg_id ?>)"
                 onkeydown="if(event.key==='Enter'||event.key===' ')toggleMsg(<?= $msg_id ?>)">
                <span class="msg-summary-subject">
                    <i class="bi bi-chevron-right msg-chevron" id="chev-<?= $msg_id ?>"></i>
                    <?= h($msg['subject'] ?? '(no subject)') ?>
                </span>
                <span class="msg-summary-meta">
                    <?= $msg['posted_date'] ? date('m/d/Y', strtotime($msg['posted_date'])) : '' ?>
                    <?= $msg['category'] ? category_badge($msg['category']) : '' ?>
                </span>
            </div>
            <div class="msg-body-collapse" id="body-<?= $msg_id ?>" style="display:none;">
                <?php if ($msg['message_body']): ?>
                <div class="msg-body-text"><?= h($msg['message_body']) ?></div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <div class="msg-full">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="msg-subject-unread"><?= h($msg['subject'] ?? '(no subject)') ?></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary msg-mark-btn"
                            style="font-size:.72rem; white-space:nowrap;"
                            onclick="markRead(<?= $msg_id ?>)">
                        <i class="bi bi-check2"></i> Mark read
                    </button>
                </div>
                <div class="msg-date">
                    <?= $msg['posted_date'] ? date('m/d/Y', strtotime($msg['posted_date'])) : '' ?>
                    <?= $msg['category'] ? category_badge($msg['category']) : '' ?>
                </div>
                <?php if ($msg['message_body']): ?>
                <div class="msg-body-text mt-2"><?= h($msg['message_body']) ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php else: ?>
<!-- All read (or no messages): single slim line -->
<div class="msg-quiet-bar mb-3" id="msg-panel">
    <i class="bi bi-megaphone me-1"></i>
    <span class="msg-quiet-label">Messages from FIA</span>
    <?php if (!empty($messages)): ?>
    — <span class="text-muted">no new messages</span>
    <button type="button" class="msg-quiet-toggle ms-2"
            onclick="document.getElementById('msg-quiet-list').hidden ^= 1;
                     this.textContent = document.getElementById('msg-quiet-list').hidden ? 'show history' : 'hide';">
        show history
    </button>
    <div id="msg-quiet-list" hidden>
        <div class="insp-card mt-2" style="font-size:.83rem;">
            <div class="insp-card-body p-0">
            <?php foreach ($messages as $msg):
                $msg_id = (int)$msg['message_id'];
            ?>
            <div class="msg-item msg-read" id="msg-<?= $msg_id ?>" data-id="<?= $msg_id ?>">
                <div class="msg-summary d-flex justify-content-between align-items-center"
                     role="button" tabindex="0"
                     onclick="toggleMsg(<?= $msg_id ?>)"
                     onkeydown="if(event.key==='Enter'||event.key===' ')toggleMsg(<?= $msg_id ?>)">
                    <span class="msg-summary-subject">
                        <i class="bi bi-chevron-right msg-chevron" id="chev-<?= $msg_id ?>"></i>
                        <?= h($msg['subject'] ?? '(no subject)') ?>
                    </span>
                    <span class="msg-summary-meta">
                        <?= $msg['posted_date'] ? date('m/d/Y', strtotime($msg['posted_date'])) : '' ?>
                    </span>
                </div>
                <div class="msg-body-collapse" id="body-<?= $msg_id ?>" style="display:none;">
                    <?php if ($msg['message_body']): ?>
                    <div class="msg-body-text"><?= h($msg['message_body']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php else: ?>
    — <span class="text-muted">no messages at this time</span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Recent jobs ───────────────────────────────────────────────────────── -->
<?php if (!empty($recent_jobs)): ?>
<div class="insp-card">
    <div class="insp-card-header d-flex justify-content-between align-items-center">
        <span>Recent Jobs</span>
        <a href="/inspector/jobs.php?status=" class="btn btn-sm btn-outline-light py-0">View All</a>
    </div>
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
            <?php foreach ($recent_jobs as $j): ?>
            <tr>
                <td data-label="FIA #">
                    <a href="/inspector/job.php?fia=<?= (int)$j['fia_number'] ?>" class="fw-semibold">
                        #<?= (int)$j['fia_number'] ?>
                    </a>
                </td>
                <td data-label="Status">
                    <span class="badge bg-<?= $j['status'] === 'Assigned' ? 'primary' : 'warning text-dark' ?>">
                        <?= h($j['status']) ?>
                    </span>
                </td>
                <td data-label="Type" style="font-size:.78rem;"><?= h($j['inspection_type'] ?? '—') ?></td>
                <td data-label="Warranty Co" style="font-size:.78rem;"><?= h($j['warranty_co'] ?? '—') ?></td>
                <td data-label="Vehicle">
                    <?= h(trim(($j['year'] ?? '') . ' ' . ($j['make'] ?? '') . ' ' . ($j['model'] ?? '')) ?: '—') ?>
                </td>
                <td data-label="Location" style="font-size:.78rem;">
                    <?= h($j['repair_shop'] ?? '') ?><br>
                    <span class="text-muted"><?= h(implode(', ', array_filter([$j['city'] ?? '', $j['state_code'] ?? '']))) ?></span>
                    <?php
                    $map_q = implode(', ', array_filter([$j['repair_shop'] ?? '', $j['city'] ?? '', $j['state_code'] ?? '']));
                    if ($map_q): ?>
                    <a href="https://maps.google.com/?q=<?= urlencode($map_q) ?>" target="_blank" rel="noopener"
                       class="text-muted" title="Open in Google Maps" style="font-size:.75rem;">
                        <i class="bi bi-geo-alt"></i>
                    </a>
                    <?php endif; ?>
                </td>
                <td data-label="Assigned" style="white-space:nowrap;">
                    <?= $j['date_assigned'] ? date('m/d/Y', strtotime($j['date_assigned'])) : '—' ?>
                </td>
                <td data-label="ETA" style="white-space:nowrap;">
                    <?= ($j['eta'] && $j['eta'] !== '0000-00-00') ? date('m/d/Y', strtotime($j['eta'])) : '—' ?>
                </td>
                <td class="text-end" style="white-space:nowrap;">
                    <a href="/inspector/job.php?fia=<?= (int)$j['fia_number'] ?>"
                       class="btn btn-fia btn-sm py-0 px-2 me-1">
                        Open
                    </a>
                    <a href="/inspector/generate_worksheet.php?fia=<?= (int)$j['fia_number'] ?>"
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

<style>
/* ── Compact stat pills ──────────────────────────────────────────────────── */
.dash-stat-pill {
    display: flex;
    align-items: center;
    gap: .6rem;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: .5rem;
    padding: .45rem .9rem;
    box-shadow: 0 1px 2px rgba(0,0,0,.06);
    transition: box-shadow .15s;
}
.dash-stat-pill:hover { box-shadow: 0 2px 6px rgba(0,0,0,.12); }
.dash-stat-num   { font-size: 1.6rem; font-weight: 700; line-height: 1; }
.dash-stat-label { font-size: .78rem; color: #6c757d; }

/* ── Quiet message bar (all-read state) ─────────────────────────────────── */
.msg-quiet-bar {
    font-size: .82rem;
    color: #6c757d;
    padding: .4rem .2rem;
}
.msg-quiet-label { font-weight: 600; color: #495057; }
.msg-quiet-toggle {
    background: none;
    border: none;
    color: var(--fia-blue, #0d6efd);
    font-size: .8rem;
    padding: 0;
    cursor: pointer;
    text-decoration: underline;
}

/* ── Message item styles ─────────────────────────────────────────────────── */
.msg-item {
    border-bottom: 1px solid var(--bs-border-color, #dee2e6);
    transition: background .15s;
}
.msg-item:last-child { border-bottom: none; }

.msg-unread {
    background: #eef4ff;
    border-left: 4px solid var(--fia-blue, #0d6efd);
}
.msg-read {
    background: transparent;
    opacity: .65;
    border-left: 4px solid transparent;
}

.msg-summary {
    padding: .5rem 1rem;
    cursor: pointer;
    user-select: none;
}
.msg-summary:hover { background: #f8f9fa; opacity: 1; }
.msg-summary-subject { font-size: .83rem; font-weight: 500; color: #495057; }
.msg-summary-meta    { font-size: .75rem; color: #6c757d; flex-shrink: 0; margin-left: .75rem; }

.msg-chevron { font-size: .7rem; transition: transform .15s; margin-right: .3rem; }
.msg-chevron.open { transform: rotate(90deg); }

.msg-body-text     { font-size: .83rem; white-space: pre-line; line-height: 1.5; }
.msg-body-collapse { padding: .4rem 1rem .75rem 2rem; }

.msg-full           { padding: .85rem 1rem .85rem 1.25rem; }
.msg-subject-unread { font-weight: 700; font-size: .92rem; color: #1a2b4a; margin-bottom: .2rem; }
.msg-date           { font-size: .75rem; color: #6c757d; margin-bottom: .1rem; }
</style>

<script>
function toggleMsg(id) {
    const body = document.getElementById('body-' + id);
    const chev = document.getElementById('chev-' + id);
    const open = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    chev.classList.toggle('open', !open);
}

function markRead(id) {
    fetch('/inspector/mark_message_read.php', {
        method:  'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body:    'message_id=' + id
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) return;
        const el = document.getElementById('msg-' + id);
        if (!el) return;

        el.classList.replace('msg-unread', 'msg-read');

        const subject = el.querySelector('.msg-subject-unread')?.textContent?.trim() ?? '';
        const meta    = el.querySelector('.msg-date')?.innerHTML ?? '';
        const bodyTxt = el.querySelector('.msg-body-text')?.innerHTML ?? '';

        el.innerHTML = `
            <div class="msg-summary d-flex justify-content-between align-items-center"
                 role="button" tabindex="0"
                 onclick="toggleMsg(${id})"
                 onkeydown="if(event.key==='Enter'||event.key===' ')toggleMsg(${id})">
                <span class="msg-summary-subject">
                    <i class="bi bi-chevron-right msg-chevron" id="chev-${id}"></i>
                    ${escHtml(subject)}
                </span>
                <span class="msg-summary-meta">${meta}</span>
            </div>
            <div class="msg-body-collapse" id="body-${id}" style="display:none;">
                <div class="msg-body-text">${bodyTxt}</div>
            </div>`;

        const list = document.getElementById('msg-list');
        if (list) list.appendChild(el);

        updateBadge(-1);
    })
    .catch(() => {});
}

document.getElementById('mark-all-btn')?.addEventListener('click', function() {
    document.querySelectorAll('.msg-unread[data-id]')
            .forEach(el => markRead(parseInt(el.dataset.id)));
    this.style.display = 'none';
});

function updateBadge(delta) {
    const badge = document.getElementById('unread-badge');
    if (!badge) return;
    const n = Math.max(0, parseInt(badge.textContent) + delta);
    badge.textContent = n;
    if (n > 0) return;
    // All read — swap the full panel for the quiet bar without a page reload
    const panel = document.getElementById('msg-panel');
    if (panel) {
        panel.outerHTML = `<div class="msg-quiet-bar mb-3">
            <i class="bi bi-megaphone me-1"></i>
            <span class="msg-quiet-label">Messages from FIA</span>
            — <span class="text-muted">no new messages</span>
        </div>`;
    }
    document.getElementById('mark-all-btn')?.remove();
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
