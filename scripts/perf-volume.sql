-- Synthetic volume for the Phase 12 performance review — NEVER load this into a real database.
--
--   mysql -u root -e "CREATE DATABASE crm_explain CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--   DB_NAME=crm_explain php scripts/migrate.php && DB_NAME=crm_explain php scripts/seed.php
--   mysql -u root crm_explain < scripts/perf-volume.sql          (needs MariaDB's `seq` engine; ~30 s)
--   DB_NAME=crm_explain php scripts/perf-audit.php
--
-- Loads 2 branches, 40 users, 100k persons, 150k leads, 50k candidates, 500 employers, 5k jobs,
-- 100k applications, 60k invoices, 50k payments and 200k notifications. Give InnoDB a real buffer pool first
-- (SET GLOBAL innodb_buffer_pool_size = 1024*1024*1024): XAMPP's 16 MB default makes every number IO-bound.
-- Note the data is correlated by construction (e.g. branch A never holds 'selected' applications).

SET FOREIGN_KEY_CHECKS = 0;
SET unique_checks = 0;
SET autocommit = 0;

INSERT INTO branches (public_id, name, code) VALUES ('BR00000000000000000000A001', 'Vol A', 'VOL-A'), ('BR00000000000000000000A002', 'Vol B', 'VOL-B');
SET @ba = (SELECT MIN(id) FROM branches WHERE code = 'VOL-A');
SET @bb = (SELECT MIN(id) FROM branches WHERE code = 'VOL-B');
SET @role = (SELECT id FROM roles WHERE name = 'manager');
INSERT INTO users (public_id, name, email, password_hash, role_id, primary_branch_id, is_active)
  SELECT CONCAT('US', LPAD(seq, 24, '0')), CONCAT('User ', seq), CONCAT('vol', seq, '@dev.local'), 'x', @role, IF(seq % 2, @ba, @bb), 1 FROM seq_1_to_40;
SET @u = (SELECT MIN(id) FROM users WHERE email LIKE 'vol%@dev.local');
SET @ls0 = (SELECT MIN(id) FROM lead_statuses);

-- people: 100k
INSERT INTO persons (public_id, full_name, primary_phone, email)
  SELECT CONCAT('PE', LPAD(seq, 24, '0')), CONCAT('Person ', ELT(1 + seq % 7, 'Aslam', 'Bilal', 'Chandra', 'Dinesh', 'Eshan', 'Farid', 'Gita'), ' ', seq),
         CONCAT('9', LPAD(seq, 9, '0')), CONCAT('p', seq, '@example.com') FROM seq_1_to_100000;
SET @p0 = (SELECT MIN(id) FROM persons);

-- leads: 150k
INSERT INTO leads (public_id, lead_number, branch_id, name, phone, status_id, assigned_to, created_at)
  SELECT CONCAT('LE', LPAD(seq, 24, '0')), CONCAT('LEAD-2026-', LPAD(seq, 6, '0')), IF(seq % 3, @ba, @bb), CONCAT('Lead ', seq), CONCAT('8', LPAD(seq, 9, '0')),
         @ls0 + (seq % 8), @u + (seq % 40), NOW() - INTERVAL (seq % 900) DAY FROM seq_1_to_150000;

-- candidates: 50k
INSERT INTO candidates (public_id, candidate_number, person_id, branch_id)
  SELECT CONCAT('CA', LPAD(seq, 24, '0')), CONCAT('CAND-2026-', LPAD(seq, 6, '0')), @p0 + seq - 1, IF(seq % 3, @ba, @bb) FROM seq_1_to_50000;
SET @c0 = (SELECT MIN(id) FROM candidates);

-- employers 500, jobs 5k
INSERT INTO employers (public_id, employer_number, company_name, country, branch_id)
  SELECT CONCAT('EM', LPAD(seq, 24, '0')), CONCAT('EMP-2026-', LPAD(seq, 6, '0')), CONCAT('Company ', seq), 'AE', IF(seq % 2, @ba, @bb) FROM seq_1_to_500;
SET @e0 = (SELECT MIN(id) FROM employers);
INSERT INTO jobs (public_id, job_number, slug, title, employer_id, country, branch_id, status)
  SELECT CONCAT('JO', LPAD(seq, 24, '0')), CONCAT('JOB-2026-', LPAD(seq, 6, '0')), CONCAT('job-', seq), CONCAT('Driver ', seq), @e0 + (seq % 500), 'AE', IF(seq % 2, @ba, @bb), ELT(1 + seq % 3, 'open', 'draft', 'closed') FROM seq_1_to_5000;
SET @j0 = (SELECT MIN(id) FROM jobs);

-- applications 100k
INSERT INTO applications (public_id, application_number, candidate_id, job_id, employer_id, branch_id, status, applied_at)
  SELECT CONCAT('AP', LPAD(seq, 24, '0')), CONCAT('APP-2026-', LPAD(seq, 6, '0')), @c0 + (seq % 50000), @j0 + ((seq % 5000) + (seq DIV 50000)) % 5000, @e0 + (seq % 500), IF(seq % 3, @ba, @bb),
         ELT(1 + seq % 6, 'applied', 'shortlisted', 'interview_scheduled', 'selected', 'visa_processing', 'rejected'), NOW() - INTERVAL (seq % 700) DAY FROM seq_1_to_100000;
SET @a0 = (SELECT MIN(id) FROM applications);

-- invoices 60k (currency INR), payments 50k
INSERT INTO invoices (public_id, invoice_number, person_id, branch_id, invoiceable_type, invoiceable_id, created_by, status, currency, grand_total, amount_paid, due_on, created_at)
  SELECT CONCAT('IN', LPAD(seq, 24, '0')), CONCAT('INV-2026-', LPAD(seq, 6, '0')), @p0 + (seq % 100000), IF(seq % 3, @ba, @bb), 'application', @a0 + (seq % 100000), @u,
         ELT(1 + seq % 4, 'issued', 'partially_paid', 'paid', 'draft'), 'INR', 1000 + (seq % 50) * 100, IF(seq % 4 = 3, 1000 + (seq % 50) * 100, IF(seq % 4 = 2, 400, 0)),
         CURDATE() - INTERVAL (CAST(seq % 120 AS SIGNED) - 30) DAY, NOW() - INTERVAL (seq % 400) DAY FROM seq_1_to_60000;
INSERT INTO payments (public_id, payment_number, receipt_number, person_id, branch_id, amount, method, paid_at, created_by, status, currency)
  SELECT CONCAT('PY', LPAD(seq, 24, '0')), CONCAT('PAY-2026-', LPAD(seq, 6, '0')), CONCAT('RCT-2026-', LPAD(seq, 6, '0')), @p0 + (seq % 100000), IF(seq % 3, @ba, @bb), 500 + (seq % 40) * 50,
         ELT(1 + seq % 3, 'cash', 'upi', 'bank_transfer'), NOW() - INTERVAL (seq % 300) DAY, @u, 'recorded', 'INR' FROM seq_1_to_50000;

-- notifications 200k (per user), activity 300k, cron_runs 20k
INSERT INTO notifications (user_id, type, title, read_at, created_at)
  SELECT @u + (seq % 40), 'lead_assigned', CONCAT('Note ', seq), IF(seq % 5, NOW(), NULL), NOW() - INTERVAL (seq % 200) DAY FROM seq_1_to_200000;

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;
SELECT 'leads', COUNT(*) FROM leads UNION ALL SELECT 'candidates', COUNT(*) FROM candidates UNION ALL SELECT 'applications', COUNT(*) FROM applications UNION ALL SELECT 'invoices', COUNT(*) FROM invoices UNION ALL SELECT 'payments', COUNT(*) FROM payments UNION ALL SELECT 'notifications', COUNT(*) FROM notifications;
