<?php
// =============================================================================
// qbo/qbo_service.php
// Returns a configured, authenticated DataService instance ready to make API
// calls. Automatically refreshes the access token if it is expired.
//
// Usage from any page:
//   require_once __DIR__ . '/../qbo/qbo_service.php';
//   $qbo = get_qbo_service();
//   if (!$qbo) { /* not connected — redirect to /qbo/connect.php */ }
// =============================================================================

require_once 'C:/inetpub/fia_private/config.php';
require_once 'C:/inetpub/fia_private/db.php';

use QuickBooksOnline\API\DataService\DataService;

/**
 * Returns a ready-to-use DataService, or null if no valid token exists.
 */
function get_qbo_service(): ?DataService {
    $db  = get_db();
    $env = QBO_BASE_URL === 'development' ? 'sandbox' : 'production';

    $stmt = $db->prepare(
        'SELECT realm_id, access_token, refresh_token,
                access_token_expires, refresh_token_expires
         FROM qbo_tokens
         WHERE environment = ?
         ORDER BY updated_at DESC LIMIT 1'
    );
    $stmt->bind_param('s', $env);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;    // never connected
    }

    $now             = new DateTime();
    $refresh_expires = new DateTime($row['refresh_token_expires']);

    if ($refresh_expires <= $now) {
        return null;    // refresh token expired — user must re-authorize
    }

    $access_expires = new DateTime($row['access_token_expires']);
    $needs_refresh  = $access_expires <= (new DateTime('+5 minutes'));

    $dataService = DataService::Configure([
        'auth_mode'     => 'oauth2',
        'ClientID'      => QBO_CLIENT_ID,
        'ClientSecret'  => QBO_CLIENT_SECRET,
        'RedirectURI'   => QBO_REDIRECT_URI,
        'scope'         => QBO_SCOPE,
        'baseUrl'       => QBO_BASE_URL,
        'QBORealmID'      => $row['realm_id'],
        'accessTokenKey'  => $row['access_token'],
        'refreshTokenKey' => $row['refresh_token'],
        'logLocation'     => 'C:/inetpub/fia_private/logs',
    ]);

    $dataService->disableLog();

    if ($needs_refresh) {
        $dataService = _refresh_qbo_token($dataService, $row['realm_id'], $db, $env);
    }

    return $dataService;
}

/**
 * Exchange the refresh token for a new access token and update the DB.
 */
function _refresh_qbo_token(DataService $dataService, string $realm_id, mysqli $db, string $env): DataService {
    $helper = $dataService->getOAuth2LoginHelper();

    try {
        $newToken = $helper->refreshAccessTokenWithRefreshToken(
            $helper->getAccessToken()->getRefreshToken()
        );
    } catch (Throwable $e) {
        error_log('QBO token refresh failed: ' . $e->getMessage());
        return $dataService;    // return stale service; caller will hit an API error and can redirect
    }

    // getAccessTokenExpiresAt() returns an absolute date string ('Y/m/d H:i:s') — reformat for MySQL
    $access_expires  = date('Y-m-d H:i:s', strtotime($newToken->getAccessTokenExpiresAt()));
    $refresh_expires = date('Y-m-d H:i:s', strtotime($newToken->getRefreshTokenExpiresAt()));
    $at = $newToken->getAccessToken();
    $rt = $newToken->getRefreshToken();

    $stmt = $db->prepare('
        UPDATE qbo_tokens
        SET access_token = ?, refresh_token = ?,
            access_token_expires = ?, refresh_token_expires = ?
        WHERE realm_id = ? AND environment = ?
    ');
    $stmt->bind_param('ssssss', $at, $rt, $access_expires, $refresh_expires, $realm_id, $env);
    $stmt->execute();
    $stmt->close();

    // Reconfigure the DataService with fresh tokens
    $fresh = DataService::Configure([
        'auth_mode'       => 'oauth2',
        'ClientID'        => QBO_CLIENT_ID,
        'ClientSecret'    => QBO_CLIENT_SECRET,
        'RedirectURI'     => QBO_REDIRECT_URI,
        'scope'           => QBO_SCOPE,
        'baseUrl'         => QBO_BASE_URL,
        'QBORealmID'      => $realm_id,
        'accessTokenKey'  => $at,
        'refreshTokenKey' => $rt,
    ]);
    $fresh->disableLog();
    return $fresh;
}

