<?php
// =============================================================================
// qbo/connect.php
// "Connect to QuickBooks Online" page — admin only.
// Generates the Intuit authorization URL and redirects the user to it.
// =============================================================================

require_once 'C:/inetpub/fia_private/config.php';
require_once 'C:/inetpub/fia_private/db.php';
init_session();

require_admin();          // defined in config.php — redirects to /admin/login.php if not authed
verify_admin_session();

require_once dirname(__DIR__) . '/vendor/autoload.php';

use QuickBooksOnline\API\DataService\DataService;

// Check if we already have a valid token for this environment
$db   = get_db();
$env  = QBO_BASE_URL === 'development' ? 'sandbox' : 'production';
$stmt = $db->prepare(
    'SELECT realm_id, access_token_expires, refresh_token_expires
     FROM qbo_tokens
     WHERE environment = ?
     ORDER BY updated_at DESC LIMIT 1'
);
$stmt->bind_param('s', $env);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$already_connected = false;
$realm_id          = null;
if ($row) {
    $refresh_expires   = new DateTime($row['refresh_token_expires']);
    $already_connected = $refresh_expires > new DateTime();
    $realm_id          = $row['realm_id'];
}

// If a reconnect was requested, fall through to the auth flow regardless
$reconnect = isset($_GET['reconnect']);

if ($already_connected && !$reconnect) {
    $status_message = 'QuickBooks Online is connected (Company ID: ' . h($realm_id) . ').';
} else {
    // Build the authorization URL
    $dataService = DataService::Configure([
        'auth_mode'     => 'oauth2',
        'ClientID'      => QBO_CLIENT_ID,
        'ClientSecret'  => QBO_CLIENT_SECRET,
        'RedirectURI'   => QBO_REDIRECT_URI,
        'scope'         => QBO_SCOPE,
        'baseUrl'       => QBO_BASE_URL,
    ]);

    $oauth2LoginHelper = $dataService->getOAuth2LoginHelper();
    $authUrl           = $oauth2LoginHelper->getAuthorizationCodeURL();

    // Store the state token that Intuit echoes back in the callback for CSRF validation
    parse_str(parse_url($authUrl, PHP_URL_QUERY), $auth_params);
    $_SESSION['qbo_oauth_state'] = $auth_params['state'] ?? '';

    // DEBUG — remove after confirming redirect_uri is correct
    // die('<pre>' . htmlspecialchars($authUrl) . '</pre>');

    header('Location: ' . $authUrl);
    exit;
}

$page_title = 'QuickBooks Online — Connection Status';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($page_title) ?></title>
    <link rel="stylesheet" href="<?= SITE_URL ?>/bootstrap/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:600px;">
    <h4 class="mb-4">QuickBooks Online</h4>

    <?php if ($already_connected): ?>
        <div class="alert alert-success"><?= h($status_message) ?></div>
        <p class="text-muted small">The refresh token is valid. API calls will work.</p>
        <a href="?reconnect=1" class="btn btn-outline-secondary btn-sm">Re-authorize</a>
    <?php endif; ?>
</div>
</body>
</html>
