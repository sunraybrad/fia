<?php
/**
 * message.php — Add / edit a message
 *
 * GET  /office/message.php         → new message form
 * GET  /office/message.php?id=N    → edit existing message
 * POST /office/message.php         → save (insert or update)
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_office();

$db = get_db();

$errors    = [];
$msg_id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit   = $msg_id > 0;

// ── Load existing record for edit ─────────────────────────────────────────

$record = [
    'message_id'   => 0,
    'posted_date'  => date('Y-m-d'),
    'audience'     => 'Inspectors',
    'category'     => '',
    'subject'      => '',
    'message_body' => '',
    'is_archived'  => 0,
];

if ($is_edit) {
    $s = $db->prepare("SELECT * FROM messages WHERE message_id = ?");
    $s->bind_param('i', $msg_id);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$row) {
        header('Location: /office/messages.php');
        exit;
    }
    $record = $row;
}

// ── Handle POST ───────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $posted_date  = trim($_POST['posted_date']  ?? '');
    $audience     = trim($_POST['audience']     ?? '');
    $category     = trim($_POST['category']     ?? '');
    $subject      = trim($_POST['subject']      ?? '');
    $message_body = trim($_POST['message_body'] ?? '');

    // Validation
    if ($subject === '')   $errors[] = 'Subject is required.';
    if ($audience === '')  $errors[] = 'Audience is required.';
    if (!in_array($audience, ['Inspectors', 'Clients', 'Both'], true))
        $errors[] = 'Invalid audience.';
    $valid_categories = ['', 'URGENT..MUST READ', 'URGENT...HIGH IMPORTANCE..READ', 'IMPORTANT UPDATE', 'FIA Workflow', 'FIA ADMIN', 'Tech Note', 'General News'];
    if (!in_array($category, $valid_categories, true))
        $errors[] = 'Invalid category.';

    $date_val = $posted_date !== '' ? $posted_date : null;

    if (empty($errors)) {
        if ($is_edit) {
            $upd = $db->prepare(
                "UPDATE messages
                    SET posted_date  = ?,
                        audience     = ?,
                        category     = ?,
                        subject      = ?,
                        message_body = ?
                  WHERE message_id   = ?"
            );
            $upd->bind_param('sssssi',
                $date_val, $audience, $category, $subject, $message_body,
                $msg_id
            );
            $upd->execute();
            $upd->close();
            log_audit('message.save', 'message', $msg_id, ['subject' => $subject]);
        } else {
            $ins = $db->prepare(
                "INSERT INTO messages (posted_date, audience, category, subject, message_body)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $ins->bind_param('sssss',
                $date_val, $audience, $category, $subject, $message_body
            );
            $ins->execute();
            $msg_id = (int)$db->insert_id;
            $ins->close();
            log_audit('message.create', 'message', $msg_id, ['subject' => $subject]);
        }
        header('Location: /office/messages.php?saved=1');
        exit;
    }

    // Re-populate form from POST on validation error
    $record['posted_date']  = $posted_date;
    $record['audience']     = $audience;
    $record['category']     = $category;
    $record['subject']      = $subject;
    $record['message_body'] = $message_body;
}

// ── Page output ───────────────────────────────────────────────────────────

$page_title = $is_edit ? 'Edit Message' : 'New Message';
$active_nav = 'messages';
$flash = null;
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-3">
    <a href="/office/messages.php" class="text-muted text-decoration-none" style="font-size:.85rem;">
        <i class="bi bi-arrow-left"></i> Back to Messages
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?>
    <div><?= h($e) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="fia-card" style="max-width:700px;">
    <div class="fia-page-header"><?= $is_edit ? 'Edit Message' : 'New Message' ?></div>
    <div class="fia-card-body">

        <form method="POST" action="/office/message.php<?= $is_edit ? '?id=' . $msg_id : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Date Posted</label>
                    <input type="date" name="posted_date" class="form-control"
                           value="<?= h($record['posted_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Audience <span class="text-danger">*</span></label>
                    <select name="audience" class="form-select" required>
                        <?php foreach (['Inspectors', 'Clients', 'Both'] as $opt): ?>
                        <option value="<?= $opt ?>" <?= ($record['audience'] ?? '') === $opt ? 'selected' : '' ?>>
                            <?= $opt ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Category</label>
                    <select name="category" class="form-select">
                        <option value="">— none —</option>
                        <?php foreach ([
                            'URGENT..MUST READ',
                            'URGENT...HIGH IMPORTANCE..READ',
                            'IMPORTANT UPDATE',
                            'FIA Workflow',
                            'FIA ADMIN',
                            'Tech Note',
                            'General News',
                        ] as $cat): ?>
                        <option value="<?= h($cat) ?>"
                            <?= ($record['category'] ?? '') === $cat ? 'selected' : '' ?>>
                            <?= h($cat) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label>
                <input type="text" name="subject" class="form-control"
                       value="<?= h($record['subject'] ?? '') ?>"
                       placeholder="Brief subject line" required maxlength="255">
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold">Message Body</label>
                <textarea name="message_body" class="form-control" rows="8"
                          placeholder="Full message text…"><?= h($record['message_body'] ?? '') ?></textarea>
                <div class="form-text">Plain text. Line breaks are preserved.</div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-fia">
                    <i class="bi bi-check-lg"></i> <?= $is_edit ? 'Save Changes' : 'Create Message' ?>
                </button>
                <a href="/office/messages.php" class="btn btn-outline-secondary">Cancel</a>
            </div>

        </form>

    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
