# Client Portal — Code Audit Report

Audit performed on the `/client/` folder and its supporting files.  
Scope: all PHP files under `client/`, shared includes (`client/includes/`), and the private config/db files at `C:/inetpub/fia_private/`.

---

## Security Fixes

### 1. DB query `execute()` failures silently produced wrong output
**Files:** `client/index.php` (4 queries), `client/inspection.php` (3 queries), `client/print.php` (3 queries), `client/login.php` (1 query)  
**Issue:** All `execute()` calls returned a value that was never checked. A DB failure would silently produce incorrect results:
- `index.php` — status counts would show zeros; pagination count would produce a PHP fatal (`fetch_assoc()` on `false`); the single-result redirect would fail silently; the inspection list would appear empty.
- `inspection.php` / `print.php` — inspection load failure would not redirect cleanly; tire record failure would leave `$tire` in an undefined state; photo failure would show no photos with no indication of error.
- `login.php` — a DB failure during the credential lookup would treat the result as `null` (no match), silently denying all login attempts with no log entry.

**Fix:**
- Critical-path queries (inspection load in `inspection.php` and `print.php`) now redirect to `/client/index.php` on execute failure with the error logged.
- Non-critical queries (status counts, tire record, photos, job list, single redirect) now degrade gracefully to empty/null with the error logged.
- `login.php` credential query logs the failure and sets `$warco = null`, which correctly produces an "Invalid username or PIN" response rather than a confusing error page.

All failures write to the PHP error log with the file name and query context (`client/index.php/counts`, `client/inspection.php/photos`, etc.).

---

### 2. Photo filename not validated before URL construction
**Files:** `client/inspection.php` (~line 430), `client/print.php` (~line 487)  
**Issue:** `$pic['image_path']` from the DB was concatenated directly into a `src` URL attribute. While `h()` was correctly applied for HTML escaping, it does not guard against path traversal sequences (`../`). A DB record containing a manipulated filename could produce a URL pointing to an unintended server path.  
**Fix:** Added `preg_match('/^[a-zA-Z0-9._-]+$/', $pic['image_path'])` validation before constructing the URL in both files. Any photo record with a non-conforming filename is silently skipped rather than rendered. This is the same fix applied to the inspector portal's `save_job.php` during the inspector audit.

---

## Bug Fixes

### 3. Dead code: unused `yn()` function
**File:** `client/print.php` (~line 79)  
**Issue:** A `yn(mixed $v): string` helper function was defined but never called anywhere in the file or any include. It produced no output and served no purpose.  
**Fix:** Removed the function.

---

## False Positives (Confirmed Safe — No Action)

### Rate limiting on login
`login.php` already calls `is_rate_limited('warco_login', 5, 900)` before processing any credentials, and `record_attempt()` on every failure. 5 attempts in 15 minutes triggers a 30-minute block. Fully implemented. ✓

### Session idle timeout reset
`includes/auth.php` resets `$_SESSION['warco_start']` on every authenticated page load. Same pattern as the inspector portal — this is intentional activity-based timeout, not idle timeout. The 8-hour `SESSION_LIFETIME` is the hard ceiling. ✓

### CSRF token entropy
`csrf_token()` uses `bin2hex(random_bytes(32))` — cryptographically secure. ✓

### Search parameter SQL injection
All search parameters (`$search_fia`, `$search_claim`, `$search_contract`, `$filter_status`) are passed via `bind_param()` with typed placeholders. The `LIKE` patterns use the bound parameter correctly, not string concatenation. ✓

### Warranty company data isolation
Every query that loads inspection data includes `WHERE ... warranty_co_id = ?` using `$warco_id = (int)$_SESSION['warco_id']`. One warranty company cannot access another's inspections. ✓

---

## Positive Findings (No Action Required)

- **Prepared statements throughout** — all queries use parameterized `bind_param()`; no SQL injection surface found.
- **CSRF protection** — login form includes `csrf_token()` hidden field; `verify_csrf()` called at the top of the POST handler; `set_tab.php` (AJAX) also calls `verify_csrf()`.
- **XSS escaping** — all DB and user-supplied values passed through `h()` or `ro()` (which wraps `htmlspecialchars`) before output. No raw echoes of untrusted data.
- **Session security** — `httponly`, `secure`, `SameSite=Lax`, `use_strict_mode`, and `use_only_cookies` all set in `init_session()`.
- **Session regeneration** — `regenerate_session()` called on successful login.
- **Audit logging** — `log_audit('warco.login.success')` and `log_audit('warco.login.fail')` called on every login attempt.
- **bcrypt-ready PIN verification** — `login.php` checks for a `$2y$` prefix and uses `password_verify()` for hashed PINs, with plaintext fallback for legacy data. Future-proof.
- **Read-only portal** — no UPDATE, DELETE, or INSERT operations are exposed to the client. The portal is view-only by design.
- **Pagination with LIMIT/OFFSET** — the inspection list uses server-side pagination; no unbounded full-table fetch.
- **Input validation on filter parameters** — `$filter_status` is validated against a whitelist before use; `$fia` is always cast to `(int)`.

---

## Remaining (Code Quality — Not Addressed)

| # | Issue | File | Notes |
|---|-------|------|-------|
| 1 | No `LIMIT` on photo query | `client/inspection.php:54`, `client/print.php:53` | Could load large sets for photo-heavy inspections |
| 2 | `is_video()`, `ro()`, `fdate()`, `ftime()` duplicated in inspection.php and print.php | Both files | Identical functions defined in two places; candidate for a shared helpers include |
| 3 | `warco_status_badge()` in index.php duplicates status logic in inspection.php | Both files | Minor — consolidate into config.php or a helpers file |
| 4 | Tab names hardcoded in two files | `inspection.php:68`, `set_tab.php:14` | `$valid_tabs` array defined identically in both; extract to a shared constant |

---

## Summary Table

| # | Category | File | Status |
|---|----------|------|--------|
| 1 | Security/Bug | index.php, inspection.php, print.php, login.php | Fixed |
| 2 | Security | inspection.php, print.php | Fixed |
| 3 | Bug | print.php | Fixed |
| — | False positive | login.php rate limiting | No action |
| — | False positive | auth.php session reset | No action |
| — | False positive | CSRF entropy | No action |
| — | False positive | Search SQL injection | No action |
| — | False positive | Warco data isolation | No action |
| — | Code quality | Multiple files | Deferred |
