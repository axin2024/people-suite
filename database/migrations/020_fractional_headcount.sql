CREATE TABLE IF NOT EXISTS headcount_slot_workloads (
    slot_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    position_id BIGINT UNSIGNED NOT NULL,
    fte_bp INT UNSIGNED NOT NULL,
    FOREIGN KEY (group_id,company_id,position_id,slot_id) REFERENCES headcount_slots(group_id,company_id,position_id,id),
    CHECK (fte_bp BETWEEN 1 AND 10000)
) ENGINE=InnoDB;
