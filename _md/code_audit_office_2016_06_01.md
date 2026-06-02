# Office Portal — Code Audit Report

Audit performed on the `/office/` folder and its supporting files.  
Scope: PHP files, shared includes, private config, and the mailer helper.

---

## Security Fixes

### 1. XSS — JavaScript template variables not properly escaped
**File:** `office/inspection.php` (email compose section, ~line 1507)  
**Issue:** PHP values were injected into JavaScript string literals using `h()` (`htmlspecialchars`). HTML entity encoding is not decoded by the browser's JS engine inside `<script>` blocks. A value containing a backslash (e.g. `C:\path`) would silently produce incorrect JS, and crafted input could escape the string literal.  
**Fix:** Replaced all `'<?= h($value) ?>'` injections with `<?= json_encode($value) ?>`. `json_encode()` produces a properly quoted and escaped JS string literal including the surrounding quotes.  
Applied to: all 14 TPL object properties and the `?compose=` URL param echo.

---

### 2. Password reset not rate-limited
**File:** `office/forgot_password.php`  
**Issue:** Unlimited password reset requests were accepted per IP. An attacker could spam reset emails to a victim or flood the `password_resets` table.  
**Fix:** Added `is_rate_limited('pwd_reset', 5, 900)` gate (5 attempts per IP per 15 minutes) using the existing rate-limiting infrastructure already present in `config.php`. `record_attempt()` is called on each valid submission to increment the counter.

---

### 3. Session hijacking — no User-Agent binding
**File:** `office/includes/auth.php`  
**Issue:** `require_office()` validated session lifetime but did not bind the session to the originating browser. A stolen session cookie replayed from a different browser would be accepted.  
**Note:** `init_session()` in config.php already sets `secure`, `httponly`, `samesite=Lax`, and `use_strict_mode`, which provides a solid baseline.  
**Fix:** On first authenticated request, `$_SESSION['office_ua']` is set to the current User-Agent. On subsequent requests, it is compared using `hash_equals()`. A mismatch calls `_office_destroy('session_mismatch')`.

---

### 4. Session not validated against database
**File:** `office/includes/auth.php`  
**Issue:** `require_office()` checked session lifetime but never confirmed the `office_id` still existed as an active row in `office_users`. A deactivated account would remain logged in until their session naturally expired.  
**Fix:** Added a `SELECT id FROM office_users WHERE id = ? AND is_active = 1` check on every protected page load. If the account is inactive or deleted, the session is destroyed and the user is redirected to login.

---

### 5. Flash alert type not validated
**File:** `office/includes/header.php`  
**Issue:** `$flash['type']` was output directly into a Bootstrap CSS class (`alert-<?= htmlspecialchars($flash['type']) ?>`). While `htmlspecialchars` prevented XSS, an unexpected value would produce a non-existent class without error.  
**Fix:** Added whitelist validation against `['success', 'danger', 'warning', 'info']` at the top of header.php. Defaults to `'info'` for any unrecognised value.

---

### 6. CSRF tokens missing from three POST handlers
**Files:** `office/forgot_password.php`, `office/reset_password.php`, `office/email_test.php`  
**Issue:** All other office POST handlers already called `verify_csrf()`. These three were missed. `email_test.php` is authenticated but still requires CSRF protection as it can trigger outbound email.  
**Fix:** Added `verify_csrf()` call to each handler and `<input type="hidden" name="csrf_token">` to each corresponding form. Pre-login pages (`forgot_password`, `reset_password`) work correctly because `init_session()` is called before rendering, so the CSRF token is available in the anonymous session.

---

## Bug Fixes

### 7. Inspector email history queried by email address string
**File:** `office/inspector.php` (~line 76)  
**Issue:** The email history query matched on `emails.to_address` using both exact and `LIKE` patterns against the inspector's email address. If an inspector's email ever changed, emails sent to the old address would be missing from their history. The `LIKE` pattern could also pull in emails for unrelated addresses sharing a similar string.  
**Fix:** Changed the query to `WHERE e.inspector_id = ?` using the integer primary key. The `emails` table already stores `inspector_id` when sent from inspector context via `fia_send_email()`.

---

