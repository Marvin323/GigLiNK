USE giglink;
INSERT INTO jobs (employer_id,title,description,status) VALUES (4,'QA carried reconciliation','Fixture','completed');
SET @target_job=LAST_INSERT_ID();
INSERT INTO applications (job_id,youth_id,status,completed_at) VALUES (@target_job,3,'completed',NOW());
SET @target_application=LAST_INSERT_ID();
INSERT INTO payments (job_id,employer_id,youth_id,amount,method,reference,status)
VALUES (3,4,3,12000,'cash','QA Phase 7 carried reconciliation','released');
INSERT INTO payment_reconciliations (payment_id,application_id,actor_id,reason,match_score)
VALUES (LAST_INSERT_ID(),@target_application,1,'Historical Phase 6 association.',65);

