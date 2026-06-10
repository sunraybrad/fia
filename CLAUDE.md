# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**FIA (Florida Inspection Associates)** is an automotive inspection service. Insurance/warranty companies request third-party mechanical inspections; office staff dispatch them to contract inspectors nationwide; inspectors record findings and photos via a web portal; the office bills the warranty company. The app is plain procedural PHP (no framework, intentionally — see `fia_private/_md/goal_background.md`) on IIS/Windows with MySQL.

**History matters here:** the system was migrated off FileMaker in 2026. `CLAUDE_initial_premigration.md` documents the *old* FileMaker architecture — it is historical reference only; nothing in it describes the running app. The modern code lives in the portal subdirectories; the root-level `.php` files are the public marketing site plus a few legacy forms.

## Environments & Commands

- **No build, lint, or test tooling exists.** Files run as-is under IIS + PHP.
- `composer install` — installs the only dependencies: `tecnickcom/tcpdf`, `setasign/fpdi-tcpdf`, `quickbooks/v3-php-sdk` (plus `PHPMailer6/`, which is bundled manually in the repo, not via Composer).
- Dev server: this working copy (`D:/Clients/inetpub/wwwroot/FIA`) served at `https://fia.sunraywebdev.com`. Production: `C:/inetpub/wwwroot/fia` at `https://fiainspectors.com`. `DEV_MODE` is derived from `HTTP_HOST` at runtime in config.php — dev gets `display_errors`, the QBO sandbox, and a remote-image fallback for photos.
- Deployment is a manual FTP sync (VS Code SFTP extension; `.vscode/sftp.json`, not committed).
- Scheduled job (Windows Task Scheduler, every ~15 min): `php C:\inetpub\fia_private\cron\process_photos.php` — burns caption banners into uploaded photos and sets `pictures.is_processed = 1`.

## Private Directory (outside web root, not in git)

