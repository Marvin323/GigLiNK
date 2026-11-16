-- Apply after 06 with writes paused. See FINANCIAL_GOVERNANCE.md.
USE giglink;
CREATE TABLE IF NOT EXISTS staff_governance (
    id TINYINT PRIMARY KEY
) ENGINE=InnoDB;
INSERT IGNORE INTO staff_governance VALUES (1);

CREATE TABLE IF NOT EXISTS staff_roles (
    user_id INT PRIMARY KEY,
    role ENUM('super_admin','dispute_officer','finance_officer','auditor','support_officer') NOT NULL,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- Seed exactly once. Later reruns must never restore revoked permissions.
INSERT INTO staff_roles (user_id,role,enabled)
SELECT id,'super_admin',active AND deleted_at IS NULL FROM users
WHERE role='admin' AND NOT EXISTS (SELECT 1 FROM staff_governance WHERE id=2)
ON DUPLICATE KEY UPDATE user_id=staff_roles.user_id;
INSERT IGNORE INTO staff_governance VALUES (2);
ALTER TABLE disputes ADD COLUMN IF NOT EXISTS assigned_to INT NULL,
    ADD FOREIGN KEY IF NOT EXISTS fk_dispute_assigned (assigned_to) REFERENCES staff_roles(user_id);

CREATE TABLE IF NOT EXISTS effective_reconciliations (
    payment_id INT PRIMARY KEY,
    application_id INT NULL UNIQUE,
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (payment_id) REFERENCES payment_reconciliations(payment_id),
    FOREIGN KEY (application_id) REFERENCES applications(id)
) ENGINE=InnoDB;
INSERT INTO effective_reconciliations (payment_id,application_id)
SELECT r.payment_id,r.application_id FROM payment_reconciliations r
LEFT JOIN effective_reconciliations e ON e.payment_id=r.payment_id WHERE e.payment_id IS NULL;
-- The projection enforces uniqueness for the current association.
CREATE INDEX IF NOT EXISTS idx_original_recon_application ON payment_reconciliations(application_id);
ALTER TABLE payment_reconciliations DROP INDEX IF EXISTS application_id;
CREATE TABLE IF NOT EXISTS reconciliation_corrections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payment_id INT NOT NULL,
    original_revision INT UNSIGNED NOT NULL,
    from_application INT NULL,
    to_application INT NULL,
    requester INT NOT NULL,
    reason VARCHAR(1500) NOT NULL,
    pending BOOLEAN NOT NULL DEFAULT TRUE,
    open_payment INT AS (IF(pending,payment_id,NULL)) PERSISTENT UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES effective_reconciliations(payment_id),
    FOREIGN KEY (from_application) REFERENCES applications(id),
    FOREIGN KEY (to_application) REFERENCES applications(id),
    FOREIGN KEY (requester) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS correction_decisions (
    correction_id INT PRIMARY KEY,
    requester INT NOT NULL,
    reviewer INT NOT NULL,
    approved BOOLEAN NOT NULL,
    reason VARCHAR(1500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (requester <> reviewer),
    FOREIGN KEY (correction_id) REFERENCES reconciliation_corrections(id),
    FOREIGN KEY (requester) REFERENCES users(id),
    FOREIGN KEY (reviewer) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE OR REPLACE VIEW v_application_payments AS
SELECT p.id, p.job_id AS original_job_id,a.job_id,a.id AS application_id,p.employer_id,p.youth_id,
       p.amount,p.method,p.reference,p.status,p.created_at,p.receipt_at,p.dispute_reason,
       CASE WHEN e.application_id IS NOT NULL AND p.status='released' THEN 'recorded'
            WHEN e.application_id IS NOT NULL AND p.status='disputed' THEN 'disputed'
            ELSE p.receipt_status END AS receipt_status,
       r.created_at AS reconciled_at
FROM payments p LEFT JOIN effective_reconciliations e ON e.payment_id=p.id
LEFT JOIN payment_reconciliations r ON r.payment_id=e.payment_id
JOIN applications a ON a.id=COALESCE(p.application_id,e.application_id);

CREATE TABLE IF NOT EXISTS provider_transactions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reference VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_id INT NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL CHECK (amount_minor BETWEEN 1 AND 1000000000000),
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'requested',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (provider,reference),
    UNIQUE (actor_id,provider,request_key),
    INDEX (status,id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS provider_events (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reference VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_type VARCHAR(24) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    status ENUM('pending','applied','ignored','failed') NOT NULL DEFAULT 'pending',
    outcome VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (provider,event_key),
    INDEX (provider,reference,status,id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS settlement_ledger (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    transaction_id BIGINT NOT NULL,
    event_id BIGINT NULL UNIQUE,
    event_type VARCHAR(24) NOT NULL,
    amount_minor BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (transaction_id,event_type),
    FOREIGN KEY (transaction_id) REFERENCES provider_transactions(id),
    FOREIGN KEY (event_id) REFERENCES provider_events(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS webhook_limits (
    source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    next_reset BIGINT NOT NULL,
    hits INT UNSIGNED NOT NULL,
    INDEX (next_reset)
) ENGINE=InnoDB;

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS ledger_no_update BEFORE UPDATE ON settlement_ledger FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Settlement history is append-only'; END$$
CREATE TRIGGER IF NOT EXISTS ledger_no_delete BEFORE DELETE ON settlement_ledger FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Settlement history is append-only'; END$$
CREATE TRIGGER IF NOT EXISTS correction_no_update BEFORE UPDATE ON correction_decisions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction decisions are append-only'; END$$
CREATE TRIGGER IF NOT EXISTS correction_no_delete BEFORE DELETE ON correction_decisions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction decisions are append-only'; END$$
CREATE TRIGGER IF NOT EXISTS original_recon_no_update BEFORE UPDATE ON payment_reconciliations FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Original reconciliation is append-only'; END$$
CREATE TRIGGER IF NOT EXISTS original_recon_no_delete BEFORE DELETE ON payment_reconciliations FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Original reconciliation is append-only'; END$$
DELIMITER ;

