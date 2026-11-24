CREATE TABLE IF NOT EXISTS accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) CHARACTER SET ascii NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY uq_accounts_email (group_id, email),
    UNIQUE KEY uq_accounts_scope (group_id, id),
    FOREIGN KEY (group_id) REFERENCES hcm_groups (id),
    CHECK (enabled IN (0, 1))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS group_administrators (
    group_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, account_id),
    FOREIGN KEY (group_id, account_id) REFERENCES accounts (group_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS company_roles (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (group_id, company_id, account_id, role),
    FOREIGN KEY (group_id, company_id) REFERENCES companies (group_id, id),
    FOREIGN KEY (group_id, account_id) REFERENCES accounts (group_id, id),
    CHECK (role IN ('hr', 'recruiter', 'manager', 'payroll_maker', 'payroll_reviewer', 'employee'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS recruiter_positions (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, company_id, position_id, account_id),
    FOREIGN KEY (group_id, company_id, position_id) REFERENCES positions (group_id, company_id, id),
    FOREIGN KEY (group_id, account_id) REFERENCES accounts (group_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    object_type VARCHAR(40) CHARACTER SET ascii NOT NULL,
    object_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(64) CHARACTER SET ascii NOT NULL,
    facts JSON NOT NULL,
    occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_audit_object (group_id, company_id, object_type, object_id, id),
    FOREIGN KEY (group_id, company_id) REFERENCES companies (group_id, id),
    FOREIGN KEY (group_id, actor_id) REFERENCES accounts (group_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS operation_receipts (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(64) CHARACTER SET ascii NOT NULL,
    request_key VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_digest CHAR(64) CHARACTER SET ascii NOT NULL,
    response JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (group_id, company_id, actor_id, action, request_key),
    FOREIGN KEY (group_id, company_id) REFERENCES companies (group_id, id),
    FOREIGN KEY (group_id, actor_id) REFERENCES accounts (group_id, id)
) ENGINE=InnoDB;
