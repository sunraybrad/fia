<?php
// =============================================================================
// qbo/callback.php
// OAuth 2.0 callback handler — Intuit redirects here after the user authorizes.
// Exchanges the auth code for tokens and stores them in qbo_tokens.
// =============================================================================

require_once 'C:/inetpub/fia_private/config.php';
require_once 'C:/inetpub/fia_private/db.php';
init_session();

require_once dirname(__DIR__) . '/vendor/autoload.php';

use QuickBooksOnline\API\DataService\DataService;

// ---------------------------------------------------------------------------
// 1. Validate the callback parameters
// ---------------------------------------------------------------------------

// Intuit sends 'error' if the user denied access
if (!empty($_GET['error'])) {
    _fail('Authorization denied: ' . htmlspecialchars($_GET['error']));
}

if (empty($_GET['code']) || empty($_GET['realmId'])) {
    _fail('Missing authorization code or realmId.');
}

// Validate CSRF state before exchanging the code
$returned_state = $_GET['state'] ?? '';
$expected_state = $_SESSION['qbo_oauth_state'] ?? '';
if (empty($returned_state) || !hash_equals($expected_state, $returned_state)) {
    _fail('OAuth state mismatch — possible CSRF. Please try connecting again.');
}
unset($_SESSION['qbo_oauth_state']);

$auth_code = $_GET['code'];
$realm_id  = $_GET['realmId'];

// ---------------------------------------------------------------------------
// 2. Exchange auth code for tokens
// ---------------------------------------------------------------------------

$dataService = DataService::Configure([
    'auth_mode'     => 'oauth2',
    'ClientID'      => QBO_CLIENT_ID,
    'ClientSecret'  => QBO_CLIENT_SECRET,
    'RedirectURI'   => QBO_REDIRECT_URI,
    'scope'         => QBO_SCOPE,
    'baseUrl'       => QBO_BASE_URL,
]);

$oauth2LoginHelper = $dataService->getOAuth2LoginHelper();

try {
    $accessToken = $oauth2LoginHelper->exchangeAuthorizationCodeForToken($auth_code, $realm_id);
} catch (Throwable $e) {
    error_log('QBO token exchange failed: ' . $e->getMessage());
    _fail('Token exchange failed. Check the error log.');
}

if (!$accessToken || !$accessToken->getAccessToken()) {
    _fail('Intuit returned an empty token. Check credentials and redirect URI.');
}

// ---------------------------------------------------------------------------
// 3. Store tokens in qbo_tokens (upsert — one row per realm_id)
// ---------------------------------------------------------------------------

$db  = get_db();
$env = QBO_BASE_URL === 'development' ? 'sandbox' : 'production';

// getAccessTokenExpiresAt() returns an absolute date string ('Y/m/d H:i:s') — reformat for MySQL
$access_expires  = date('Y-m-d H:i:s', strtotime($accessToken->getAccessTokenExpiresAt()));
$refresh_expires = date('Y-m-d H:i:s', strtotime($accessToken->getRefreshTokenExpiresAt()));

$stmt = $db->prepare('
    INSERT INTO qbo_tokens
        (realm_id, access_token, refresh_token, access_token_expires, refresh_token_expires, environment)
    VALUES
        (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        access_token          = VALUES(access_token),
        refresh_token         = VALUES(refresh_token),
        access_token_expires  = VALUES(access_token_expires),
        refresh_token_expires = VALUES(refresh_token_expires),
        environment           = VALUES(environment)
');

$at = $accessToken->getAccessToken();
$rt = $accessToken->getRefreshToken();

$stmt->bind_param('ssssss',
    $realm_id,
    $at,
    $rt,
    $access_expires,
    $refresh_expires,
    $env
);

if (!$stmt->execute()) {
    error_log('QBO token save failed: ' . $stmt->error);
    _fail('Could not save tokens to database.');
}
$stmt->close();

log_audit('qbo.connect', 'qbo_token', null, [
    'realm_id'    => $realm_id,
    'environment' => $env,
]);

// ---------------------------------------------------------------------------
// 4. Redirect to status page
// ---------------------------------------------------------------------------

header('Location: ' . SITE_URL . '/qbo/connect.php');
exit;

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

function _fail(string $message): never {
    error_log('QBO callback error: ' . $message);
    http_response_code(400);
    echo '<!DOCTYPE html><html><body>';
    echo '<p style="color:red;font-family:sans-serif;padding:2rem;">' . htmlspecialchars($message) . '</p>';
    echo '<p><a href="' . SITE_URL . '/qbo/connect.php">Try again</a></p>';
    echo '</body></html>';
    exit;
}
