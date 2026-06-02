<?php
/**
 * set_tab.php — AJAX tab persistence for warco inspection detail
 */
require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';
init_session();
require_warco();
verify_csrf();

$fia  = (int)($_POST['fia']  ?? 0);
$tab  = trim($_POST['tab']   ?? '');
$valid = ['vehicle','findings1','findings2','tire','photos'];

if ($fia && in_array($tab, $valid, true)) {
    $_SESSION['warco_tab_' . $fia] = $tab;
}
http_response_code(204);
