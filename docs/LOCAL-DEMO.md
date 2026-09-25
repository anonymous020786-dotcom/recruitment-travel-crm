# Running the CRM locally with sample data

A throwaway database with believable data, so every dashboard, report and list has something to show. It is kept apart from
the development database (`crm_dev`, which the test suite uses), and `scripts/demo-data.php` refuses to run against any
database whose name does not contain `demo`, or in production.

```bash
# 1. once: an empty database (XAMPP/MariaDB running; adjust the user to yours)
mysql -u root -e "CREATE DATABASE crm_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON crm_demo.* TO 'crm_dev'@'localhost'"

# 2. schema, reference data, sample data (real environment variables override .env)
DB_NAME=crm_demo php scripts/migrate.php
DB_NAME=crm_demo php scripts/seed.php
DB_NAME=crm_demo php scripts/demo-data.php

# 3. serve it
DB_NAME=crm_demo APP_URL=http://localhost:8899 php -S 127.0.0.1:8899 -t public public/index.php
```
(PowerShell: `$env:DB_NAME='crm_demo'; $env:APP_URL='http://localhost:8899'` before the commands.)

Open <http://localhost:8899/login>. Every login has the password **`Demo@12345678`**:

| Login | Role | Sees |
|---|---|---|
| demo.admin@example.test | Super admin | everything, all branches (Roles, Settings, Blog, Audit log, Users) |
| demo.manager@example.test | Manager, Mumbai | the Mumbai branch |
| demo.delhi@example.test | Manager, Delhi | the Delhi branch only |
| demo.counselor@example.test | Counselor | leads, candidates, follow-ups |
| demo.recruiter@example.test | Recruitment | employers, jobs, applications, interviews |
| demo.accounts@example.test | Accounts | invoices, payments, refunds, finance reports |
| demo.docs@example.test | Documentation | documents, medical, visa |

Includes: 2 branches, ~46 leads (19 converted to candidates), 4 employers with 7 open public jobs, applications spread across the
whole pipeline (so the funnel report has drop-offs), placements, medicals, visas and flights, invoices with payments (some overdue),
3 public tour packages with bookings, 2 blog posts and a business profile. The public site is at `/`, `/overseas-jobs`,
`/travel-packages`, `/blog`, `/contact`. To start over, drop and recreate `crm_demo` and repeat step 2.
