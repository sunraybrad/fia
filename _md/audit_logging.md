# Office Portal — Audit Logging

## Overview

All significant actions taken by authenticated office users are recorded in the `audit_log` table. The log captures who did what, when, to which record, and for high-value field changes, the old and new values.

Email sends are **not** duplicated here — the `emails` table is the authoritative record for all outbound email activity.

---

## Database Table

```sql
CREATE TABLE audit_log (
    id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    created_at     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    office_user_id INT              NULL,          -- NULL for unauthenticated actions (failed logins, password resets)
    office_email   VARCHAR(255)     NULL,          -- denormalized; readable even if user is later deleted
    action         VARCHAR(64)      NOT NULL,
    entity_type    VARCHAR(32)      NULL,          -- 'inspection' | 'inspector' | 'warranty_co' | NULL
    entity_id      INT              NULL,          -- record primary key
    detail         JSON             NULL,          -- context; see action reference below
    ip_address     VARCHAR(45)      NOT NULL DEFAULT '',
    PRIMARY KEY    (id),
    INDEX idx_audit_user    (office_user_id),
    INDEX idx_audit_action  (action),
    INDEX idx_audit_entity  (entity_type, entity_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Retention

Entries older than 1 year are automatically purged by a MySQL scheduled event:

```sql
SET GLOBAL event_scheduler = ON;

CREATE EVENT purge_audit_log
    ON SCHEDULE EVERY 1 DAY
    STARTS CURRENT_TIMESTAMP
    DO DELETE FROM audit_log
       WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

If the Event Scheduler is unavailable, purge manually:

```sql
DELETE FROM audit_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

---

## Helper Function

Defined in `C:\inetpub\fia_private\config.php`. Available on every page that loads config.php.

```php
log_audit(
    string  $action,
    ?string $entity_type = null,
    ?int    $entity_id   = null,
    array   $detail      = []
): void
```

- Never throws — failures are caught and written to the PHP error log.
- Reads `$_SESSION['office_id']` and `$_SESSION['office_email']` automatically.
- Pass `$detail` as a plain associative array; it is JSON-encoded before storage.

**Diff format** — for field changes, store old and new values under the field name:

```php
log_audit('inspection.save', 'inspection', $fia, [
    'status'     => ['old' => 'Unassigned', 'new' => 'Assigned'],
    'quoted_fee' => ['old' => '85.00',      'new' => '95.00'],
]);
```

---

## Action Reference

### Authentication

| action | entity_type | entity_id | detail |
|--------|-------------|-----------|--------|
| `login.success` | — | — | — |
| `login.fail` | — | — | `{"email": "..."}` |
| `logout` | — | — | — |
| `password_reset.request` | — | — | — |
| `password_reset.complete` | — | — | — |

> `login.fail` and password reset actions have `office_user_id = NULL` (unauthenticated context).

### Inspections (`save_inspection.php`)

| action | detail |
|--------|--------|
| `inspection.save` | `{"tab": "assign_inspector", "inspector_id": {"old": N, "new": N}, "status": {"old": "...", "new": "..."}}` |
| `inspection.save` | `{"status": {"old": "...", "new": "..."}}` — status-only button change |
| `inspection.save` | `{"tab": "dispatch", "inspector_id": {...}, "quoted_fee": {...}, "status": {...}}` — only changed fields included |
| `inspection.save` | `{"tab": "billing", "base_fee": {...}, "inspection_fee": {...}, "inspection_fee_approv": {...}}` — only changed fields |
| `inspection.save` | `{"tab": "vehicle"}` — event only, no diff |
| `inspection.save` | `{"tab": "findings1"}` — event only |
| `inspection.save` | `{"tab": "findings2"}` — event only |
| `inspection.save` | `{"tab": "tire"}` — event only |

### Inspectors (`save_inspector.php`)

| action | detail |
|--------|--------|
| `inspector.create` | `{"name": "John Smith"}` |
| `inspector.save` | `{"status": {"old": "...", "new": "..."}, "base_fee": {"old": "...", "new": "..."}}` — only changed fields |
| `inspector.archive` | — |
| `inspector.unarchive` | — |

### Warranty Companies (`save_warranty_co.php`)

| action | detail |
|--------|--------|
| `warranty_co.create` | `{"name": "ACME Warranty"}` |
| `warranty_co.save` | `{"tab": "details", "company_name": {"old": "...", "new": "..."}}` — diff only if name changed |
| `warranty_co.save` | `{"tab": "rates", "rate_base_national": {...}, "rate_base_florida": {...}}` — only changed fields |
| `warranty_co.save` | `{"tab": "fee_add", "state": "TX", "fee": "75.00"}` |
| `warranty_co.save` | `{"tab": "fee_update", "state": "TX", "fee": "80.00"}` |
| `warranty_co.save` | `{"tab": "fee_delete", "state": "TX"}` |
| `warranty_co.save` | `{"tab": "contact_add", "name": "Jane Doe"}` |
| `warranty_co.save` | `{"tab": "contact_update", "contact_id": N}` |
| `warranty_co.save` | `{"tab": "contact_delete", "contact_id": N}` |
| `warranty_co.archive` | — |
| `warranty_co.unarchive` | — |

---

## Useful Queries

**All activity for a specific inspection:**
```sql
SELECT created_at, office_email, action, detail
FROM audit_log
WHERE entity_type = 'inspection' AND entity_id = 12345
ORDER BY created_at DESC;
```

**All activity by a specific user:**
```sql
SELECT created_at, action, entity_type, entity_id, detail
FROM audit_log
WHERE office_user_id = 3
ORDER BY created_at DESC
LIMIT 100;
```

**All login failures in the last 7 days:**
```sql
SELECT created_at, ip_address, detail
FROM audit_log
WHERE action = 'login.fail'
  AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY created_at DESC;