### 8. send_inspection_email.php — no guard if inspection not found
**File:** `office/send_inspection_email.php`  
**Issue:** After the DB lookup `SELECT inspector_id, warranty_co_id FROM inspections WHERE fia_number = ?`, if `$row` came back null (non-existent FIA number), execution continued silently. The email would send with null relationship IDs, tagged to a non-existent record.  
**Fix:** Added `if (!$row) { header('Location: /office/index.php'); exit; }` immediately after the fetch.

---

### 9. forgot_password.php — random_bytes() not in try-catch
**File:** `office/forgot_password.php`  
**Issue:** `random_bytes(32)` throws an `Exception` if the OS randomness source fails (can happen on Windows under certain conditions). The exception was uncaught, which would produce an unhandled 500 error.  
**Fix:** Wrapped the entire token generation, DB writes, and email send block in an outer `try/catch`. On failure, logs the error and sets `$error` to a generic server message. Changed `$submitted = true` to `if (!$error) { $submitted = true; }` so a server error is surfaced to the user rather than falsely confirming the email was sent. The inner PHPMailer `try/catch` is preserved unchanged.

---

## False Positives

### reset_password.php — timing attack on token comparison
**Claim:** Token comparison used `===` and was vulnerable to a timing attack.  
**Confirmed false positive.** The token hash is used as a SQL `WHERE` parameter in a parameterized prepared statement — no PHP-level string comparison occurs. The comparison happens entirely in the database engine. `hash_equals()` is not applicable here.

### warranty_co.php email history — email address query
**Initial belief:** The email history was queried by `inspector_id`.  
**Confirmed real bug.** Inspection of the code confirmed it was querying by email address string (`to_address = ?` and `to_address LIKE ?`). Fixed as item #7 above.

### AJAX error handling — no .catch() blocks
**Claim:** `fetch()` calls in `inspection.php` and `warranty_co.php` had no error handling.  
**Confirmed false positive.** All `fetch()` calls already had `.catch()` handlers at the time of audit.

---

## Bonus Fixes Found During Implementation

### fee_add / fee_update / fee_delete — unreachable dead code
**File:** `office/save_warranty_co.php`  
**Issue:** The `in_array` guard for AJAX operations only listed `['contact_add', 'contact_update', 'contact_delete']`. The fee schedule operations (`fee_add`, `fee_update`, `fee_delete`) were nested inside this block but could never be reached since their `$tab` values were not in the array. All fee schedule saves silently fell through to a redirect to `/office/warranty_cos.php`.  
**Fix:** Added the three fee tab values to the `in_array` check. Discovered during audit logging implementation.

---

## Summary Table

| # | Category | File | Status |
|---|----------|------|--------|
| 1 | Security | office/inspection.php | Fixed |
| 2 | Security | office/forgot_password.php | Fixed |
| 3 | Security | office/includes/auth.php | Fixed |
| 4 | Bug | office/includes/auth.php | Fixed |
| 5 | Security | office/includes/header.php | Fixed |
| 6 | Security | forgot_password.php, reset_password.php, email_test.php | Fixed |
| 7 | Bug | office/inspector.php | Fixed |
| 8 | Bug | office/send_inspection_email.php | Fixed |
| 9 | Bug | office/forgot_password.php | Fixed |
| — | False positive | office/reset_password.php | No action |
| — | False positive | AJAX .catch() blocks | No action |
| — | Bonus bug | office/save_warranty_co.php | Fixed |

---

## Post-Audit Additions (2026-06-02)

Photo upload functionality was added to `office/inspection.php` after the original audit. The new files (`upload_photo.php`, changes to `inspection.php`, and photo delete/caption handling in `save_inspection.php`) were reviewed and hardened to match the same standards applied to the inspector and client portals on the same date.

### 10. Deprecated `mime_content_type()` — PHP 7+ fatal error
**File:** `office/upload_photo.php` (~line 80)  
**Issue:** Identical to the issue found in `inspector/upload.php`. `mime_content_type()` was removed in PHP 7.0 and would cause a fatal error on every upload attempt.  
**Fix:** Replaced with `finfo_open(FILEINFO_MIME_TYPE)` / `finfo_file()` / `finfo_close()`. Handle opened once before the loop, closed after.

---

### 11. `mkdir()` failure silently caused upload errors
**File:** `office/upload_photo.php` (~line 42)  
**Issue:** Return value of `mkdir()` was not checked. If the per-inspection photo directory could not be created, all subsequent `move_uploaded_file()` calls would fail with no useful error.  
**Fix:** Changed to `if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true))`. On failure, logs the path and redirects back to the photos tab with `err=upload`.

