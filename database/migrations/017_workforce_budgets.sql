CREATE TABLE IF NOT EXISTS workforce_department_managers (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    manager_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id,company_id,department_id,manager_id),
    FOREIGN KEY (group_id,company_id,department_id) REFERENCES departments (group_id,company_id,id),
    FOREIGN KEY (group_id,manager_id) REFERENCES accounts (group_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workforce_budget_limits (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    revision INT UNSIGNED NOT NULL,
    capacity_bp INT UNSIGNED NOT NULL,
    cost_cap_minor BIGINT UNSIGNED NOT NULL,
    used_bp INT UNSIGNED NOT NULL DEFAULT 0,
    used_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (group_id,company_id,department_id,period_start),
    FOREIGN KEY (group_id,company_id,department_id) REFERENCES departments (group_id,company_id,id),
    CHECK (DAYOFMONTH(period_start)=1 AND used_bp<=capacity_bp AND used_minor<=cost_cap_minor)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workforce_budget_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    start_month DATE NOT NULL,
    months TINYINT UNSIGNED NOT NULL,
    fte_bp INT UNSIGNED NOT NULL,
    monthly_minor BIGINT UNSIGNED NOT NULL,
    grade VARCHAR(48) NOT NULL,
    state ENUM('submitted','reviewed','adjustment','approved') NOT NULL,
    revision INT UNSIGNED NOT NULL,
    submitted_by BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_budget_request_scope (group_id,company_id,id),
    KEY ix_budget_request_department (group_id,company_id,department_id,id),
    FOREIGN KEY (group_id,company_id,department_id) REFERENCES departments (group_id,company_id,id),
    FOREIGN KEY (group_id,company_id,position_id) REFERENCES positions (group_id,company_id,id),
    FOREIGN KEY (group_id,submitted_by) REFERENCES accounts (group_id,id),
    CHECK (months BETWEEN 1 AND 12 AND fte_bp BETWEEN 1 AND 10000 AND DAYOFMONTH(start_month)=1)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workforce_budget_history (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    snapshot JSON NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(group_id,company_id,request_id,revision),
    FOREIGN KEY (group_id,company_id,request_id) REFERENCES workforce_budget_requests (group_id,company_id,id),
    FOREIGN KEY (group_id,actor_id) REFERENCES accounts (group_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workforce_budget_reservations (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    request_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    fte_bp INT UNSIGNED NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id,company_id,request_id,period_start),
    FOREIGN KEY (group_id,company_id,request_id) REFERENCES workforce_budget_requests (group_id,company_id,id),
    FOREIGN KEY (group_id,company_id,department_id,period_start) REFERENCES workforce_budget_limits (group_id,company_id,department_id,period_start)
) ENGINE=InnoDB;
