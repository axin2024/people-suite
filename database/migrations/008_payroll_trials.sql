CREATE TABLE IF NOT EXISTS pay_rule_sequences (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    latest_revision INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, company_id),
    FOREIGN KEY (group_id, company_id) REFERENCES companies (group_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pay_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    rule_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(160) NOT NULL,
    proration_basis VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    monthly_allowance_minor BIGINT UNSIGNED NOT NULL,
    absence_hourly_minor BIGINT UNSIGNED NOT NULL,
    withholding_bps SMALLINT UNSIGNED NOT NULL,
    withholding_exempt_minor BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_pay_rule_rev (group_id, company_id, revision),
    UNIQUE KEY uq_pay_rule_ref (group_id, company_id, rule_ref),
    UNIQUE KEY uq_pay_rule_scope (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id) REFERENCES companies (group_id, id),
    FOREIGN KEY (group_id, created_by) REFERENCES accounts (group_id, id),
    CHECK (proration_basis IN ('actual_days','fixed_30')),
    CHECK (withholding_bps<=10000)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payroll_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    trial_revision INT UNSIGNED NOT NULL DEFAULT 0,
    state VARCHAR(14) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_payroll_month (group_id, company_id, period_start),
    UNIQUE KEY uq_payroll_run_scope (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id) REFERENCES companies (group_id, id),
    FOREIGN KEY (group_id, created_by) REFERENCES accounts (group_id, id),
    CHECK (period_end>period_start),
    CHECK (state IN ('draft','calculated','in_review','closed','published'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payroll_trials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    rule_id BIGINT UNSIGNED NOT NULL,
    attendance_watermark BIGINT UNSIGNED NOT NULL,
    calculated_by BIGINT UNSIGNED NOT NULL,
    eligible_count INT UNSIGNED NOT NULL DEFAULT 0,
    blocked_count INT UNSIGNED NOT NULL DEFAULT 0,
    gross_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    net_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    cost_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    calculated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_payroll_trial_revision (group_id, company_id, run_id, revision),
    UNIQUE KEY uq_payroll_trial_scope (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, run_id) REFERENCES payroll_runs (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, rule_id) REFERENCES pay_rules (group_id, company_id, id),
    FOREIGN KEY (group_id, calculated_by) REFERENCES accounts (group_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payslips (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    trial_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    attendance_certification_id BIGINT UNSIGNED NULL,
    employee_code VARCHAR(48) CHARACTER SET ascii NOT NULL,
    employee_name VARCHAR(160) NOT NULL,
    state VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    gross_minor BIGINT UNSIGNED NULL,
    absence_minor BIGINT UNSIGNED NULL,
    withholding_minor BIGINT UNSIGNED NULL,
    net_minor BIGINT UNSIGNED NULL,
    cost_minor BIGINT UNSIGNED NULL,
    facts JSON NOT NULL,
    UNIQUE KEY uq_payslip_trial_person (group_id, company_id, trial_id, employment_id),
    UNIQUE KEY uq_payslip_scope (group_id, company_id, id),
    KEY ix_payslip_history (group_id, company_id, employment_id, id),
    FOREIGN KEY (group_id, company_id, trial_id) REFERENCES payroll_trials (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, employment_id) REFERENCES employments (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, employment_id, attendance_certification_id)
        REFERENCES attendance_certifications (group_id, company_id, employment_id, id),
    CHECK ((state='blocked' AND net_minor IS NULL) OR (state='ready' AND net_minor IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payslip_segments (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    payslip_id BIGINT UNSIGNED NOT NULL,
    line_no SMALLINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    starts_on DATE NOT NULL,
    ends_before DATE NOT NULL,
    base_minor BIGINT UNSIGNED NOT NULL,
    allowance_minor BIGINT UNSIGNED NOT NULL,
    absence_minor BIGINT UNSIGNED NOT NULL,
    cost_minor BIGINT UNSIGNED NOT NULL,
    facts JSON NOT NULL,
    PRIMARY KEY (group_id, company_id, payslip_id, line_no),
    KEY ix_payslip_cost_department (group_id, company_id, department_id, payslip_id),
    FOREIGN KEY (group_id, company_id, payslip_id) REFERENCES payslips (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, department_id) REFERENCES departments (group_id, company_id, id),
    CHECK (ends_before>starts_on)
) ENGINE=InnoDB;
