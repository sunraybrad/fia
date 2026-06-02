-- =============================================================================
-- 003_zip_codes.sql
-- US zip code reference table with lat/lng for inspector proximity queries.
-- rLat and rLong from FileMaker are omitted — MySQL's RADIANS() handles
-- the degree-to-radian conversion inline at query time.
-- =============================================================================

CREATE TABLE IF NOT EXISTS zip_codes (
    zip        VARCHAR(10)   NOT NULL,
    lat        DECIMAL(9,6)  NOT NULL,
    lng        DECIMAL(9,6)  NOT NULL,
    city       VARCHAR(100)      NULL,
    state_code CHAR(2)           NULL,

    PRIMARY KEY (zip),
    KEY idx_zip_state (state_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
