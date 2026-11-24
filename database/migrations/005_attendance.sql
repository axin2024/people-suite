CREATE TABLE IF NOT EXISTS reporting_lines (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    manager_account_id BIGINT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    effective_until DATE NULL,
    PRIMARY KEY (group_id, company_id, employment_id, effective_from),
    KEY ix_reporting_manager (group_id, company_id, manager_account_id, effective_from, effective_until),
    FOREIGN KEY (group_id, company_id, employment_id) REFERENCES employments (group_id, company_id, id),
    FOREIGN KEY (group_id, manager_account_id) REFERENCES accounts (group_id, id),
    CHECK (effective_until IS NULL OR effective_until>effective_from)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shifts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    workday DATE NOT NULL,
    starts_at BIGINT NOT NULL,
    ends_at BIGINT NOT NULL,
    state VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'scheduled',
    UNIQUE KEY uq_shift_scope (group_id, company_id, id),
    UNIQUE KEY uq_shift_identity (group_id, company_id, employment_id, id),
    KEY ix_shift_roster (group_id, company_id, employment_id, workday, id),
    KEY ix_shift_company_day (group_id, company_id, workday, id),
    FOREIGN KEY (group_id, company_id, employment_id) REFERENCES employments (group_id, company_id, id),
    CHECK (ends_at>starts_at AND ends_at-starts_at<=86400),
    CHECK (state IN ('scheduled','cancelled'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS punch_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    shift_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    direction VARCHAR(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    punched_at BIGINT NOT NULL,
    digest CHAR(64) CHARACTER SET ascii NOT NULL,
    UNIQUE KEY uq_punch_event (group_id, company_id, source, event_ref),
    KEY ix_punch_shift (group_id, company_id, shift_id, punched_at, id),
    FOREIGN KEY (group_id, company_id, employment_id, shift_id)
        REFERENCES shifts (group_id, company_id, employment_id, id),
    CHECK (direction IN ('in','out'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_watermarks (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (group_id, company_id),
    FOREIGN KEY (group_id, company_id) REFERENCES companies (group_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance_versions (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    watermark BIGINT UNSIGNED NOT NULL,
    shift_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    state VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    worked_minutes SMALLINT UNSIGNED NOT NULL,
    decision_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
    explanation VARCHAR(600) NULL,
    evidence JSON NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (group_id, company_id, watermark),
    UNIQUE KEY uq_attendance_version (group_id, company_id, shift_id, version),
    UNIQUE KEY uq_attendance_decision (group_id, company_id, decision_ref),
    FOREIGN KEY (group_id, company_id, shift_id) REFERENCES shifts (group_id, company_id, id),
    FOREIGN KEY (group_id, actor_id) REFERENCES accounts (group_id, id),
    CHECK (state IN ('scheduled','ready','anomaly','worked','absent','excused','cancelled')),
    CHECK (worked_minutes<=1440)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS leave_balances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    leave_kind VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    leave_year SMALLINT UNSIGNED NOT NULL,
    granted_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    reserved_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    used_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_leave_balance (group_id, company_id, employment_id, leave_kind, leave_year),
    UNIQUE KEY uq_leave_balance_scope (group_id, company_id, employment_id, id),
    FOREIGN KEY (group_id, company_id, employment_id) REFERENCES employments (group_id, company_id, id),
    CHECK (reserved_minutes+used_minutes<=granted_minutes)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS leave_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    balance_id BIGINT UNSIGNED NOT NULL,
    workday DATE NOT NULL,
    minutes SMALLINT UNSIGNED NOT NULL,
    state VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reason VARCHAR(600) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    submitted_by BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_leave_request_balance (group_id, company_id, employment_id, balance_id, id),
    KEY ix_leave_day (group_id, company_id, employment_id, workday, state, id),
    FOREIGN KEY (group_id, company_id, employment_id, balance_id)
        REFERENCES leave_balances (group_id, company_id, employment_id, id),
    FOREIGN KEY (group_id, submitted_by) REFERENCES accounts (group_id, id),
    CHECK (minutes BETWEEN 1 AND 1440),
    CHECK (state IN ('pending','approved','denied','cancelled'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS leave_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    balance_id BIGINT UNSIGNED NOT NULL,
    request_id BIGINT UNSIGNED NULL,
    version INT UNSIGNED NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    granted_change INT NOT NULL DEFAULT 0,
    reserved_change INT NOT NULL DEFAULT 0,
    used_change INT NOT NULL DEFAULT 0,
    decision_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_leave_ledger_decision (group_id, company_id, decision_ref),
    UNIQUE KEY uq_leave_request_version (group_id, company_id, employment_id, balance_id, request_id, version),
    FOREIGN KEY (group_id, company_id, employment_id, balance_id)
        REFERENCES leave_balances (group_id, company_id, employment_id, id),
    FOREIGN KEY (group_id, company_id, employment_id, balance_id, request_id)
        REFERENCES leave_requests (group_id, company_id, employment_id, balance_id, id),
    FOREIGN KEY (group_id, actor_id) REFERENCES accounts (group_id, id),
    CHECK (action IN ('grant','reserve','approve','deny','cancel'))
) ENGINE=InnoDB;
