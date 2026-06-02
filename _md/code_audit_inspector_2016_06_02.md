# Inspector Portal — Code Audit Report

Audit performed on the `/inspector/` folder and its supporting files.  
Scope: all PHP files under `inspector/`, shared includes (`inspector/includes/`), and the private config/db files at `C:/inetpub/fia_private/`.

---

## Security Fixes

### 1. Deprecated `mime_content_type()` — PHP 7+ fatal error
**File:** `inspector/upload.php` (~line 93)  
**Issue:** `mime_content_type()` was removed in PHP 7.0. On any PHP 7+ server the upload handler would throw a fatal error on every file submission, making photo uploads completely non-functional.  
**Fix:** Replaced with `finfo_open(FILEINFO_MIME_TYPE)` / `finfo_file()` / `finfo_close()`. The `finfo` handle is opened once before the upload loop and closed after, avoiding per-file overhead.

---

### 2. No rate limiting on login
**File:** `inspector/login.php` (~line 37)  
**Issue:** The `is_rate_limited()` and `record_attempt()` functions already existed in `config.php` with a `rate_limits` DB table, but login.php never called them. An attacker could make unlimited PIN guesses against any Inspector ID.  
**Fix:** Added `elseif (is_rate_limited('inspector_login'))` check before any DB auth attempt. On any authentication failure (wrong PIN, unknown ID, inactive account), `record_attempt('inspector_login')` is called. After 10 failures within 15 minutes the IP is blocked for 30 minutes. Validation errors (empty fields) do not count as attempts.

---

