USE giglink;
INSERT INTO jobs (employer_id,title,description,status) VALUES (4,'QA migrated work dispute','Fixture','filled');
SET @work_job=LAST_INSERT_ID();
INSERT INTO applications (job_id,youth_id,status,started_at) VALUES (@work_job,3,'disputed',NOW());

INSERT INTO jobs (employer_id,title,description,status) VALUES (4,'QA migrated payment dispute','Fixture','completed');
SET @payment_job=LAST_INSERT_ID();
INSERT INTO applications (job_id,youth_id,status,completed_at,paid_at)
VALUES (@payment_job,3,'completed',NOW(),NOW());
SET @application=LAST_INSERT_ID();
INSERT INTO payments (job_id,employer_id,youth_id,amount,method,reference,status,application_id,receipt_status,receipt_at,dispute_reason)
VALUES (@payment_job,4,3,12000,'cash','QA migrated receipt','released',@application,'disputed',NOW(),'Legacy dispute reason.');

