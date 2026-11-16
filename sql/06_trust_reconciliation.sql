-- Apply after migration 05, with application writes paused.
USE giglink;

CREATE TABLE IF NOT EXISTS payment_reconciliations (
    payment_id INT PRIMARY KEY,
    application_id INT NOT NULL UNIQUE,
    actor_id INT NOT NULL,
    reason VARCHAR(1500) NOT NULL,
    match_score SMALLINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_id) REFERENCES payments(id),
    FOREIGN KEY (application_id) REFERENCES applications(id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE OR REPLACE VIEW v_application_payments AS
SELECT p.id, p.job_id AS original_job_id, a.job_id, COALESCE(p.application_id,r.application_id) AS application_id,
       p.employer_id,p.youth_id,p.amount,p.method,p.reference,p.status,p.created_at,p.receipt_at,p.dispute_reason,
       CASE WHEN r.payment_id IS NOT NULL AND p.status='released' THEN 'recorded'
            WHEN r.payment_id IS NOT NULL AND p.status='disputed' THEN 'disputed'
            ELSE p.receipt_status END AS receipt_status,
       r.created_at AS reconciled_at
FROM payments p LEFT JOIN payment_reconciliations r ON r.payment_id=p.id
JOIN applications a ON a.id=COALESCE(p.application_id,r.application_id);

CREATE TABLE IF NOT EXISTS disputes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL,
    payment_id INT NULL UNIQUE,
    opened_by INT NULL,
    initial_reason VARCHAR(500) NULL,
    status ENUM('open','reviewing','resolved','rejected') NOT NULL DEFAULT 'open',
    revision INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    active_work_id INT AS (IF(payment_id IS NULL AND status IN ('open','reviewing'),application_id,NULL)) PERSISTENT,
    UNIQUE KEY uq_active_work_dispute (active_work_id),
    INDEX idx_dispute_status_created (status,created_at,id),
    FOREIGN KEY (application_id) REFERENCES applications(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id),
    FOREIGN KEY (opened_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dispute_decisions (
    dispute_id INT NOT NULL,
    revision INT UNSIGNED NOT NULL,
    actor_id INT NOT NULL,
    previous_status VARCHAR(12) NOT NULL,
    next_status VARCHAR(12) NOT NULL,
    reason VARCHAR(1500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (dispute_id,revision),
    FOREIGN KEY (dispute_id) REFERENCES disputes(id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dispute_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dispute_id INT NOT NULL,
    actor_id INT NOT NULL,
    party_id INT NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    statement VARCHAR(3000) NOT NULL,
    evidence_type ENUM('image/jpeg','image/png') NULL,
    evidence MEDIUMBLOB NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dispute_submission (dispute_id,actor_id,request_key),
    FOREIGN KEY (dispute_id) REFERENCES disputes(id),
    FOREIGN KEY (actor_id) REFERENCES users(id),
    FOREIGN KEY (party_id) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO disputes (application_id)
SELECT a.id FROM applications a
WHERE a.status='disputed' AND NOT EXISTS
    (SELECT 1 FROM disputes d WHERE d.application_id=a.id AND d.payment_id IS NULL AND d.status IN ('open','reviewing'));

INSERT INTO disputes (application_id,payment_id,initial_reason,created_at)
SELECT p.application_id,p.id,p.dispute_reason,COALESCE(p.receipt_at,p.created_at) FROM payments p
WHERE p.application_id IS NOT NULL AND p.receipt_status='disputed'
AND NOT EXISTS (SELECT 1 FROM disputes d WHERE d.payment_id=p.id);

DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_audit_no_update BEFORE UPDATE ON audit_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit events are append-only';
END$$
CREATE TRIGGER IF NOT EXISTS trg_audit_no_delete BEFORE DELETE ON audit_log FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit events are append-only';
END$$
DELIMITER ;

