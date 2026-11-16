USE giglink;
INSERT INTO applications (job_id,youth_id,status,decided_at,paid_at,completed_at)
VALUES (3,3,'completed',NOW(),NOW(),NOW());
INSERT INTO payments (job_id,employer_id,youth_id,amount,method,reference,status,created_at,updated_at)
VALUES (3,4,3,45000,'cash','QA historical receipt','released','2024-05-01 12:00:00','2024-05-02 12:00:00'),
       (3,4,3,5000,'cash','QA historical refund','refunded','2024-05-01 12:00:00','2024-05-02 12:00:00'),
       (3,4,2,5000,'cash','QA unmatched receipt','released','2024-05-01 12:00:00','2024-05-02 12:00:00');

