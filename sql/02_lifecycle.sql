-- =====================================================================
--  GigLink migration 02 — Job lifecycle (pay, rate, auto-complete, renew)
--  Run once after schema.sql has been loaded.
--  Idempotent-ish: uses DROP IF EXISTS where needed.
-- =====================================================================

USE giglink;

-- ---------- Schema changes ----------
ALTER TABLE applications
    MODIFY COLUMN status ENUM('pending','accepted','completed','rejected','withdrawn') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS paid_at             TIMESTAMP NULL AFTER decided_at,
    ADD COLUMN IF NOT EXISTS employer_rated_at   TIMESTAMP NULL AFTER paid_at,
    ADD COLUMN IF NOT EXISTS youth_rated_at      TIMESTAMP NULL AFTER employer_rated_at,
    ADD COLUMN IF NOT EXISTS completed_at        TIMESTAMP NULL AFTER youth_rated_at;

ALTER TABLE jobs
    MODIFY COLUMN status ENUM('open','filled','completed','closed','cancelled') NOT NULL DEFAULT 'open',
    ADD COLUMN IF NOT EXISTS last_renewed_at     TIMESTAMP NULL AFTER created_at;

-- ---------- Rebuild triggers ----------
DROP TRIGGER IF EXISTS trg_fill_job_when_full;
DROP TRIGGER IF EXISTS trg_auto_complete_application;
DROP TRIGGER IF EXISTS trg_auto_complete_job;
DROP TRIGGER IF EXISTS trg_stamp_decided_at;

DELIMITER $$

-- Only ACTIVE accepted apps count toward "filled"; completed ones from a prior
-- round don't block renewal.
CREATE TRIGGER trg_fill_job_when_full
AFTER UPDATE ON applications
FOR EACH ROW
BEGIN
    DECLARE v_needed    INT;
    DECLARE v_active    INT;
    IF NEW.status = 'accepted' AND OLD.status <> 'accepted' THEN
        SELECT workers_needed INTO v_needed FROM jobs WHERE id = NEW.job_id;
        SELECT COUNT(*) INTO v_active
          FROM applications
         WHERE job_id = NEW.job_id AND status = 'accepted';
        IF v_active >= v_needed THEN
            UPDATE jobs SET status = 'filled' WHERE id = NEW.job_id AND status = 'open';
        END IF;
    END IF;
END$$

-- Stamp decided_at automatically on accept/reject
CREATE TRIGGER trg_stamp_decided_at
BEFORE UPDATE ON applications
FOR EACH ROW
BEGIN
    IF NEW.status IN ('accepted','rejected') AND OLD.status = 'pending' THEN
        SET NEW.decided_at = NOW();
    END IF;

    -- Auto-complete the application when both paid + employer rated
    IF NEW.status = 'accepted'
       AND NEW.paid_at IS NOT NULL
       AND NEW.employer_rated_at IS NOT NULL THEN
        SET NEW.status = 'completed';
        SET NEW.completed_at = NOW();
    END IF;
END$$

-- When the LAST active accepted application finishes, the job finishes too
CREATE TRIGGER trg_auto_complete_job
AFTER UPDATE ON applications
FOR EACH ROW
BEGIN
    DECLARE v_remaining INT;
    IF NEW.status = 'completed' AND OLD.status <> 'completed' THEN
        SELECT COUNT(*) INTO v_remaining
          FROM applications
         WHERE job_id = NEW.job_id AND status = 'accepted';
        IF v_remaining = 0 THEN
            UPDATE jobs SET status = 'completed'
             WHERE id = NEW.job_id AND status IN ('open','filled');
        END IF;
    END IF;
END$$

DELIMITER ;

-- ---------- New stored procedures ----------
DROP PROCEDURE IF EXISTS sp_mark_paid;
DROP PROCEDURE IF EXISTS sp_renew_job;

DELIMITER $$

CREATE PROCEDURE sp_mark_paid (
    IN p_application_id INT,
    IN p_employer_id    INT,
    IN p_amount         DECIMAL(10,2),
    IN p_method         VARCHAR(40),
    IN p_reference      VARCHAR(80)
)
BEGIN
    DECLARE v_job_id   INT;
    DECLARE v_youth_id INT;
    DECLARE v_status   VARCHAR(20);
    DECLARE v_paid_at  TIMESTAMP;

    SELECT a.job_id, a.youth_id, a.status, a.paid_at
      INTO v_job_id, v_youth_id, v_status, v_paid_at
      FROM applications a
      JOIN jobs j ON j.id = a.job_id
     WHERE a.id = p_application_id AND j.employer_id = p_employer_id;

    IF v_job_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Application not found or not yours';
    ELSEIF v_status NOT IN ('accepted','completed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'You can only pay an accepted applicant';
    ELSEIF v_paid_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This worker has already been paid';
    END IF;

    INSERT INTO payments (job_id, employer_id, youth_id, amount, method, reference, status)
    VALUES (v_job_id, p_employer_id, v_youth_id, p_amount,
            IFNULL(p_method,'cash'), p_reference, 'released');

    UPDATE applications SET paid_at = NOW() WHERE id = p_application_id;
END$$

CREATE PROCEDURE sp_renew_job (
    IN p_job_id      INT,
    IN p_employer_id INT
)
BEGIN
    DECLARE v_status VARCHAR(20);
    SELECT status INTO v_status FROM jobs
     WHERE id = p_job_id AND employer_id = p_employer_id;
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Job not found or not yours';
    ELSEIF v_status NOT IN ('completed','closed','cancelled') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only completed or closed jobs can be renewed';
    END IF;
    UPDATE jobs
       SET status = 'open', last_renewed_at = NOW()
     WHERE id = p_job_id AND employer_id = p_employer_id;
END$$

DELIMITER ;

-- ---------- Views that need refreshing to pick up new columns ----------
CREATE OR REPLACE VIEW v_jobs_public AS
SELECT
    j.id, j.title, j.description, j.pay_amount, j.pay_type,
    j.workers_needed, j.start_date, j.end_date, j.status,
    j.uses_escrow, j.created_at, j.last_renewed_at,
    j.employer_id,
    ep.business_name,
    c.name AS category_name,
    l.name AS location_name,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS applicant_count,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id AND a.status='accepted')  AS accepted_count,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id AND a.status='completed') AS completed_count
FROM jobs j
LEFT JOIN employer_profiles ep ON ep.user_id = j.employer_id
LEFT JOIN categories c         ON c.id = j.category_id
LEFT JOIN locations  l         ON l.id = j.location_id;
