CREATE TABLE IF NOT EXISTS requisitions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    proposed_start DATE NOT NULL,
    justification VARCHAR(600) NOT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_requisitions_scope (group_id, company_id, id),
    UNIQUE KEY uq_requisitions_position (group_id, company_id, position_id, id),
    KEY ix_requisitions_worklist (group_id, company_id, state, id),
    FOREIGN KEY (group_id, company_id, position_id) REFERENCES positions (group_id, company_id, id),
    CHECK (state IN ('pending', 'open', 'closed'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS candidates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    identity_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    UNIQUE KEY uq_candidates_identity (group_id, identity_ref),
    UNIQUE KEY uq_candidates_scope (group_id, id),
    FOREIGN KEY (group_id) REFERENCES hcm_groups (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    requisition_id BIGINT UNSIGNED NOT NULL,
    candidate_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_applications_candidate (group_id, company_id, requisition_id, candidate_id),
    UNIQUE KEY uq_applications_scope (group_id, company_id, position_id, id),
    KEY ix_applications_worklist (group_id, company_id, position_id, state, id),
    FOREIGN KEY (group_id, company_id, position_id, requisition_id)
        REFERENCES requisitions (group_id, company_id, position_id, id),
    FOREIGN KEY (group_id, candidate_id) REFERENCES candidates (group_id, id),
    CHECK (state IN ('submitted', 'interviewed', 'offered', 'accepted', 'hired', 'rejected'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS interview_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    notes VARCHAR(1200) NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (group_id, company_id, position_id, application_id)
        REFERENCES applications (group_id, company_id, position_id, id),
    FOREIGN KEY (group_id, author_id) REFERENCES accounts (group_id, id),
    CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS offers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    application_id BIGINT UNSIGNED NOT NULL,
    drafted_by BIGINT UNSIGNED NOT NULL,
    signed_by BIGINT UNSIGNED NULL,
    effective_on DATE NOT NULL,
    monthly_minor BIGINT UNSIGNED NOT NULL,
    state VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    decision_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
    UNIQUE KEY uq_offers_scope (group_id, company_id, position_id, id),
    KEY ix_offers_application (group_id, company_id, position_id, application_id, id),
    FOREIGN KEY (group_id, company_id, position_id, application_id)
        REFERENCES applications (group_id, company_id, position_id, id),
    FOREIGN KEY (group_id, drafted_by) REFERENCES accounts (group_id, id),
    FOREIGN KEY (group_id, signed_by) REFERENCES accounts (group_id, id),
    CHECK (state IN ('draft', 'signed', 'accepted', 'revoked', 'hired'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS offer_decisions (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    offer_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    decision_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    offer_version INT UNSIGNED NOT NULL,
    action VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    decided_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (group_id, company_id, decision_ref),
    UNIQUE KEY uq_offer_decision_version (group_id, company_id, position_id, offer_id, offer_version),
    FOREIGN KEY (group_id, company_id, position_id, offer_id)
        REFERENCES offers (group_id, company_id, position_id, id),
    FOREIGN KEY (group_id, actor_id) REFERENCES accounts (group_id, id),
    CHECK (action IN ('sign', 'accept', 'revoke'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS headcount_slots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    budget_id BIGINT UNSIGNED NOT NULL,
    offer_id BIGINT UNSIGNED NULL,
    starts_on DATE NOT NULL,
    ends_before DATE NOT NULL,
    released_at DATETIME(6) NULL,
    UNIQUE KEY uq_slots_offer (group_id, company_id, position_id, offer_id),
    UNIQUE KEY uq_slots_scope (group_id, company_id, position_id, id),
    KEY ix_slots_capacity (group_id, company_id, position_id, budget_id, released_at, starts_on, ends_before),
    FOREIGN KEY (group_id, company_id, position_id, budget_id)
        REFERENCES headcount_budgets (group_id, company_id, position_id, id),
    FOREIGN KEY (group_id, company_id, position_id, offer_id)
        REFERENCES offers (group_id, company_id, position_id, id),
    CHECK (ends_before > starts_on)
) ENGINE=InnoDB;
