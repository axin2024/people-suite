CREATE TABLE IF NOT EXISTS internal_openings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    budget_id BIGINT UNSIGNED NOT NULL,
    posted_by BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    description VARCHAR(800) NOT NULL,
    closes_on DATE NOT NULL,
    state ENUM('open','reserved','filled','closed') NOT NULL DEFAULT 'open',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_internal_opening_scope (group_id,company_id,id),
    UNIQUE KEY uq_internal_opening_budget (group_id,company_id,budget_id),
    FOREIGN KEY (group_id,company_id,department_id) REFERENCES departments (group_id,company_id,id),
    FOREIGN KEY (group_id,company_id,position_id) REFERENCES positions (group_id,company_id,id),
    FOREIGN KEY (group_id,company_id,budget_id) REFERENCES workforce_budget_requests (group_id,company_id,id),
    FOREIGN KEY (group_id,posted_by) REFERENCES accounts (group_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS internal_opening_skills (
    opening_id BIGINT UNSIGNED NOT NULL,
    skill VARCHAR(48) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    required_skill BOOLEAN NOT NULL,
    PRIMARY KEY(opening_id,skill),
    FOREIGN KEY(opening_id) REFERENCES internal_openings(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS internal_opening_panel (
    opening_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY(opening_id,account_id),
    FOREIGN KEY(group_id,company_id,opening_id) REFERENCES internal_openings(group_id,company_id,id),
    FOREIGN KEY(group_id,account_id) REFERENCES accounts(group_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS internal_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    opening_id BIGINT UNSIGNED NOT NULL,
    source_company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    motivation VARCHAR(800) NOT NULL,
    state ENUM('applied','reviewed','reserved','scheduled','transferred','withdrawn') NOT NULL DEFAULT 'applied',
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_internal_application (opening_id,employee_id),
    UNIQUE KEY uq_internal_application_scope (group_id,company_id,id),
    FOREIGN KEY (group_id,company_id,opening_id) REFERENCES internal_openings(group_id,company_id,id),
    FOREIGN KEY (group_id,source_company_id,employment_id) REFERENCES employments(group_id,company_id,id),
    FOREIGN KEY (group_id,employee_id) REFERENCES employees(group_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS internal_interviews (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    score TINYINT UNSIGNED NOT NULL,
    notes VARCHAR(600) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(application_id,reviewer_id),
    FOREIGN KEY(group_id,company_id,application_id) REFERENCES internal_applications(group_id,company_id,id),
    FOREIGN KEY(group_id,reviewer_id) REFERENCES accounts(group_id,id),
    CHECK (score BETWEEN 1 AND 100)
) ENGINE=InnoDB;
