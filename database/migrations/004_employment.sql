CREATE TABLE IF NOT EXISTS employees (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    candidate_id BIGINT UNSIGNED NOT NULL,
    employee_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_employee_group (group_id, id),
    UNIQUE KEY uq_employee_code (group_id, employee_code),
    UNIQUE KEY uq_employee_candidate (group_id, candidate_id),
    UNIQUE KEY uq_employee_account (group_id, account_id),
    FOREIGN KEY (group_id, candidate_id) REFERENCES candidates (group_id, id),
    FOREIGN KEY (group_id, account_id) REFERENCES accounts (group_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    starts_on DATE NOT NULL,
    ends_before DATE NULL,
    assignment_version INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_employment_scope (group_id, company_id, id),
    UNIQUE KEY uq_employment_employee_start (group_id, employee_id, starts_on),
    KEY ix_employment_asof (group_id, company_id, starts_on, ends_before, employee_id),
    FOREIGN KEY (group_id, company_id) REFERENCES companies (group_id, id),
    FOREIGN KEY (group_id, employee_id) REFERENCES employees (group_id, id),
    CHECK (ends_before IS NULL OR ends_before>starts_on)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    position_revision_id BIGINT UNSIGNED NOT NULL,
    slot_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    effective_until DATE NULL,
    version INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_assignment_start (group_id, company_id, employment_id, effective_from),
    UNIQUE KEY uq_assignment_version (group_id, company_id, employment_id, version),
    UNIQUE KEY uq_assignment_slot (group_id, company_id, position_id, slot_id),
    KEY ix_assignment_position (group_id, company_id, position_id, effective_until, effective_from),
    FOREIGN KEY (group_id, company_id, employment_id) REFERENCES employments (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, position_id, position_revision_id)
        REFERENCES position_revisions (group_id, company_id, position_id, id),
    FOREIGN KEY (group_id, company_id, position_id, slot_id) REFERENCES headcount_slots (group_id, company_id, position_id, id),
    FOREIGN KEY (group_id, company_id, department_id) REFERENCES departments (group_id, company_id, id),
    CHECK (effective_until IS NULL OR effective_until>effective_from)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contract_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    reference VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    effective_from DATE NOT NULL,
    ends_before DATE NULL,
    monthly_minor BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_contract_version (group_id, company_id, employment_id, version),
    UNIQUE KEY uq_contract_reference (group_id, company_id, reference),
    KEY ix_contract_asof (group_id, company_id, employment_id, effective_from),
    FOREIGN KEY (group_id, company_id, employment_id) REFERENCES employments (group_id, company_id, id),
    CHECK (ends_before IS NULL OR ends_before>effective_from)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employment_intakes (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    offer_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, company_id, position_id, offer_id),
    UNIQUE KEY uq_intake_employment (group_id, company_id, employment_id),
    FOREIGN KEY (group_id, company_id, position_id, offer_id) REFERENCES offers (group_id, company_id, position_id, id),
    FOREIGN KEY (group_id, company_id, employment_id) REFERENCES employments (group_id, company_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS legal_transfers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    source_company_id BIGINT UNSIGNED NOT NULL,
    source_employment_id BIGINT UNSIGNED NOT NULL,
    source_version INT UNSIGNED NOT NULL,
    target_company_id BIGINT UNSIGNED NOT NULL,
    target_position_id BIGINT UNSIGNED NOT NULL,
    target_employment_id BIGINT UNSIGNED NULL,
    effective_on DATE NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    accepted_by BIGINT UNSIGNED NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decision_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
    UNIQUE KEY uq_legal_decision (group_id, target_company_id, decision_ref),
    KEY ix_legal_intake (group_id, target_company_id, state, id),
    FOREIGN KEY (group_id, source_company_id, source_employment_id) REFERENCES employments (group_id, company_id, id),
    FOREIGN KEY (group_id, target_company_id, target_position_id) REFERENCES positions (group_id, company_id, id),
    FOREIGN KEY (group_id, target_company_id, target_employment_id) REFERENCES employments (group_id, company_id, id),
    FOREIGN KEY (group_id, requested_by) REFERENCES accounts (group_id, id),
    FOREIGN KEY (group_id, accepted_by) REFERENCES accounts (group_id, id),
    CHECK (source_company_id<>target_company_id),
    CHECK ((state='pending' AND accepted_by IS NULL AND target_employment_id IS NULL AND decision_ref IS NULL)
        OR (state='accepted' AND accepted_by IS NOT NULL AND accepted_by<>requested_by
            AND target_employment_id IS NOT NULL AND decision_ref IS NOT NULL))
) ENGINE=InnoDB;
