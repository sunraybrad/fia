-- =============================================================================
-- 002_rate_limits.sql
-- Rate limiting table — shared by login and any other brute-force-sensitive actions
-- Run once against the `fia` database
-- =============================================================================

CREATE TABLE IF NOT EXISTS rate_limits (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    ip_address     VARCHAR(45)   NOT NULL,          -- supports IPv6
    rl_action      VARCHAR(50)   NOT NULL,           -- e.g. 'office_login'
    attempts       INT UNSIGNED  NOT NULL DEFAULT 1,
    window_start   DATETIME      NOT NULL,
    blocked_until  DATETIME          NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rl_ip_action_window (ip_address, rl_action, window_start),
    KEY idx_rl_blocked (ip_address, rl_action, blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
