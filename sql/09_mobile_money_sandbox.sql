-- Phase 9. Separate sandbox cases from real gig obligations. Preserve existing history.
USE giglink;
CREATE TABLE IF NOT EXISTS mobile_money_sandbox_cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    operation ENUM('collection','disbursement') NOT NULL,
    amount_minor INT UNSIGNED NOT NULL CHECK (amount_minor BETWEEN 1 AND 10000),
    wallet_binding CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;
ALTER TABLE payment_attempts MODIFY obligation_id INT NULL,
    ADD COLUMN IF NOT EXISTS sandbox_case_id INT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS uq_payment_sandbox_case (sandbox_case_id),
    ADD INDEX IF NOT EXISTS idx_attempt_provider_created (provider,created_at),
    ADD FOREIGN KEY IF NOT EXISTS fk_attempt_sandbox_case (sandbox_case_id) REFERENCES mobile_money_sandbox_cases(id),
    ADD CONSTRAINT IF NOT EXISTS chk_attempt_context
    CHECK ((obligation_id IS NOT NULL AND sandbox_case_id IS NULL) OR
           (obligation_id IS NULL AND sandbox_case_id IS NOT NULL));
CREATE TABLE IF NOT EXISTS mobile_money_health (
    provider VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    opened_until DATETIME(6) NULL,
    next_call_at DATETIME(6) NULL,
    last_category VARCHAR(40) NULL,
    checked_at DATETIME(6) NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO mobile_money_health (provider) VALUES ('mtn_collection'),('mtn_disbursement');


-- Serialize the short lease-claim scan, while jobs still execute independently.
CREATE TABLE IF NOT EXISTS payment_queue_claim_lock (id TINYINT PRIMARY KEY) ENGINE=InnoDB;
INSERT IGNORE INTO payment_queue_claim_lock VALUES (1);
