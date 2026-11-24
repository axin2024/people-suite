CREATE TABLE IF NOT EXISTS hcm_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS companies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    name VARCHAR(160) NOT NULL,
    timezone VARCHAR(64) CHARACTER SET ascii NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    UNIQUE KEY uq_companies_code (group_id, code),
    UNIQUE KEY uq_companies_scope (group_id, id),
    FOREIGN KEY (group_id) REFERENCES hcm_groups (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS departments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    name VARCHAR(160) NOT NULL,
    effective_from DATE NOT NULL,
    UNIQUE KEY uq_departments_code (group_id, company_id, code),
    UNIQUE KEY uq_departments_scope (group_id, company_id, id),
    KEY ix_departments_parent (group_id, company_id, parent_id),
    FOREIGN KEY (group_id, company_id) REFERENCES companies (group_id, id),
    FOREIGN KEY (group_id, company_id, parent_id) REFERENCES departments (group_id, company_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS positions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    UNIQUE KEY uq_positions_code (group_id, company_id, code),
    UNIQUE KEY uq_positions_scope (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id) REFERENCES companies (group_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS position_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    effective_until DATE NULL,
    title VARCHAR(160) NOT NULL,
    active BOOLEAN NOT NULL,
    CHECK (effective_until IS NULL OR effective_until > effective_from),
    CHECK (active IN (0, 1)),
    UNIQUE KEY uq_position_revision_date (group_id, company_id, position_id, effective_from),
    UNIQUE KEY uq_position_revision_scope (group_id, company_id, position_id, id),
    FOREIGN KEY (group_id, company_id, position_id) REFERENCES positions (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, department_id) REFERENCES departments (group_id, company_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS headcount_budgets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    starts_on DATE NOT NULL,
    ends_before DATE NOT NULL,
    capacity INT UNSIGNED NOT NULL,
    CHECK (ends_before > starts_on),
    UNIQUE KEY uq_headcount_start (group_id, company_id, position_id, starts_on),
    UNIQUE KEY uq_headcount_scope (group_id, company_id, position_id, id),
    FOREIGN KEY (group_id, company_id, position_id) REFERENCES positions (group_id, company_id, id)
) ENGINE=InnoDB;
