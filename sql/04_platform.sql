-- =====================================================================
--  GigLink migration 04 — Platform build
--  Notifications, messages, reports, saved jobs, audit log, soft delete,
--  profile photos, recent views, password reset tokens.
-- =====================================================================
USE giglink;

-- ---------- Profile photos ----------
ALTER TABLE users ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NULL AFTER phone;

-- ---------- Soft delete / suspension reason ----------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL,
    ADD COLUMN IF NOT EXISTS suspended_reason VARCHAR(255) NULL;

-- ---------- Notifications ----------
CREATE TABLE IF NOT EXISTS notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    kind        VARCHAR(40) NOT NULL,
    title       VARCHAR(200) NOT NULL,
    url         VARCHAR(255),
    read_at     TIMESTAMP NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, read_at),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ---------- Messages (threaded by application) ----------
-- Rule: a conversation exists only between the two parties of an accepted or
-- completed application. We key the thread on application_id, so no separate
-- conversations table is needed.
CREATE TABLE IF NOT EXISTS messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    application_id  INT NOT NULL,
    sender_id       INT NOT NULL,
    body            TEXT NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at         TIMESTAMP NULL,
    FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id)      REFERENCES users(id)        ON DELETE CASCADE,
    INDEX idx_app_created (application_id, created_at)
) ENGINE=InnoDB;

-- ---------- Reports (user flags another user or a job) ----------
CREATE TABLE IF NOT EXISTS reports (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    reporter_user_id    INT NOT NULL,
    target_user_id      INT NULL,
    target_job_id       INT NULL,
    reason              VARCHAR(100) NOT NULL,
    details             TEXT,
    status              ENUM('open','reviewing','actioned','dismissed') NOT NULL DEFAULT 'open',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at         TIMESTAMP NULL,
    resolution_note     VARCHAR(255),
    FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (target_user_id)   REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (target_job_id)    REFERENCES jobs(id)  ON DELETE SET NULL,
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- ---------- Saved jobs (bookmarks) ----------
CREATE TABLE IF NOT EXISTS saved_jobs (
    user_id     INT NOT NULL,
    job_id      INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, job_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id)  REFERENCES jobs(id)  ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Recently viewed jobs ----------
CREATE TABLE IF NOT EXISTS recent_views (
    user_id     INT NOT NULL,
    job_id      INT NOT NULL,
    viewed_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, job_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id)  REFERENCES jobs(id)  ON DELETE CASCADE,
    INDEX idx_viewed (viewed_at)
) ENGINE=InnoDB;

-- ---------- Audit log ----------
CREATE TABLE IF NOT EXISTS audit_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    actor_id    INT NULL,
    action      VARCHAR(60) NOT NULL,
    entity      VARCHAR(60),
    entity_id   INT,
    meta        TEXT,
    ip          VARCHAR(45),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_action (action),
    INDEX idx_entity (entity, entity_id)
) ENGINE=InnoDB;

-- ---------- Password reset tokens ----------
-- Email is optional, so admins can issue a reset token too (support flow).
CREATE TABLE IF NOT EXISTS password_resets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token_hash  CHAR(64) NOT NULL UNIQUE,
    expires_at  TIMESTAMP NOT NULL,
    used_at     TIMESTAMP NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    issued_by   INT NULL,
    FOREIGN KEY (user_id)   REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------- Hide suspended / soft-deleted users from the public ----------
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

CREATE OR REPLACE VIEW v_employer_summary AS
SELECT
    u.id AS user_id, u.email, u.phone, u.photo_path,
    ep.business_name, ep.business_type, ep.bio, ep.verified,
    l.name AS location_name,
    (SELECT ROUND(AVG(r.score),2) FROM ratings r WHERE r.ratee_user_id = u.id) AS avg_rating,
    (SELECT COUNT(*) FROM ratings r WHERE r.ratee_user_id = u.id) AS rating_count,
    (SELECT COUNT(*) FROM jobs j WHERE j.employer_id = u.id) AS jobs_posted
FROM users u
JOIN employer_profiles ep ON ep.user_id = u.id
LEFT JOIN locations l ON l.id = ep.location_id
WHERE u.role = 'employer' AND u.active = 1 AND u.deleted_at IS NULL;

-- v_jobs_public filters out jobs by suspended employers
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
JOIN users u                   ON u.id = j.employer_id
LEFT JOIN employer_profiles ep ON ep.user_id = j.employer_id
LEFT JOIN categories c         ON c.id = j.category_id
LEFT JOIN locations  l         ON l.id = j.location_id
WHERE u.active = 1 AND u.deleted_at IS NULL;
