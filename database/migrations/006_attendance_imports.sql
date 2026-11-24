CREATE TABLE IF NOT EXISTS attendance_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    row_count SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_attendance_import_scope (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id) REFERENCES companies (group_id, id),
    FOREIGN KEY (group_id, owner_id) REFERENCES accounts (group_id, id),
    CHECK (kind IN ('roster','punches')),
    CHECK (row_count BETWEEN 1 AND 100)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_import_rows (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    import_id BIGINT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    row_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload JSON NOT NULL,
    state VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    outcome JSON NULL,
    PRIMARY KEY (group_id, company_id, import_id, line_no),
    UNIQUE KEY uq_attendance_import_row_ref (group_id, company_id, import_id, row_ref),
    FOREIGN KEY (group_id, company_id, import_id) REFERENCES attendance_imports (group_id, company_id, id),
    CHECK (state IN ('pending','succeeded','failed'))
) ENGINE=InnoDB;