---

### 12. Upload extension not whitelisted
**File:** `office/upload_photo.php` (~line 96)  
**Issue:** MIME type was the only validation layer. A file with a `.php` extension but valid image content would pass the MIME check and be stored with an executable extension.  
**Fix:** Extension validated against explicit whitelist `['jpg','jpeg','png','heic','heif','webp','mp4','mov','avi','wmv','mpeg','mpg']` after the MIME check. Files with any other extension are rejected before the filename is constructed.

---

### 13. Upload INSERT `execute()` unchecked; orphaned files on DB failure
**File:** `office/upload_photo.php` (~line 107)  
**Issue:** If the `INSERT INTO pictures` failed, the file was already moved to disk but no DB record was created — an orphaned file with no way to display or delete it.  
**Fix:** `execute()` return value now checked. On failure: logs the error, calls `unlink($dest)` to remove the orphaned file, and adds a user-facing error for that file.

---

### 14. DB query `execute()` failures silently produced wrong output
**File:** `office/inspection.php` (6 queries)  
**Issue:** Same pattern fixed in the inspector and client portals. All six `execute()` calls on the inspection detail page were unchecked — inspection load, tire record, nearby inspectors, photos, emails, and warco contacts.  
**Fix:**
- Inspection load failure → redirect to `/office/index.php` with error logged.
- Tire record, nearby inspectors, photos, emails, warco contacts → degrade gracefully to `null` / empty array with error logged.

---

### 15. Photo filename not validated before URL construction
**File:** `office/inspection.php` (~line 1198)  
**Issue:** `$pic['image_path']` concatenated into a `src` attribute without path validation. Same issue fixed in the inspector and client portals.  
**Fix:** Added `preg_match('/^[a-zA-Z0-9._-]+$/', $pic['image_path'])` guard before building `$thumb`. Photos with non-conforming filenames are skipped.

---

### 16. Path traversal in photo file deletion; silent `@unlink`
**File:** `office/save_inspection.php` (~line 422)  
**Issue:** Identical to the issue found in `inspector/save_job.php`. `$pic['image_path']` from the DB was passed to `unlink()` without path validation, and `@` suppressed any failure silently.  
**Fix:** Added `preg_match('/^[a-zA-Z0-9._-]+$/', $pic['image_path'])` guard before constructing the file path. Removed `@unlink` — failures now log to the PHP error log.

### 17. Photo delete SELECT `execute()` unchecked
**File:** `office/save_inspection.php` (~line 408)  
**Issue:** The `SELECT image_path FROM pictures` query before the delete was not checking `execute()`. A DB failure would leave `$pic` in an undefined state, potentially causing a PHP notice on the subsequent `if ($pic)` check.  
**Fix:** Added execute() check; on failure sets `$pic = null` and logs the error.

---

## Updated Summary Table

| # | Category | File | Status |
|---|----------|------|--------|
| 1 | Security | office/inspection.php | Fixed |
| 2 | Security | office/forgot_password.php | Fixed |
| 3 | Security | office/includes/auth.php | Fixed |
| 4 | Bug | office/includes/auth.php | Fixed |
| 5 | Security | office/includes/header.php | Fixed |
| 6 | Security | forgot_password.php, reset_password.php, email_test.php | Fixed |
| 7 | Bug | office/inspector.php | Fixed |
| 8 | Bug | office/send_inspection_email.php | Fixed |
| 9 | Bug | office/forgot_password.php | Fixed |
| — | False positive | office/reset_password.php | No action |
| — | False positive | AJAX .catch() blocks | No action |
| — | Bonus bug | office/save_warranty_co.php | Fixed |
| 10 | Security | office/upload_photo.php | Fixed (2026-06-02) |
| 11 | Bug | office/upload_photo.php | Fixed (2026-06-02) |
| 12 | Security | office/upload_photo.php | Fixed (2026-06-02) |
| 13 | Bug | office/upload_photo.php | Fixed (2026-06-02) |
| 14 | Bug | office/inspection.php | Fixed (2026-06-02) |
| 15 | Security | office/inspection.php | Fixed (2026-06-02) |
| 16 | Security | office/save_inspection.php | Fixed (2026-06-02) |
| 17 | Bug | office/save_inspection.php | Fixed (2026-06-02) |
