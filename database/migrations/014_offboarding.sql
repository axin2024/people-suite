CREATE TABLE IF NOT EXISTS offboarding_cases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    employee_account_id BIGINT UNSIGNED NULL,
    reference VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ends_before DATE NOT NULL,
    assignment_version INT UNSIGNED NOT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'in_progress',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    reason VARCHAR(600) NOT NULL,
    opened_by BIGINT UNSIGNED NOT NULL,
    opened_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    decided_by BIGINT UNSIGNED NULL,
    decision_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
    decided_at DATETIME(6) NULL,
    current_employment_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(state='cancelled',NULL,employment_id)) STORED,
    UNIQUE KEY uq_offboarding_scope (group_id, company_id, id),
    UNIQUE KEY uq_offboarding_reference (group_id, company_id, reference),
    UNIQUE KEY uq_offboarding_current (group_id, company_id, current_employment_id),
    KEY ix_offboarding_employment (group_id, company_id, employment_id, state),
    KEY ix_offboarding_employee (group_id, company_id, employee_account_id, id),
    FOREIGN KEY (group_id, company_id, employment_id) REFERENCES employments (group_id, company_id, id),
    FOREIGN KEY (group_id, employee_account_id) REFERENCES accounts (group_id, id),
    FOREIGN KEY (group_id, opened_by) REFERENCES accounts (group_id, id),
    FOREIGN KEY (group_id, decided_by) REFERENCES accounts (group_id, id),
    CHECK ((state='in_progress' AND decided_by IS NULL AND decision_ref IS NULL AND decided_at IS NULL)
        OR (state IN ('completed','cancelled') AND decided_by IS NOT NULL AND decision_ref IS NOT NULL AND decided_at IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS offboarding_tasks (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    case_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    label VARCHAR(160) NOT NULL,
    owner_role VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_account_id BIGINT UNSIGNED NULL,
    evidence VARCHAR(1200) NULL,
    decision_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    confirmed_at DATETIME(6) NULL,
    PRIMARY KEY (group_id, company_id, case_id, code),
    KEY ix_offboarding_owner (group_id, company_id, owner_account_id, case_id),
    FOREIGN KEY (group_id, company_id, case_id) REFERENCES offboarding_cases (group_id, company_id, id),
    FOREIGN KEY (group_id, owner_account_id) REFERENCES accounts (group_id, id),
    FOREIGN KEY (group_id, confirmed_by) REFERENCES accounts (group_id, id),
    CHECK ((owner_role='manager' AND owner_account_id IS NOT NULL)
        OR (owner_role IN ('hr','payroll_reviewer') AND owner_account_id IS NULL)),
    CHECK ((confirmed_at IS NULL AND evidence IS NULL AND decision_ref IS NULL AND confirmed_by IS NULL)
        OR (confirmed_at IS NOT NULL AND evidence IS NOT NULL AND decision_ref IS NOT NULL AND confirmed_by IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS archived_employment_access (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    case_id BIGINT UNSIGNED NOT NULL,
    granted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (group_id, company_id, employment_id, account_id),
    KEY ix_archived_employment_account (group_id, account_id, company_id, employment_id),
    FOREIGN KEY (group_id, company_id, employment_id) REFERENCES employments (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, case_id) REFERENCES offboarding_cases (group_id, company_id, id),
    FOREIGN KEY (group_id, account_id) REFERENCES accounts (group_id, id)
) ENGINE=InnoDB;
