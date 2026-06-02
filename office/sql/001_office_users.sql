-- =============================================================================
-- 001_office_users.sql
-- Office admin user accounts and password reset tokens
-- Run once against the `fia` database
-- =============================================================================

CREATE TABLE IF NOT EXISTS office_users (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name          VARCHAR(100)    NOT NULL,
    email         VARCHAR(255)    NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,          -- bcrypt via password_hash()
    is_active     TINYINT(1)      NOT NULL DEFAULT 1,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login    DATETIME            NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_office_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    token_hash VARCHAR(64)  NOT NULL,                -- SHA-256 of the emailed token
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME         NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_password_resets_token (token_hash),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id)
        REFERENCES office_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =============================================================================
-- Seed: initial admin user
-- Replace name/email before running.
-- Generate the hash in PHP:  echo password_hash('your-password', PASSWORD_BCRYPT);
-- =============================================================================
-- INSERT INTO office_users (name, email, password_hash)
-- VALUES ('Admin Name', 'admin@fiainspectors.com', '$2y$12$...');
