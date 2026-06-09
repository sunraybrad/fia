<?php
/**
 * messages.php — Office message management
 *
 * Lists all messages (active + archived) with inline read-count badges.
 * Provides links to add, edit, and archive/restore each message.
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

$db = get_db();

function category_badge(string $cat): string {
    if ($cat === '') return '';
    $upper = strtoupper($cat);
    if (str_contains($upper, 'URGENT'))         $cls = 'bg-danger';
    elseif (str_contains($upper, 'IMPORTANT'))  $cls = 'bg-warning text-dark';
    else                                         $cls = 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . h($cat) . '</span>';
}

// ── Archive / restore toggle ──────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_archive'])) {
    verify_csrf();
    $msg_id    = (int)$_POST['message_id'];
    $archive   = (int)$_POST['archive']; // 1 = archive, 0 = restore
    $stmt = $db->prepare("UPDATE messages SET is_archived = ? WHERE message_id = ?");
    $stmt->bind_param('ii', $archive, $msg_id);
    $stmt->execute();
    $stmt->close();
    log_audit($archive ? 'message.archive' : 'message.restore', 'message', $msg_id);
    header('Location: /office/messages.php' . ($archive ? '?deleted=1' : '?restored=1&tab=active'));
    exit;
}

// ── Filter ────────────────────────────────────────────────────────────────

$show_archived = (($_GET['tab'] ?? 'active') === 'archived');

// ── Fetch messages with read counts ──────────────────────────────────────

$sql = "SELECT m.message_id,
               m.posted_date,
               m.audience,
               m.category,
               m.subject,
               m.message_body,
               m.is_archived,
               COUNT(mr.read_id) AS read_count
          FROM messages m
          LEFT JOIN message_reads mr ON mr.message_id = m.message_id
         WHERE m.is_archived = ?
         GROUP BY m.message_id
         ORDER BY m.posted_date DESC, m.message_id DESC";

$stmt = $db->prepare($sql);
$archived_flag = $show_archived ? 1 : 0;
$stmt->bind_param('i', $archived_flag);
$stmt->execute();
$messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Flash ─────────────────────────────────────────────────────────────────

$flash = null;
if (isset($_GET['saved']))    $flash = ['type' => 'success', 'msg' => 'Message saved.'];
if (isset($_GET['deleted']))  $flash = ['type' => 'success', 'msg' => 'Message archived.'];
if (isset($_GET['restored'])) $flash = ['type' => 'success', 'msg' => 'Message restored.'];

$page_title = 'Messages';
$active_nav = 'messages';
require_once __DIR__ . '/includes/header.php';
?>

<!-- ── Tab bar ──────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= !$show_archived ? 'active' : '' ?>" href="/office/messages.php?tab=active">
            Active
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $show_archived ? 'active' : '' ?>" href="/office/messages.php?tab=archived">
            Archived
        </a>
    </li>
    <li class="nav-item ms-auto align-self-center">
        <a href="/office/message.php" class="btn btn-fia btn-sm">
            <i class="bi bi-plus-lg"></i> New Message
        </a>
    </li>
</ul>

<?php if (!empty($messages)): ?>
<div class="fia-card">
    <div class="fia-page-header d-flex justify-content-between align-items-center">
        <span><?= $show_archived ? 'Archived' : 'Active' ?> Messages</span>
        <span class="subtext"><?= count($messages) ?> record<?= count($messages) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="table-responsive">
        <table class="fia-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Audience</th>
                    <th>Category</th>
                    <th class="text-start">Subject</th>
                    <th>Reads</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($messages as $msg): ?>
            <tr>
                <td data-label="Date" class="text-nowrap">
                    <?= $msg['posted_date'] ? date('m/d/Y', strtotime($msg['posted_date'])) : '—' ?>
                </td>
                <td data-label="Audience">
                    <?= h($msg['audience'] ?? '—') ?>
                </td>
                <td data-label="Category">
                    <?= $msg['category'] ? category_badge($msg['category']) : '<span class="text-muted">—</span>' ?>
                </td>
                <td data-label="Subject" class="text-start">
                    <a href="/office/message.php?id=<?= (int)$msg['message_id'] ?>" class="fw-semibold">
                        <?= h($msg['subject'] ?? '(no subject)') ?>
                    </a>
                    <?php if ($msg['message_body']): ?>
                    <div class="text-muted" style="font-size:.78rem; overflow:hidden; max-height:2.4em;">
                        <?= h(mb_substr($msg['message_body'], 0, 120)) ?><?= mb_strlen($msg['message_body']) > 120 ? '…' : '' ?>
                    </div>
                    <?php endif; ?>
                </td>
                <td data-label="Reads">
                    <?php if ($msg['audience'] === 'Inspectors'): ?>
                    <span class="badge bg-light text-dark border"
                          title="<?= (int)$msg['read_count'] ?> inspector<?= $msg['read_count'] != 1 ? 's' : '' ?> marked read">
                        <i class="bi bi-eye"></i> <?= (int)$msg['read_count'] ?>
                    </span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td data-label="Actions" class="text-end text-nowrap">
                    <a href="/office/message.php?id=<?= (int)$msg['message_id'] ?>"
                       class="btn btn-outline-secondary btn-sm me-1">
                        <i class="bi bi-pencil"></i> Edit
                    </a>
                    <form method="POST" action="/office/messages.php" class="d-inline"
                          onsubmit="return confirm('<?= $show_archived ? 'Restore this message?' : 'Archive this message? Inspectors will no longer see it.' ?>')">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="message_id" value="<?= (int)$msg['message_id'] ?>">
                        <input type="hidden" name="archive"    value="<?= $show_archived ? '0' : '1' ?>">
                        <button type="submit" name="toggle_archive"
                                class="btn btn-sm <?= $show_archived ? 'btn-outline-success' : 'btn-outline-danger' ?>">
                            <i class="bi bi-<?= $show_archived ? 'arrow-counterclockwise' : 'archive' ?>"></i>
                            <?= $show_archived ? 'Restore' : 'Archive' ?>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<div class="fia-card">
    <div class="fia-card-body text-center text-muted py-4">
        <i class="bi bi-chat-square fs-3 d-block mb-2"></i>
        No <?= $show_archived ? 'archived' : 'active' ?> messages.
        <?php if (!$show_archived): ?>
        <div class="mt-2">
            <a href="/office/message.php" class="btn btn-fia btn-sm">
                <i class="bi bi-plus-lg"></i> Create the first message
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
