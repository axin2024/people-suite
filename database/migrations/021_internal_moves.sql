CREATE TABLE IF NOT EXISTS internal_moves (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    opening_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    source_company_id BIGINT UNSIGNED NOT NULL,
    source_employment_id BIGINT UNSIGNED NOT NULL,
    source_assignment_id BIGINT UNSIGNED NOT NULL,
    source_version INT UNSIGNED NOT NULL,
    from_position BIGINT UNSIGNED NOT NULL,
    to_position BIGINT UNSIGNED NOT NULL,
    source_department BIGINT UNSIGNED NOT NULL,
    target_department BIGINT UNSIGNED NOT NULL,
    effective_date DATE NOT NULL,
    offer_rev INT UNSIGNED NOT NULL,
    slot_id BIGINT UNSIGNED NOT NULL,
    reserved_fte INT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    state ENUM('awaiting_signatures','scheduled','withdrawn','effective') NOT NULL DEFAULT 'awaiting_signatures',
    result JSON NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_move_scope (group_id,company_id,id),
    UNIQUE KEY uq_move_application_revision (application_id,offer_rev),
    UNIQUE KEY uq_move_slot (slot_id),
    KEY ix_move_due (group_id,company_id,state,effective_date,id),
    KEY ix_move_employee (group_id,employee_id,state),
    FOREIGN KEY (group_id,company_id,application_id) REFERENCES internal_applications(group_id,company_id,id),
    FOREIGN KEY (group_id,company_id,opening_id) REFERENCES internal_openings(group_id,company_id,id),
    FOREIGN KEY (group_id,source_company_id,source_employment_id) REFERENCES employments(group_id,company_id,id),
    FOREIGN KEY (group_id,employee_id) REFERENCES employees(group_id,id),
    FOREIGN KEY (group_id,company_id,to_position,slot_id) REFERENCES headcount_slots(group_id,company_id,position_id,id),
    FOREIGN KEY (group_id,created_by) REFERENCES accounts(group_id,id),
    FOREIGN KEY (source_assignment_id) REFERENCES assignments(id),
    CHECK (from_position<>to_position AND reserved_fte BETWEEN 1 AND 10000)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS internal_move_signatures (
    move_id BIGINT UNSIGNED NOT NULL,
    side ENUM('source_manager','target_manager') NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    notes VARCHAR(400) NOT NULL,
    signed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(move_id,side),
    UNIQUE KEY uq_internal_move_signers(move_id,actor_id),
    FOREIGN KEY (move_id) REFERENCES internal_moves(id),
    FOREIGN KEY (actor_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS internal_move_handover (
    move_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    owner_id BIGINT UNSIGNED NOT NULL,
    checklist VARCHAR(1200) NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    completed_at DATETIME(6) NULL,
    FOREIGN KEY (move_id) REFERENCES internal_moves(id),
    FOREIGN KEY (owner_id) REFERENCES accounts(id),
    FOREIGN KEY (assigned_by) REFERENCES accounts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS internal_move_contracts (
    move_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    reference VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    monthly_minor BIGINT UNSIGNED NOT NULL,
    ends_before DATE NULL,
    confirmed_by BIGINT UNSIGNED NOT NULL,
    confirmed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (move_id) REFERENCES internal_moves(id),
    FOREIGN KEY (confirmed_by) REFERENCES accounts(id)
) ENGINE=InnoDB;
