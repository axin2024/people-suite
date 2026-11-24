CREATE TABLE IF NOT EXISTS payslip_inquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    payslip_id BIGINT UNSIGNED NOT NULL,
    opened_by BIGINT UNSIGNED NOT NULL,
    reference VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    category VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    question VARCHAR(1200) NOT NULL,
    state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',
    version TINYINT UNSIGNED NOT NULL DEFAULT 1,
    opened_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    answered_by BIGINT UNSIGNED NULL,
    decision_ref VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
    answer VARCHAR(1200) NULL,
    answered_at DATETIME(6) NULL,
    acknowledged_at DATETIME(6) NULL,
    UNIQUE KEY uq_payslip_inquiry_reference (group_id, company_id, payslip_id, opened_by, reference),
    KEY ix_payslip_inquiries_queue (group_id, company_id, state, id),
    KEY ix_payslip_inquiries_read (group_id, company_id, payslip_id, opened_by, id),
    FOREIGN KEY (group_id, company_id, payslip_id) REFERENCES payslips (group_id, company_id, id),
    FOREIGN KEY (group_id, opened_by) REFERENCES accounts (group_id, id),
    FOREIGN KEY (group_id, answered_by) REFERENCES accounts (group_id, id),
    CHECK (category IN ('base_pay','attendance','withholding','adjustment','other')),
    CHECK (answered_by IS NULL OR answered_by<>opened_by),
    CHECK (
        (state='open' AND version=1 AND answered_by IS NULL AND decision_ref IS NULL AND answer IS NULL AND answered_at IS NULL AND acknowledged_at IS NULL)
        OR (state='answered' AND version=2 AND answered_by IS NOT NULL AND decision_ref IS NOT NULL AND answer IS NOT NULL AND answered_at IS NOT NULL AND acknowledged_at IS NULL)
        OR (state='acknowledged' AND version=3 AND answered_by IS NOT NULL AND decision_ref IS NOT NULL AND answer IS NOT NULL AND answered_at IS NOT NULL AND acknowledged_at IS NOT NULL)
    )
) ENGINE=InnoDB;
