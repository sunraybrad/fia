-- warranty_co_contacts — stores the repeating contact list from FileMaker
-- Each warranty company can have multiple contacts (name / email-or-phone / ext).

CREATE TABLE IF NOT EXISTS warranty_co_contacts (
    contact_id     INT            NOT NULL AUTO_INCREMENT,
    warranty_co_id INT            NOT NULL,
    name           VARCHAR(255)   DEFAULT NULL,
    email_phone    VARCHAR(500)   DEFAULT NULL,   -- FM mixed emails+phone notes into one field
    ext            VARCHAR(50)    DEFAULT NULL,
    sort_order     SMALLINT       NOT NULL DEFAULT 0,
    PRIMARY KEY (contact_id),
    KEY idx_wcc_wc (warranty_co_id),
    CONSTRAINT fk_wcc_wc FOREIGN KEY (warranty_co_id)
        REFERENCES warranty_co (warranty_co_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
