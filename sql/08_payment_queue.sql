-- Apply after 07 with web and worker writes paused.
USE giglink;
CREATE TABLE IF NOT EXISTS payment_obligations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    application_id INT NOT NULL UNIQUE,
    amount_minor BIGINT UNSIGNED NOT NULL CHECK (amount_minor BETWEEN 1 AND 1000000000000),
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'UGX',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (application_id) REFERENCES applications(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS payment_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    obligation_id INT NOT NULL,
    actor_id INT NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dispatch_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    provider VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('queued','sending','unknown','accepted','rejected') NOT NULL DEFAULT 'queued',
    diagnostic VARCHAR(40) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attempt_submission (actor_id,request_key),
    INDEX idx_attempt_obligation (obligation_id,state),
    FOREIGN KEY (obligation_id) REFERENCES payment_obligations(id),
    FOREIGN KEY (actor_id) REFERENCES users(id)
) ENGINE=InnoDB;
ALTER TABLE provider_transactions ADD COLUMN IF NOT EXISTS attempt_id INT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS uq_provider_attempt (attempt_id),
    ADD FOREIGN KEY IF NOT EXISTS fk_provider_attempt (attempt_id) REFERENCES payment_attempts(id);

CREATE TABLE IF NOT EXISTS payment_jobs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('initiate','status','webhook','settlement') NOT NULL,
    attempt_id INT NULL,
    event_id BIGINT NULL,
    status ENUM('queued','processing','done','dead') NOT NULL DEFAULT 'queued',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 8,
    run_after DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    lock_owner CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    lease_until DATETIME(6) NULL,
    last_error VARCHAR(40) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_job_attempt (kind,attempt_id),
    UNIQUE KEY uq_job_event (event_id),
    UNIQUE KEY uq_job_lock (lock_owner),
    INDEX idx_job_due (status,run_after,id),
    INDEX idx_job_lease (status,lease_until,id),
    CHECK ((kind='webhook' AND event_id IS NOT NULL AND attempt_id IS NULL)
        OR (kind<>'webhook' AND attempt_id IS NOT NULL AND event_id IS NULL)),
    CHECK (max_attempts BETWEEN 1 AND 100 AND attempts <= max_attempts),
    FOREIGN KEY (attempt_id) REFERENCES payment_attempts(id),
    FOREIGN KEY (event_id) REFERENCES provider_events(id)
) ENGINE=InnoDB;

