-- =====================================================================
--  GigLink migration 03 — Switch to username-based login
--  Run once after 02_lifecycle.sql.
-- =====================================================================
USE giglink;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS username VARCHAR(40) NULL AFTER id;

-- Backfill: use the local part of the email, lowercased, stripped to safe chars
UPDATE users
   SET username = LOWER(
         REGEXP_REPLACE(SUBSTRING_INDEX(email, '@', 1), '[^a-z0-9_]', '')
       )
 WHERE username IS NULL OR username = '';

-- Make username the primary handle and loosen the email requirement
ALTER TABLE users
    MODIFY COLUMN username VARCHAR(40) NOT NULL,
    MODIFY COLUMN email    VARCHAR(150) NULL,
    ADD UNIQUE KEY uq_username (username);
