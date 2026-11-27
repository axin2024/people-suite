CREATE TABLE IF NOT EXISTS workforce_rollouts (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    state ENUM('setup','active','draining') NOT NULL DEFAULT 'setup',
    last_revision_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (group_id,company_id),
    FOREIGN KEY (group_id,company_id) REFERENCES companies(group_id,id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workforce_position_baselines (
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    revision_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    effective_from DATE NOT NULL,
    effective_until DATE NULL,
    title VARCHAR(160) NOT NULL,
    active BOOLEAN NOT NULL,
    PRIMARY KEY (group_id,company_id,revision_id),
    FOREIGN KEY (group_id,company_id,position_id,revision_id) REFERENCES position_revisions(group_id,company_id,position_id,id)
) ENGINE=InnoDB;

INSERT INTO workforce_rollouts (group_id,company_id) SELECT group_id,id FROM companies
ON DUPLICATE KEY UPDATE last_revision_id=workforce_rollouts.last_revision_id;
