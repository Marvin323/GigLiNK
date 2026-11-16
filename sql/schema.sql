-- =====================================================================
--  GigLink — Full database schema
--  Target: MariaDB 10.4+ (XAMPP). Fresh installs only.
--  After this file, apply 02_lifecycle, 03_usernames, 04_platform and 05_reliability.
-- =====================================================================

CREATE DATABASE giglink CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE giglink;

-- ---------------------------------------------------------------------
-- Core: users and locations
-- ---------------------------------------------------------------------
CREATE TABLE locations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    parent_id   INT NULL,
    FOREIGN KEY (parent_id) REFERENCES locations(id) ON DELETE SET NULL,
    UNIQUE KEY uq_loc (name, parent_id)
) ENGINE=InnoDB;

CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(40)  NOT NULL UNIQUE,
    email           VARCHAR(150) NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('youth','employer','admin') NOT NULL,
    phone           VARCHAR(30),
    active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Taxonomy: categories and skills
-- ---------------------------------------------------------------------
CREATE TABLE categories (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    name    VARCHAR(80) NOT NULL UNIQUE,
    slug    VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE skills (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    name    VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Profiles
-- ---------------------------------------------------------------------
CREATE TABLE youth_profiles (
    user_id         INT PRIMARY KEY,
    full_name       VARCHAR(120) NOT NULL,
    bio             TEXT,
    education       VARCHAR(120),
    date_of_birth   DATE,
    location_id     INT,
    FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE employer_profiles (
    user_id         INT PRIMARY KEY,
    business_name   VARCHAR(150) NOT NULL,
    business_type   VARCHAR(80),
    bio             TEXT,
    location_id     INT,
    verified        TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE youth_skills (
    youth_id    INT NOT NULL,
    skill_id    INT NOT NULL,
    PRIMARY KEY (youth_id, skill_id),
    FOREIGN KEY (youth_id) REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Jobs and applications
-- ---------------------------------------------------------------------
CREATE TABLE jobs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    employer_id     INT NOT NULL,
    category_id     INT,
    location_id     INT,
    title           VARCHAR(150) NOT NULL,
    description     TEXT NOT NULL,
    pay_amount      DECIMAL(10,2),
    pay_type        ENUM('fixed','hourly','daily','negotiable') NOT NULL DEFAULT 'fixed',
    workers_needed  INT NOT NULL DEFAULT 1,
    start_date      DATE,
    end_date        DATE,
    uses_escrow     TINYINT(1) NOT NULL DEFAULT 0,
    status          ENUM('open','filled','completed','closed','cancelled') NOT NULL DEFAULT 'open',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_renewed_at TIMESTAMP NULL,
    FOREIGN KEY (employer_id) REFERENCES users(id)      ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (location_id) REFERENCES locations(id)  ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE applications (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    job_id              INT NOT NULL,
    youth_id            INT NOT NULL,
    message             TEXT,
    is_team_lead        TINYINT(1) NOT NULL DEFAULT 0,
    status              ENUM('pending','accepted','completed','rejected','withdrawn','cancelled') NOT NULL DEFAULT 'pending',
    applied_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    decided_at          TIMESTAMP NULL,
    paid_at             TIMESTAMP NULL,
    employer_rated_at   TIMESTAMP NULL,
    youth_rated_at      TIMESTAMP NULL,
    completed_at        TIMESTAMP NULL,
    FOREIGN KEY (job_id)   REFERENCES jobs(id)  ON DELETE CASCADE,
    FOREIGN KEY (youth_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_app (job_id, youth_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Differentiators: availability, endorsements, ratings, payments
-- ---------------------------------------------------------------------
CREATE TABLE availability_windows (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    youth_id    INT NOT NULL,
    start_time  DATETIME NOT NULL,
    end_time    DATETIME NOT NULL,
    location_id INT,
    notes       VARCHAR(255),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (youth_id)    REFERENCES users(id)      ON DELETE CASCADE,
    FOREIGN KEY (location_id) REFERENCES locations(id)  ON DELETE SET NULL,
    INDEX idx_window (start_time, end_time)
) ENGINE=InnoDB;

-- Endorsements: either from a GigLink user, or an admin-recorded
-- institutional endorsement (chief, school, church, SACCO, …)
CREATE TABLE endorsements (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    endorsee_user_id    INT NOT NULL,
    endorser_user_id    INT NULL,
    endorser_name       VARCHAR(150) NOT NULL,
    endorser_role       VARCHAR(100) NOT NULL,
    note                TEXT,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (endorsee_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (endorser_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Two-way ratings: workers rate employers and vice versa, after a gig
CREATE TABLE ratings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    rater_user_id   INT NOT NULL,
    ratee_user_id   INT NOT NULL,
    job_id          INT NOT NULL,
    score           TINYINT NOT NULL,
    comment         TEXT,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rater_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (ratee_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (job_id)        REFERENCES jobs(id)  ON DELETE CASCADE,
    UNIQUE KEY uq_rating (rater_user_id, ratee_user_id, job_id),
    CHECK (score BETWEEN 1 AND 5),
    CHECK (rater_user_id <> ratee_user_id)
) ENGINE=InnoDB;

-- Base payment records. 05_reliability links them to applications.
CREATE TABLE payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    job_id          INT NOT NULL,
    employer_id     INT NOT NULL,
    youth_id        INT NOT NULL,
    amount          DECIMAL(10,2) NOT NULL,
    method          VARCHAR(40) DEFAULT 'mpesa',
    reference       VARCHAR(80),
    status          ENUM('pending','held','released','refunded','disputed') NOT NULL DEFAULT 'pending',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id)      REFERENCES jobs(id)  ON DELETE CASCADE,
    FOREIGN KEY (employer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (youth_id)    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
--  VIEWS
-- =====================================================================
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

CREATE OR REPLACE VIEW v_worker_summary AS
SELECT
    u.id AS user_id,
    u.email, u.phone,
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
WHERE u.role = 'youth' AND u.active = 1;

CREATE OR REPLACE VIEW v_employer_summary AS
SELECT
    u.id AS user_id, u.email, u.phone,
    ep.business_name, ep.business_type, ep.bio, ep.verified,
    l.name AS location_name,
    (SELECT ROUND(AVG(r.score),2) FROM ratings r WHERE r.ratee_user_id = u.id) AS avg_rating,
    (SELECT COUNT(*) FROM ratings r WHERE r.ratee_user_id = u.id) AS rating_count,
    (SELECT COUNT(*) FROM jobs j WHERE j.employer_id = u.id) AS jobs_posted
FROM users u
JOIN employer_profiles ep ON ep.user_id = u.id
LEFT JOIN locations l ON l.id = ep.location_id
WHERE u.role = 'employer' AND u.active = 1;

CREATE OR REPLACE VIEW v_worker_availability_active AS
SELECT
    aw.id, aw.youth_id, aw.start_time, aw.end_time, aw.notes,
    l.name AS location_name,
    ws.full_name, ws.skills, ws.avg_rating, ws.verified_badge
FROM availability_windows aw
JOIN v_worker_summary ws ON ws.user_id = aw.youth_id
LEFT JOIN locations l   ON l.id = aw.location_id
WHERE aw.end_time >= NOW();

-- =====================================================================
--  STORED PROCEDURES
-- =====================================================================
DELIMITER $$

CREATE PROCEDURE sp_apply_to_job (
    IN p_job_id   INT,
    IN p_youth_id INT,
    IN p_message  TEXT
)
BEGIN
    DECLARE v_status VARCHAR(20);
    SELECT status INTO v_status FROM jobs WHERE id = p_job_id;
    IF v_status IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Job not found';
    ELSEIF v_status <> 'open' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Job is not open for applications';
    END IF;
    INSERT INTO applications (job_id, youth_id, message) VALUES (p_job_id, p_youth_id, p_message);
END$$

CREATE PROCEDURE sp_decide_application (
    IN p_application_id INT,
    IN p_decision       VARCHAR(10),
    IN p_is_team_lead   TINYINT
)
BEGIN
    IF p_decision NOT IN ('accepted','rejected') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Decision must be accepted or rejected';
    END IF;
    UPDATE applications
       SET status = p_decision,
           is_team_lead = IFNULL(p_is_team_lead, 0),
           decided_at = NOW()
     WHERE id = p_application_id;
END$$

CREATE PROCEDURE sp_rate_user (
    IN p_rater   INT,
    IN p_ratee   INT,
    IN p_job_id  INT,
    IN p_score   TINYINT,
    IN p_comment TEXT
)
BEGIN
    INSERT INTO ratings (rater_user_id, ratee_user_id, job_id, score, comment)
    VALUES (p_rater, p_ratee, p_job_id, p_score, p_comment);
END$$

CREATE PROCEDURE sp_endorse_user (
    IN p_endorsee_id    INT,
    IN p_endorser_id    INT,
    IN p_endorser_name  VARCHAR(150),
    IN p_endorser_role  VARCHAR(100),
    IN p_note           TEXT
)
BEGIN
    INSERT INTO endorsements
        (endorsee_user_id, endorser_user_id, endorser_name, endorser_role, note)
    VALUES
        (p_endorsee_id, p_endorser_id, p_endorser_name, p_endorser_role, p_note);
END$$

CREATE PROCEDURE sp_close_job (
    IN p_job_id      INT,
    IN p_employer_id INT
)
BEGIN
    UPDATE jobs
       SET status = 'closed'
     WHERE id = p_job_id AND employer_id = p_employer_id;
END$$

DELIMITER ;

-- =====================================================================
--  TRIGGERS
-- =====================================================================
DELIMITER $$

-- Rating must be between two people who shared an accepted or completed application
CREATE TRIGGER trg_rating_requires_shared_job
BEFORE INSERT ON ratings
FOR EACH ROW
BEGIN
    DECLARE v_count INT;
    SELECT COUNT(*) INTO v_count
      FROM applications a
      JOIN jobs j ON j.id = a.job_id
     WHERE a.job_id = NEW.job_id
       AND a.status IN ('accepted','completed')
       AND (
             (a.youth_id = NEW.rater_user_id AND j.employer_id = NEW.ratee_user_id)
          OR (a.youth_id = NEW.ratee_user_id AND j.employer_id = NEW.rater_user_id)
       );
    IF v_count = 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'You can only rate a party you shared an accepted or completed job with';
    END IF;
END$$

-- When enough ACTIVE accepted applicants, auto-fill the job.
-- (Completed apps from a prior round don't block re-filling after renewal.)
CREATE TRIGGER trg_fill_job_when_full
AFTER UPDATE ON applications
FOR EACH ROW
BEGIN
    DECLARE v_needed  INT;
    DECLARE v_active  INT;
    IF NEW.status = 'accepted' AND OLD.status <> 'accepted' THEN
        SELECT workers_needed INTO v_needed FROM jobs WHERE id = NEW.job_id;
        SELECT COUNT(*) INTO v_active
          FROM applications WHERE job_id = NEW.job_id AND status='accepted';
        IF v_active >= v_needed THEN
            UPDATE jobs SET status = 'filled' WHERE id = NEW.job_id AND status='open';
        END IF;
    END IF;
END$$

-- Stamp decided_at automatically AND auto-complete the application
-- as soon as it has been paid + rated by the employer
CREATE TRIGGER trg_stamp_decided_at
BEFORE UPDATE ON applications
FOR EACH ROW
BEGIN
    IF NEW.status IN ('accepted','rejected') AND OLD.status = 'pending' THEN
        SET NEW.decided_at = NOW();
    END IF;
    IF NEW.status = 'accepted'
       AND NEW.paid_at IS NOT NULL
       AND NEW.employer_rated_at IS NOT NULL THEN
        SET NEW.status = 'completed';
        SET NEW.completed_at = NOW();
    END IF;
END$$

-- When the LAST active accepted application finishes, the whole job finishes
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

-- Lifecycle procedures (also in 02_lifecycle.sql for existing installs)
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
    UPDATE jobs SET status = 'open', last_renewed_at = NOW()
     WHERE id = p_job_id AND employer_id = p_employer_id;
END$$

DELIMITER ;

-- =====================================================================
--  SEED DATA
-- =====================================================================
INSERT INTO locations (name) VALUES
 ('Kampala'),('Wakiso'),('Mukono'),('Jinja'),('Mbarara'),('Gulu'),('Mbale'),('Masaka'),
 ('Entebbe'),('Fort Portal'),('Lira'),('Soroti'),('Arua'),('Hoima'),('Kabale'),('Tororo');

INSERT INTO categories (name, slug) VALUES
 ('Cleaning','cleaning'),
 ('Delivery','delivery'),
 ('Tutoring','tutoring'),
 ('Farm work','farm-work'),
 ('Phone repair','phone-repair'),
 ('Hairdressing','hairdressing'),
 ('Data entry','data-entry'),
 ('Event help','event-help'),
 ('Moving / Lifting','moving');

INSERT INTO skills (name) VALUES
 ('Cleaning'),('Driving'),('Boda-boda'),('Tutoring (primary)'),('Tutoring (secondary)'),
 ('Farming'),('Harvesting'),('Phone repair'),('Hairdressing'),('Barbering'),
 ('Data entry'),('Customer service'),('Cooking'),('Washing'),('Child care'),
 ('Lifting / moving'),('Event setup');

-- Demo users: password for all is "password" (bcrypt hash below is for "password")
-- You can log in immediately and poke around.
-- All demo accounts use password: password
INSERT INTO users (username, email, password_hash, role, phone) VALUES
 ('admin',     'admin@giglink.test',    '$2y$10$/j8Ms7MCbYOq0VyBMF6Vr.heAxnO0B2muZEAOrPClneoX3JcRAdPy', 'admin',    '0700000000'),
 ('alice',     'alice@giglink.test',    '$2y$10$/j8Ms7MCbYOq0VyBMF6Vr.heAxnO0B2muZEAOrPClneoX3JcRAdPy', 'youth',    '0711111111'),
 ('brian',     'brian@giglink.test',    '$2y$10$/j8Ms7MCbYOq0VyBMF6Vr.heAxnO0B2muZEAOrPClneoX3JcRAdPy', 'youth',    '0722222222'),
 ('mama',      'mama@giglink.test',     '$2y$10$/j8Ms7MCbYOq0VyBMF6Vr.heAxnO0B2muZEAOrPClneoX3JcRAdPy', 'employer', '0733333333'),
 ('shopowner', 'shopowner@giglink.test','$2y$10$/j8Ms7MCbYOq0VyBMF6Vr.heAxnO0B2muZEAOrPClneoX3JcRAdPy', 'employer', '0744444444');

INSERT INTO youth_profiles (user_id, full_name, bio, education, date_of_birth, location_id) VALUES
 (2,'Alice Wanjiru','Reliable, hardworking, available weekends.','Form 4', '2004-05-20', 1),
 (3,'Brian Otieno','Fast learner. Experienced in phone repair and deliveries.','College diploma','2002-11-08', 1);

INSERT INTO youth_skills (youth_id, skill_id) VALUES
 (2, 1),(2, 13),(2, 14),(2, 15),
 (3, 2),(3, 3),(3, 8),(3, 11);

INSERT INTO employer_profiles (user_id, business_name, business_type, bio, location_id, verified) VALUES
 (4, 'Mama Nalongo Household', 'Household', 'Family home in Ntinda, needs occasional help.', 1, 1),
 (5, 'Jamii Phone Shop',       'Small business', 'Phone and accessories shop, Kampala Road.', 1, 0);

INSERT INTO jobs (employer_id, category_id, location_id, title, description, pay_amount, pay_type, workers_needed, start_date)
VALUES
 (4, 1, 1, 'Deep clean 3-bedroom house', 'Need thorough cleaning this Saturday 8am-2pm.',   60000.00, 'fixed', 1, CURDATE()+INTERVAL 3 DAY),
 (5, 5, 1, 'Entry-level phone repair assistant', 'Help in shop with basic screen replacements.', 25000.00, 'daily', 2, CURDATE()+INTERVAL 1 DAY),
 (4, 7, 1, 'Data entry — 500 contacts into spreadsheet', 'Must be careful and accurate.',    45000.00, 'fixed', 1, CURDATE()+INTERVAL 2 DAY);

INSERT INTO endorsements (endorsee_user_id, endorser_user_id, endorser_name, endorser_role, note) VALUES
 (2, NULL, 'Pastor J. Lubwama', 'Pastor, Nakawa Baptist Church', 'Alice has helped at church events for 2 years. Trustworthy.'),
 (3, 4,    'Mama Nalongo',      'Repeat employer',               'Brian did a great delivery job for me last month.');

INSERT INTO availability_windows (youth_id, start_time, end_time, location_id, notes) VALUES
 (2, DATE_ADD(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY)+INTERVAL 8 HOUR, 1, 'Available for cleaning or child care'),
 (3, DATE_ADD(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 2 DAY)+INTERVAL 6 HOUR, 1, 'Can do deliveries anywhere in CBD');
