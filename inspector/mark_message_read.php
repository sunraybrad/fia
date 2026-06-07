<?php
/**
 * mark_message_read.php — AJAX endpoint
 *
 * POST body: message_id=N
 * Returns: {"ok": true} or {"ok": false, "error": "..."}
 *
 * Inserts a row into message_reads (IGNORE on duplicate = idempotent).
 */

require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();

header('Content-Type: application/json');

// Must be a logged-in inspector
if (!isset($_SESSION['inspector_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$message_id  = (int)($_POST['message_id'] ?? 0);
$inspector_id = (int)$_SESSION['inspector_id'];

if ($message_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid message_id']);
    exit;
}

$db = get_db();

// Verify the message exists and is visible to this inspector
$chk = $db->prepare(
    "SELECT message_id FROM messages
      WHERE message_id = ?
        AND audience IN ('Inspectors', 'Both')
        AND is_archived = FALSE"
);
$chk->bind_param('i', $message_id);
$chk->execute();
$exists = $chk->get_result()->fetch_row();
$chk->close();

if (!$exists) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Message not found']);
    exit;
}

// INSERT IGNORE is idempotent — safe to call multiple times
$ins = $db->prepare(
    "INSERT IGNORE INTO message_reads (message_id, inspector_id) VALUES (?, ?)"
);
$ins->bind_param('ii', $message_id, $inspector_id);
$ins->execute();
$ins->close();

echo json_encode(['ok' => true]);