`C:\inetpub\fia_private\` holds everything secret or non-public. Never copy these into the web root or commit them:

- `config.php` — all constants (site, SMTP, paths, QBO keys, reCAPTCHA) **and** shared helpers: `init_session()`, `csrf_token()`/`verify_csrf()`, `is_rate_limited()`/`record_attempt()`, `set_flash()`/`get_flash()`, `h()` (HTML escaping), `format_currency()`, `status_badge()`/`inspection_status_colour()`/`inspector_status_colour()`, `log_audit()`. Check here before writing a new helper — and reuse these instead of redefining (a known past bug pattern).
- `db.php` — `get_db()`: shared mysqli singleton (strict SQL mode, utf8mb4, TZ -05:00). Never call `new mysqli()` directly.
- `upload_handler.php`, `photo_actions_handler.php` — shared photo upload/rotate/delete logic, `require`d by both the office and inspector portals.
- `cron/process_photos.php`, `logs/`, `billing_reports/` (archived billing PDFs, ≥1-year retention).
- `_md/` — all working documentation (see Documentation section below).

Every page starts with this bootstrap (paths are absolute, by design):

```php
require_once 'C:\inetpub\fia_private\config.php';
require_once 'C:\inetpub\fia_private\db.php';
require_once __DIR__ . '/includes/auth.php';   // the portal's own guard
init_session();
require_office();   // or require_inspector() / require_warco()
```

## Architecture: Three Portals + Public Site

Each portal is a self-contained directory with its own `includes/auth.php` (session guard), `includes/header.php`/`footer.php`, `login.php`/`logout.php`, and Bootstrap-based UI:

- **`/office`** — staff portal (session key `office_id`). Dashboard, full inspection/inspector/warranty-company CRUD, GEICO email intake parser (`intake_geico.php`), email sending, billing report generation, password reset flow. All POST handlers are `save_*.php` / `send_*.php` files that redirect back with flash messages.
- **`/inspector`** — contractor portal (session key scoped to `inspector_id`). Lists assigned jobs, edits findings (`job.php`/`save_job.php`), uploads photos/videos, prints worksheets. All queries are ownership-scoped by `inspector_id`.
- **`/client`** — warranty-company portal (scoped by `warranty_co_id`). Read-only inspection views and print.
- **`/qbo`** — QuickBooks Online integration: OAuth flow (`connect.php`/`callback.php`), `get_qbo_service()` with auto token refresh (`qbo_service.php`, tokens in `qbo_tokens` table), entity sync (`qbo_sync.php`: inspectors → QBO Vendors, warranty cos → QBO Customers, QBO IDs stored in `quickbooks_ref` columns). See `fia_private/_md/qbo_primer.md` before touching this.
- **Root `.php` files** — public marketing site (friendly URLs rewritten in `web.config`) and public forms (`connectform.php` → `connect_form2.php`, `employ_form.php` → `employ_form2.php`) using reCAPTCHA.

All three auth guards enforce: User-Agent binding, per-request DB re-check that the account is still active/unarchived, and idle timeout (`SESSION_LIFETIME`, 8 h). Keep them consistent — drift between the three guards was a past audit finding.

## Core Domain Rules

- An inspection is identified by its **FIA number** (`fia` — primary key of `inspections`, used in URLs, filenames, and photo paths).
- **Status workflow:** `Unassigned → Assigned → Complete → Billed → Invoiced` (plus `On Hold`). Status drives edit locking:
  - Inspectors can edit/upload only while status is `Assigned` (uploads also allowed at `Unassigned`).
  - The office portal hard-locks an inspection at `Invoiced` (`$is_locked` + `<fieldset disabled>`; `save_inspection.php` rejects writes).
- **Photos/videos:** stored under `UPLOAD_PATH` (`C:/pix/{fia}/…`), served via the `vPix` IIS virtual directory. Uploads are resized to max 800px; same filename intentionally overwrites (replacement semantics) and resets `is_processed = 0` so the caption banner is re-burned by the cron.
- **Audit logging:** every significant office-portal DB write calls `log_audit()` (action names in `entity.verb` dot notation, diffs as `{'field': {'old':…, 'new':…}}`). See `fia_private/_md/audit_logging.md` for the action reference. Emails are *not* audit-logged — the `emails` table is authoritative for outbound mail.
- **Email:** always send through `fia_send_email()` (`includes/mailer.php`) — it sends via PHPMailer/SMTP and logs to the `emails` + `email_attachments` tables. Known deliberate exception: `office/forgot_password.php` builds its own mailer so the reset token never lands in the `emails` table.
- **Security conventions (uniform across modern code):** prepared statements only; `verify_csrf()` at the top of every POST handler (AJAX handlers return JSON 403 inline instead of the HTML `die()`); `h()` on all output; rate limiting on login/password endpoints; bcrypt via `password_hash()`.

## PDF Generation

Two report types, both FPDI overlays onto CorelDraw-exported shell PDFs in `templates/`. Full detail in `fia_private/_md/pdf_creation.md` — read it before changing coordinates (they were extracted programmatically from the `*-fields.pdf` templates; do not eyeball them).

- **Worksheet** (inspector's 2-page in-shop form): hub `includes/worksheet_pdf.php` (`worksheet_pdf_bytes()`/`_stream()`) → renderer `pdf/generate_worksheet_template.php`. Generated on demand; never stored.
- **Billing report** (sent to warranty co): hub `includes/billing_pdf.php` → renderer `pdf/generate_billing_template.php`, with a 2×3 photo/video appendix. Archived to `{PRIVATE_PATH}/billing_reports/FIA_Report_{fia}.pdf` (filesystem is authoritative; overwrite on regenerate).

## Documentation (`C:\inetpub\fia_private\_md\`)

Working docs live in `_md/` under the private directory — outside the web root and outside git (moved there 2026-06-10; they contain credentials and audit detail that must never be deployed or committed): `dev_log.md` (running session log — append a dated entry when finishing significant work), `goal_background.md` (project rationale), `qbo_primer.md`, `pdf_creation.md`, `audit_logging.md`, and dated code-audit reports (`code_audit_full_2026-06-10.md` is the most recent, including its remediation log).

## Gotchas

- `.gitignore` excludes `vendor/`, `*.sql`, `office/sql/`, `.vscode/`, `.claude/` (plus `_md/` as a safety net) — schema files exist only on this machine, and all working docs live outside the repo in `fia_private/_md/`.
- QBO SDK: use IPP data objects directly, not the Facade layer (PHP 8.x incompatibility). `getAccessTokenExpiresAt()` returns a formatted date string, not seconds — past bug.
- TCPDF: `setCellHeightRatio()` before each `multiCell()` (the `$lineH` param is unreliable in this version); reset to `1.25` after.
- `web.config` enforces HTTPS, rewrites `/API/*` to `API/*.php`, and caps uploads at 150 MB; `office/generate_billing_report.php` has its own elevated FastCGI timeout there.
- Some legacy root files and libraries (e.g. `AppRequest.php`, used only as an email-body template) predate the migration — check `fia_private/_md/cleanup_legacy_files_2026_06_02.md` before assuming a root file is live.