### 3. Path traversal in photo file deletion
**File:** `inspector/save_job.php` (~line 180)  
**Issue:** `$pic['image_path']` (fetched from DB) was passed directly to `unlink()` without validation. While the DB is not directly user-controlled, a compromised or manipulated record containing `../../sensitive_file` could delete arbitrary files on the server.  
**Fix:** Added a `preg_match('/^[a-zA-Z0-9._-]+$/', $pic['image_path'])` guard before constructing the file path. Any path containing `/`, `\`, or `..` sequences is silently skipped. Also removed the `@` error suppressor — `unlink()` failures are now logged to the PHP error log.

---

### 4. Upload extension not whitelisted
**File:** `inspector/upload.php` (~line 110)  
**Issue:** MIME type was the only validation layer. A file named `shell.jpg.php` would pass the MIME check (content is a valid JPEG) but `pathinfo(PATHINFO_EXTENSION)` would return `php`, producing a stored file with a `.php` extension. If the MIME check were ever weakened or bypassed, a PHP shell could be written to `/vPix/`.  
**Fix:** After the MIME check passes, the extension extracted from the original filename is validated against an explicit whitelist: `['jpg','jpeg','png','heic','heif','webp','mp4','mov','avi','wmv','mpeg','mpg']`. Files with any other extension are rejected with a user-facing error before the filename is constructed.

---

## Bug Fixes

### 5. `mkdir()` failure silently caused upload errors
**File:** `inspector/upload.php` (~line 53)  
**Issue:** `mkdir()` return value was ignored. If the per-inspection photo directory could not be created (permission denied, disk full), every subsequent `move_uploaded_file()` call would silently fail, producing confusing "Failed to save" errors with no indication of the root cause.  
**Fix:** Changed to `if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true))`. On failure, logs the directory path to the error log and redirects the inspector back to the job page with an `err=upload` parameter rather than continuing into a broken upload loop.

---

### 6. Flash message displayed `<strong>` tags as literal text
**File:** `inspector/job.php` (~line 74)  
**Issue:** The read-only notice for completed inspections was hardcoded with `<strong>Complete</strong>` inside a flash message string that was then passed through `h()` (`htmlspecialchars`). The tags were escaped to `&lt;strong&gt;` and displayed literally — inspectors saw the raw HTML.  
**Fix:** Removed the HTML tags. The message now reads as plain text, which `h()` passes through cleanly.

---

### 7. Caption save silently reported success on DB failure
**File:** `inspector/save_job.php` (~line 145)  
**Issue:** The caption UPDATE loop called `$stmt->execute()` with no return check. A DB failure during any caption save would leave captions unsaved while the inspector was redirected to `&saved=1`, giving false confirmation that changes were persisted.  
**Fix:** Each `execute()` call now checks the return value. Any failure is logged with the `picture_id` and DB error. If any caption in the batch fails, the redirect uses `&err=1` instead of `&saved=1`.

---

### 8. DB query `execute()` failures silently produced wrong output
**Files:** `inspector/index.php` (3 queries), `inspector/job.php` (2 queries), `inspector/jobs.php` (1 query), `inspector/upload.php` (INSERT in loop)  
**Issue:** All `execute()` calls returned a value that was never checked. A DB failure would silently produce empty result sets — the dashboard would show zero job counts, the job list would appear empty, and the inspection detail page would show no photos. Upload DB failures left orphaned files on disk with no corresponding DB record.  
**Fix:**  
- `index.php` — messages, counts, and recent jobs queries now check `execute()`; on failure the error is logged and the section degrades gracefully to an empty result.  
- `job.php` — inspection load failure redirects to the jobs list with `err=1`; photo load failure degrades to an empty gallery.  
- `jobs.php` — failure logs and degrades to an empty list.  
- `upload.php` — INSERT failure logs the error, deletes the already-moved file from disk (cleanup), and adds a user-facing error message for that file.

---

## False Positives

### Session idle timeout — `insp_start` reset on every page load
**File:** `inspector/includes/auth.php`  
**Initial concern:** `$_SESSION['insp_start'] = time()` is called on every page load, meaning an inspector who is actively browsing never times out.  
**Confirmed intentional.** This is activity-based timeout — the 8-hour `SESSION_LIFETIME` in `config.php` is the hard ceiling, and active use keeps the session alive. The variable name `insp_start` is slightly misleading (it behaves more like `last_activity`) but the behavior is correct. No action taken.

---

## Positive Findings (No Action Required)

- **Prepared statements throughout** — all DB queries use `mysqli` prepared statements; no SQL injection surface.
- **CSRF protection** — all POST forms include a hidden `csrf_token` field; all POST handlers call `verify_csrf()` using `hash_equals()` for timing-safe comparison.
- **XSS escaping** — all database and user-supplied values are passed through `h()` before output; no raw `echo` of untrusted data found.
- **Session security** — `httponly`, `secure`, `SameSite=Lax`, `use_strict_mode`, and `use_only_cookies` all configured correctly in `init_session()`.
- **Inspector data isolation** — every query filters on `inspector_id = ?`; ownership is verified in both SELECT and UPDATE/DELETE WHERE clauses.
- **Session regeneration** — `regenerate_session()` called on login, preventing session fixation.
- **Soft-delete on photos** — `is_archived = TRUE` pattern preserves audit trail; physical file deletion is a secondary step.
- **Credentials outside web root** — DB credentials and SMTP password stored in `C:/inetpub/fia_private/`, not accessible via the web server.

---

## Remaining (Code Quality — Not Addressed)

| # | Issue | File | Notes |
|---|-------|------|-------|
| 1 | No `LIMIT` on photo query | `inspector/job.php:54` | Could load hundreds of rows for busy jobs |
| 2 | Helper functions (`is_video`, `val`, `fdate`, `ftime`) local to job.php | `inspector/job.php:87` | Can't be reused without a shared helpers file |
| 3 | Magic strings for statuses (`'Assigned'`, `'Complete'`, etc.) | Multiple files | Define constants in config.php |
| 4 | Inconsistent date formatting (`m/d/Y` vs `Y-m-d` vs `l, F j, Y`) | Multiple files | Centralise into date helper functions |
| 5 | Flash message construction duplicated per page | Multiple files | Extract to a shared helper |

---

## Summary Table

| # | Category | File | Status |
|---|----------|------|--------|
| 1 | Security | inspector/upload.php | Fixed |
| 2 | Security | inspector/login.php | Fixed |
| 3 | Security | inspector/save_job.php | Fixed |
| 4 | Security | inspector/upload.php | Fixed |
| 5 | Bug | inspector/upload.php | Fixed |
| 6 | Bug | inspector/job.php | Fixed |
| 7 | Bug | inspector/save_job.php | Fixed |
| 8 | Bug | index.php, job.php, jobs.php, upload.php | Fixed |
| — | False positive | inspector/includes/auth.php | No action |
| — | Code quality | Multiple files | Deferred |
