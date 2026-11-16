-- Apply after 04_platform.sql with application writes paused.
USE giglink;

-- Some installations created this table outside the checked-in migrations.
CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    label VARCHAR(60) NULL,
    expires_at TIMESTAMP NULL,
    last_used TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE jobs ADD COLUMN IF NOT EXISTS renewed_from_id INT NULL,
    ADD COLUMN IF NOT EXISTS creation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD UNIQUE INDEX IF NOT EXISTS uq_job_renewal (renewed_from_id),
    ADD UNIQUE INDEX IF NOT EXISTS uq_job_creation (employer_id, creation_key);
ALTER TABLE applications
    MODIFY status ENUM('pending','accepted','completed','rejected','withdrawn','cancelled','in_progress','disputed') NOT NULL DEFAULT 'pending',
    ADD COLUMN IF NOT EXISTS accepted_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS state_version INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS started_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS completed_by INT NULL,
    ADD INDEX IF NOT EXISTS idx_application_job_state (job_id, status),
    ADD UNIQUE INDEX IF NOT EXISTS uq_application_participants (id, job_id, youth_id),
    ADD FOREIGN KEY IF NOT EXISTS fk_completed_by (completed_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE applications SET accepted_at = COALESCE(decided_at, applied_at)
WHERE accepted_at IS NULL AND status IN ('accepted','in_progress','completed','disputed');

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS application_id INT NULL,
    ADD COLUMN IF NOT EXISTS idempotency_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN IF NOT EXISTS receipt_status ENUM('recorded','confirmed','disputed','provider_confirmed') NULL,
    ADD COLUMN IF NOT EXISTS receipt_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS dispute_reason VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS new_payment_application_id INT
        AS (IF(idempotency_key IS NULL, NULL, application_id)) PERSISTENT,
    ADD UNIQUE INDEX IF NOT EXISTS uq_payment_request (employer_id, idempotency_key),
    ADD UNIQUE INDEX IF NOT EXISTS uq_payment_per_application (new_payment_application_id),
    ADD INDEX IF NOT EXISTS idx_payment_application (application_id, id),
    ADD CONSTRAINT fk_payment_application FOREIGN KEY IF NOT EXISTS (application_id)
        REFERENCES applications(id) ON DELETE CASCADE,
    ADD FOREIGN KEY IF NOT EXISTS fk_payment_participants (application_id,job_id,youth_id)
        REFERENCES applications(id,job_id,youth_id) ON DELETE CASCADE,
    ADD CONSTRAINT IF NOT EXISTS chk_new_payment CHECK
        (idempotency_key IS NULL OR (application_id IS NOT NULL AND amount > 0 AND receipt_status IS NOT NULL));

-- Historical inconsistencies are retained for review. Link only exact participant matches.
UPDATE payments p
JOIN applications a ON a.job_id=p.job_id AND a.youth_id=p.youth_id
JOIN jobs j ON j.id=a.job_id AND j.employer_id=p.employer_id
SET p.application_id=a.id,
    p.updated_at=p.updated_at,
    p.receipt_status=CASE p.status WHEN 'released' THEN 'recorded' WHEN 'disputed' THEN 'disputed' ELSE NULL END
WHERE p.application_id IS NULL;

DROP TRIGGER IF EXISTS trg_fill_job_when_full;
DROP TRIGGER IF EXISTS trg_stamp_decided_at;
DROP TRIGGER IF EXISTS trg_auto_complete_job;
DROP PROCEDURE IF EXISTS sp_apply_to_job;
DROP PROCEDURE IF EXISTS sp_decide_application;
DROP PROCEDURE IF EXISTS sp_mark_paid;
DROP PROCEDURE IF EXISTS sp_close_job;
DROP PROCEDURE IF EXISTS sp_renew_job;


CREATE OR REPLACE VIEW v_worker_summary AS
SELECT
    u.id AS user_id,
    u.email, u.phone, u.photo_path,
    yp.full_name, yp.bio, yp.education, yp.date_of_birth,
    l.name AS location_name,
    (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ')
       FROM youth_skills ys JOIN skills s ON s.id = ys.skill_id
       WHERE ys.youth_id = u.id) AS skills,
    (SELECT ROUND(AVG(r.score),2) FROM ratings r WHERE r.ratee_user_id = u.id AND EXISTS (SELECT 1 FROM applications a JOIN jobs j ON j.id=a.job_id WHERE a.job_id=r.job_id AND a.youth_id=u.id AND a.status='completed' AND j.employer_id=r.rater_user_id)) AS avg_rating,
    (SELECT COUNT(*) FROM ratings r WHERE r.ratee_user_id = u.id AND EXISTS (SELECT 1 FROM applications a JOIN jobs j ON j.id=a.job_id WHERE a.job_id=r.job_id AND a.youth_id=u.id AND a.status='completed' AND j.employer_id=r.rater_user_id)) AS rating_count,
    (SELECT COUNT(*) FROM applications a WHERE a.youth_id = u.id AND a.status='completed') AS jobs_completed,
    (SELECT COUNT(*) FROM endorsements e WHERE e.endorsee_user_id = u.id) AS endorsement_count,
    CASE
      WHEN (SELECT COUNT(*) FROM applications a WHERE a.youth_id = u.id AND a.status='completed') >= 5
       AND IFNULL((SELECT AVG(r.score) FROM ratings r WHERE r.ratee_user_id = u.id AND EXISTS (SELECT 1 FROM applications a JOIN jobs j ON j.id=a.job_id WHERE a.job_id=r.job_id AND a.youth_id=u.id AND a.status='completed' AND j.employer_id=r.rater_user_id)), 0) >= 4
      THEN 1 ELSE 0
    END AS verified_badge
FROM users u
JOIN youth_profiles yp ON yp.user_id = u.id
LEFT JOIN locations l ON l.id = yp.location_id
WHERE u.role = 'youth' AND u.active = 1 AND u.deleted_at IS NULL;

CREATE OR REPLACE VIEW v_jobs_public AS
SELECT
    j.id, j.title, j.description, j.pay_amount, j.pay_type,
    j.workers_needed, j.start_date, j.end_date, j.status,
    j.uses_escrow, j.created_at, j.last_renewed_at,
    j.employer_id, j.category_id, j.location_id,
    ep.business_name,
    c.name AS category_name,
    l.name AS location_name,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id) AS applicant_count,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id AND a.status='accepted')  AS accepted_count,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id = j.id AND a.status='completed') AS completed_count,
    (SELECT COUNT(*) FROM applications a WHERE a.job_id=j.id
        AND a.status IN ('accepted','in_progress','completed','disputed')) AS occupied_count
FROM jobs j
JOIN users u                   ON u.id = j.employer_id
LEFT JOIN employer_profiles ep ON ep.user_id = j.employer_id
LEFT JOIN categories c         ON c.id = j.category_id
LEFT JOIN locations  l         ON l.id = j.location_id
WHERE u.active = 1 AND u.deleted_at IS NULL;

DROP TRIGGER IF EXISTS trg_rating_requires_shared_job;
DELIMITER $$
CREATE TRIGGER trg_rating_requires_shared_job
BEFORE INSERT ON ratings FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM applications a JOIN jobs j ON j.id=a.job_id
        WHERE a.job_id=NEW.job_id AND a.status='completed'
        AND ((a.youth_id=NEW.rater_user_id AND j.employer_id=NEW.ratee_user_id)
          OR (a.youth_id=NEW.ratee_user_id AND j.employer_id=NEW.rater_user_id))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reviews require completed work';
    END IF;
END$$
DELIMITER ;
