CREATE TABLE IF NOT EXISTS workforce_scenarios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    scenario_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    plan_year SMALLINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_workforce_scenario (group_id, company_id, plan_year, scenario_code, revision),
    UNIQUE KEY uq_workforce_scenario_scope (group_id, company_id, id),
    FOREIGN KEY (group_id, company_id) REFERENCES companies (group_id,id),
    FOREIGN KEY (group_id, created_by) REFERENCES accounts (group_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workforce_plan_entries (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    scenario_id BIGINT UNSIGNED NOT NULL,
    reference_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    ends_before DATE NOT NULL,
    fte_bp INT UNSIGNED NOT NULL,
    monthly_cost_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, company_id, scenario_id, reference_code),
    FOREIGN KEY (group_id, company_id, scenario_id) REFERENCES workforce_scenarios (group_id,company_id,id),
    FOREIGN KEY (group_id, company_id, department_id) REFERENCES departments (group_id,company_id,id),
    CHECK (ends_before>effective_from AND fte_bp BETWEEN 1 AND 10000)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workforce_plan_months (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    scenario_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    monthly_fte_bp BIGINT UNSIGNED NOT NULL,
    cost_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, company_id, scenario_id, department_id, period_start),
    FOREIGN KEY (group_id, company_id, scenario_id) REFERENCES workforce_scenarios (group_id,company_id,id),
    FOREIGN KEY (group_id, company_id, department_id) REFERENCES departments (group_id,company_id,id)
) ENGINE=InnoDB;
