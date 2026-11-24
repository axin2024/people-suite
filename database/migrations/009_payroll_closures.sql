CREATE TABLE IF NOT EXISTS payroll_review_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    trial_id BIGINT UNSIGNED NOT NULL,
    resulting_version INT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(14) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decision_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    notes VARCHAR(600) NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_payroll_review_decision (group_id, company_id, decision_ref),
    UNIQUE KEY uq_payroll_review_version (group_id, company_id, run_id, resulting_version),
    FOREIGN KEY (group_id, company_id, run_id) REFERENCES payroll_runs (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, trial_id) REFERENCES payroll_trials (group_id, company_id, id),
    FOREIGN KEY (group_id, actor_id) REFERENCES accounts (group_id, id),
    CHECK (action IN ('submit','return','close','publish'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payroll_closures (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    trial_id BIGINT UNSIGNED NOT NULL,
    closed_by BIGINT UNSIGNED NOT NULL,
    digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    closed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (group_id, company_id, run_id),
    UNIQUE KEY uq_payroll_closed_trial (group_id, company_id, trial_id),
    FOREIGN KEY (group_id, company_id, run_id) REFERENCES payroll_runs (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id, trial_id) REFERENCES payroll_trials (group_id, company_id, id),
    FOREIGN KEY (group_id, closed_by) REFERENCES accounts (group_id, id)
) ENGINE=InnoDB;