```

**All status changes on inspections:**
```sql
SELECT created_at, office_email, entity_id AS fia_number,
       JSON_UNQUOTE(JSON_EXTRACT(detail, '$.status.old')) AS old_status,
       JSON_UNQUOTE(JSON_EXTRACT(detail, '$.status.new')) AS new_status
FROM audit_log
WHERE action = 'inspection.save'
  AND JSON_EXTRACT(detail, '$.status') IS NOT NULL
ORDER BY created_at DESC;
```

**Inspector fee changes:**
```sql
SELECT created_at, office_email, entity_id AS inspector_id,
       JSON_UNQUOTE(JSON_EXTRACT(detail, '$.base_fee.old')) AS old_fee,
       JSON_UNQUOTE(JSON_EXTRACT(detail, '$.base_fee.new')) AS new_fee
FROM audit_log
WHERE action = 'inspector.save'
  AND JSON_EXTRACT(detail, '$.base_fee') IS NOT NULL
ORDER BY created_at DESC;
```

---

## Files Modified

| File | Change |
|------|--------|
| `C:\inetpub\fia_private\config.php` | Added `log_audit()` function |
| `office/login.php` | Logs `login.success` and `login.fail` |
| `office/logout.php` | Logs `logout` before session is destroyed |
| `office/forgot_password.php` | Logs `password_reset.request` after token stored |
| `office/reset_password.php` | Logs `password_reset.complete` after password updated |
| `office/save_inspection.php` | Logs all tab saves; diffs on assign_inspector, status_change, dispatch, billing |
| `office/save_inspector.php` | Logs create, save (with status/fee diffs), archive, unarchive |
| `office/save_warranty_co.php` | Logs create, save (with diffs), archive, unarchive, all contact and fee schedule operations |

---

## Extending the Log

To add a new event, call `log_audit()` after the relevant DB write succeeds:

```php
// Event only
log_audit('my_entity.action', 'my_entity', $record_id);

// With diff
log_audit('my_entity.save', 'my_entity', $record_id, [
    'some_field' => ['old' => $old_value, 'new' => $new_value],
]);
```

Keep action names in `entity.verb` dot notation for consistency.
