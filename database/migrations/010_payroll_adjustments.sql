CREATE TABLE IF NOT EXISTS payroll_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    target_run_id BIGINT UNSIGNED NOT NULL,
    original_payslip_id BIGINT UNSIGNED NOT NULL,
    original_line_no SMALLINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    decision_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_minor BIGINT UNSIGNED NOT NULL,
    prior_minor BIGINT UNSIGNED NOT NULL,
    recalculated_minor BIGINT UNSIGNED NOT NULL,
    monthly_minor BIGINT UNSIGNED NOT NULL,
    gross_delta_minor BIGINT NOT NULL,
    withholding_delta_minor BIGINT NOT NULL,
    facts JSON NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_payroll_adjustment_decision (group_id, company_id, original_payslip_id, original_line_no, decision_ref),
    UNIQUE KEY uq_payroll_adjustment_scope (group_id, company_id, id),
    KEY ix_adjustments_target (group_id, company_id, target_run_id, employment_id, id),
    FOREIGN KEY (group_id, company_id, target_run_id) REFERENCES payroll_runs (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, original_payslip_id, original_line_no)
        REFERENCES payslip_segments (group_id, company_id, payslip_id, line_no),
    FOREIGN KEY (group_id, company_id, employment_id) REFERENCES employments (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, department_id) REFERENCES departments (group_id, company_id, id),
    FOREIGN KEY (group_id, recorded_by) REFERENCES accounts (group_id, id),
    CHECK (gross_delta_minor<>0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payroll_trial_adjustments (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    trial_id BIGINT UNSIGNED NOT NULL,
    adjustment_id BIGINT UNSIGNED NOT NULL,
    payslip_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, company_id, trial_id, adjustment_id),
    KEY ix_adjustment_slip (group_id, company_id, payslip_id, adjustment_id),
    FOREIGN KEY (group_id, company_id, trial_id) REFERENCES payroll_trials (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, adjustment_id) REFERENCES payroll_adjustments (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, payslip_id) REFERENCES payslips (group_id, company_id, id)
) ENGINE=InnoDB;
