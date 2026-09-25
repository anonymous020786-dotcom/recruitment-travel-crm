<?php

declare(strict_types=1);

/**
 * Fills a THROWAWAY local database with believable sample data so the dashboards, reports and lists have something
 * to show. It never runs against anything but a database whose name contains "demo", and never in production.
 *
 *   DB_NAME=crm_demo php scripts/migrate.php
 *   DB_NAME=crm_demo php scripts/seed.php
 *   DB_NAME=crm_demo php scripts/demo-data.php
 *
 * Creates two branches, seven staff logins (all with the password below), leads, candidates, employers, jobs, applications
 * across the whole pipeline, placements, medicals, visas, flights, invoices and payments (some overdue), tour packages and
 * bookings, and a couple of blog posts. Everything goes through the real services where a service exists, so audit rows,
 * numbers and rules are exactly what the application produces; only the dates are then spread over the past months.
 */

use App\Models\Application;
use App\Models\Candidate;
use App\Models\User;
use App\Repositories\InvoiceRepository;
use App\Repositories\UserRepository;
use App\Services\ApplicationService;
use App\Services\BlogService;
use App\Services\EmployerService;
use App\Services\InvoiceService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Services\PaymentService;
use App\Services\SettingsService;
use App\Services\TourBookingService;
use App\Services\TourPackageService;
use App\Support\Application as App;
use App\Support\Db;
use App\Support\Hash;
use App\Support\Ulid;
use App\Validators\InvoiceValidator;
use App\Validators\JobValidator;
use App\Validators\PaymentValidator;
use App\Validators\TourBookingValidator;
use App\Validators\TourPackageValidator;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var App $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$db = $app->get(Db::class);

$dbName = (string) $db->selectValue('SELECT DATABASE()');
if ((string) $app->config()->get('app.env') === 'production' || !str_contains($dbName, 'demo')) {
    fwrite(STDERR, "Refusing to run: this fills a database with fake data and only runs against one whose name contains 'demo' (this one is '{$dbName}').\n");
    exit(1);
}
if ((int) $db->selectValue("SELECT COUNT(*) FROM users WHERE email LIKE 'demo.%@example.test'") > 0) {
    fwrite(STDERR, "Demo data is already there ({$dbName}). Drop and recreate the database to start again.\n");
    exit(1);
}

mt_srand(42);
const PASSWORD = 'Demo@12345678';
$hash = $app->get(Hash::class);
$users = $app->get(UserRepository::class);
$say = static fn (string $m) => print($m . "\n");
$ago = static fn (int $days, int $hour = 10): string => gmdate('Y-m-d', strtotime("-{$days} days")) . sprintf(' %02d:%02d:00', $hour, mt_rand(0, 59));
$ahead = static fn (int $days): string => gmdate('Y-m-d', strtotime("+{$days} days"));

// ---- branches and staff ---------------------------------------------------------------------------
$branch = static function (string $name, string $code) use ($db): int {
    return (int) $db->insertRow('branches', ['public_id' => Ulid::generate(), 'name' => $name, 'code' => $code]);
};
$mumbai = $branch('Mumbai HQ', 'MUM');
$delhi = $branch('Delhi Office', 'DEL');
$roles = array_column($db->select('SELECT id, name FROM roles'), 'id', 'name');

$staff = [];
foreach ([
    ['admin', 'super_admin', 'Anita Sharma (Super Admin)', $mumbai, 1],
    ['manager', 'manager', 'Rahul Verma (Mumbai Manager)', $mumbai, 0],
    ['counselor', 'counselor', 'Priya Nair (Counselor)', $mumbai, 0],
    ['recruiter', 'recruitment', 'Imran Qureshi (Recruitment)', $mumbai, 0],
    ['accounts', 'accounts', 'Meera Iyer (Accounts)', $mumbai, 0],
    ['docs', 'documentation', 'Suresh Patil (Documentation)', $mumbai, 0],
    ['delhi', 'manager', 'Vikram Singh (Delhi Manager)', $delhi, 0],
] as [$key, $role, $name, $branchId, $orgWide]) {
    $id = (int) $db->insertRow('users', [
        'public_id' => Ulid::generate(), 'name' => $name, 'email' => "demo.{$key}@example.test", 'password_hash' => $hash->make(PASSWORD),
        'role_id' => (int) $roles[$role], 'primary_branch_id' => $branchId, 'is_org_wide' => $orgWide, 'is_active' => 1, 'must_change_password' => 0,
    ]);
    $db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
    $staff[$key] = $users->findById($id);
}
$say('staff: ' . count($staff));

// ---- business profile + blog --------------------------------------------------------------------------
$app->get(SettingsService::class)->update([
    'business.name' => 'Horizon Overseas Careers', 'business.phone' => '+91 22 4000 1234', 'business.whatsapp' => '+91 98200 12345',
    'business.email' => 'hello@horizon-careers.example', 'business.address' => "12 Marine Lines\nMumbai 400020", 'business.hours' => 'Mon–Sat, 10:00–18:30',
], $staff['admin']);
$blog = $app->get(BlogService::class);
foreach ([
    ['Working in Dubai: what to know before you apply', "## The basics\n\nMost roles in the UAE need a **valid passport**, a medical certificate and an employment visa arranged by the employer.\n\n- Passport with at least 6 months validity\n- Medical fitness certificate\n- Attested certificates\n\nSee current openings on our [jobs page](/overseas-jobs)."],
    ['A checklist for your medical examination', "## Before you go\n\n1. Carry your passport\n2. Fast if the centre asks you to\n3. Bring previous reports\n\nResults usually take 2–3 working days. Questions? [Contact us](/contact)."],
] as [$title, $body]) {
    $blog->publish($blog->create(['title' => $title, 'body' => $body], $staff['manager']), $staff['manager']);
}

// ---- leads and candidates -------------------------------------------------------------------------------------
$first = ['Aarav', 'Vivaan', 'Aditya', 'Arjun', 'Rohan', 'Karan', 'Mohit', 'Sanjay', 'Deepak', 'Manoj', 'Imran', 'Faisal', 'Salman', 'Ravi', 'Naveen', 'Suresh', 'Anil', 'Vijay', 'Pradeep', 'Harish', 'Kiran', 'Ajay', 'Sunil', 'Ramesh'];
$last = ['Sharma', 'Verma', 'Khan', 'Patil', 'Nair', 'Reddy', 'Singh', 'Yadav', 'Shaikh', 'Kumar', 'Gupta', 'Das', 'Mishra', 'Pillai', 'Ansari'];
$sources = array_column($db->select('SELECT id FROM lead_sources WHERE is_active = 1'), 'id');
$statusIds = array_column($db->select("SELECT id FROM lead_statuses WHERE key_name <> 'converted'"), 'id');
$leads = $app->get(LeadService::class);
$phone = 9820000000;
$candidates = [];
$n = 0;
foreach ([[$staff['counselor'], $mumbai, 34, 14], [$staff['delhi'], $delhi, 12, 5]] as [$actor, $branchId, $count, $convert]) {
    for ($i = 0; $i < $count; $i++, $n++) {
        $name = $first[array_rand($first)] . ' ' . $last[array_rand($last)];
        try {
            $lead = $leads->create(['name' => $name, 'phone' => (string) (++$phone), 'priority' => ['low', 'medium', 'high'][mt_rand(0, 2)], 'source_id' => (int) $sources[array_rand($sources)]], $actor, $branchId, confirmedNotDuplicate: true);
        } catch (\Throwable $e) {
            continue;
        }
        $db->affectingStatement('UPDATE leads SET created_at = ? WHERE id = ?', [$ago(mt_rand(1, 75)), $lead->id]);
        if ($i < $convert) {
            $c = $leads->convert($lead, $actor, $lead->recordVersion);
            $candidates[] = ['c' => $c, 'actor' => $actor, 'branch' => $branchId];
        } elseif ($statusIds !== []) {
            $db->affectingStatement('UPDATE leads SET status_id = ? WHERE id = ?', [(int) $statusIds[array_rand($statusIds)], $lead->id]);
        }
    }
}
$say('leads: ' . $n . ', candidates: ' . count($candidates));

// ---- employers, jobs, applications ------------------------------------------------------------------------------
$employers = $app->get(EmployerService::class);
$jobs = $app->get(JobService::class);
$appsSvc = $app->get(ApplicationService::class);
$jobModels = [];
foreach ([
    ['Al Noor Contracting', 'AE', [['Heavy Vehicle Driver', 2200, 'AED', 12], ['Site Electrician', 1800, 'AED', 8]]],
    ['Gulf Hospitality Group', 'AE', [['Hotel Housekeeping Attendant', 1300, 'AED', 20], ['Restaurant Server', 1500, 'AED', 10]]],
    ['Riyadh Build Co', 'SA', [['Steel Fixer', 1600, 'SAR', 15], ['Scaffolder', 1700, 'SAR', 6]]],
    ['Doha Logistics', 'QA', [['Warehouse Operator', 1900, 'QAR', 9]]],
] as [$company, $country, $openings]) {
    $employer = $employers->create(['company_name' => $company, 'country' => $country, 'status' => 'active', 'city' => 'Capital', 'industry' => 'Services'], $staff['recruiter'], $mumbai);
    foreach ($openings as [$title, $salary, $currency, $vacancies]) {
        $job = $jobs->create($employer, (new JobValidator())->validate([
            'title' => $title, 'country' => $country, 'vacancies' => (string) $vacancies, 'salary_min' => (string) $salary, 'salary_max' => (string) ($salary + 400), 'currency' => $currency,
            'description' => "We are hiring a {$title}. Accommodation and transport provided; overtime paid as per company policy.", 'accommodation' => 'provided', 'food' => 'provided', 'transport' => 'provided',
        ]), $staff['recruiter']);
        $open = $jobs->changeStatus($job, 'open', $staff['recruiter']);
        $jobModels[] = $jobs->setPublic($open, true, $staff['recruiter']);
    }
}
$say('jobs: ' . count($jobModels));

// pipeline: how far each application gets (history rows keep the funnel report honest)
$path = ['applied', 'shortlisted', 'interview_scheduled', 'interview_completed', 'selected', 'offer_received', 'offer_accepted', 'medical_pending', 'medical_completed', 'visa_processing', 'visa_approved', 'ticket_pending', 'ticket_booked', 'departed', 'placed'];
$depths = [0, 0, 1, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 14, 14];
$apps = [];
foreach ($candidates as $k => $row) {
    $job = $jobModels[$k % count($jobModels)];
    try {
        $a = $appsSvc->create($row['c'], $job, $row['actor']);
    } catch (\Throwable $e) {
        continue;
    }
    $depth = $depths[array_rand($depths)];
    $terminal = mt_rand(1, 10) === 1 ? ['rejected', 'cancelled'][mt_rand(0, 1)] : null;
    $when = mt_rand(3, 80);
    $db->affectingStatement('UPDATE applications SET applied_at = ? WHERE id = ?', [$ago($when), $a->id]);
    $status = 'applied';
    foreach (array_slice($path, 1, $depth) as $s) {
        $db->insertRow('application_status_history', ['application_id' => $a->id, 'from_status' => $status, 'to_status' => $s, 'changed_by' => $staff['recruiter']->id, 'changed_at' => $ago(max(1, $when - 2))]);
        $status = $s;
    }
    if ($terminal !== null && $status !== 'placed') {
        $db->insertRow('application_status_history', ['application_id' => $a->id, 'from_status' => $status, 'to_status' => $terminal, 'changed_by' => $staff['recruiter']->id, 'reason' => 'Demo data']);
        $status = $terminal;
    }
    $db->affectingStatement('UPDATE applications SET status = ? WHERE id = ?', [$status, $a->id]);
    $apps[] = ['a' => $a, 'status' => $status, 'row' => $row, 'when' => $when];
}
$say('applications: ' . count($apps));

// ---- placements, medicals, visas, flights, passports ------------------------------------------------------------------
$idx = 0;
foreach ($apps as $x) {
    /** @var Application $a */
    $a = $x['a'];
    $c = $x['row']['c'];
    $idx++;
    $db->insertRow('passports', ['candidate_id' => $c->id, 'passport_number' => 'P' . str_pad((string) (1000000 + $idx * 7919), 7, '0', STR_PAD_LEFT), 'expiry_date' => $ahead(mt_rand(20, 1800))]);
    $rank = array_search($x['status'], $path, true);
    if ($rank !== false && $rank >= 7) {
        $db->insertRow('medical_records', ['public_id' => Ulid::generate(), 'candidate_id' => $c->id, 'application_id' => $a->id, 'result' => 'fit', 'status' => 'fit', 'expires_at' => $ahead(mt_rand(10, 80)), 'created_by' => $staff['docs']->id, 'medical_center' => 'Gulf Approved Clinic, Mumbai']);
    }
    if ($rank !== false && $rank >= 9) {
        $db->insertRow('visa_applications', ['public_id' => Ulid::generate(), 'candidate_id' => $c->id, 'application_id' => $a->id, 'country' => 'AE', 'status' => $rank >= 10 ? 'approved' : 'under_processing', 'expiry_date' => $rank >= 10 ? $ahead(mt_rand(15, 300)) : null, 'created_by' => $staff['docs']->id]);
    }
    if ($rank !== false && $rank >= 12) {
        $db->insertRow('flight_bookings', [
            'public_id' => Ulid::generate(), 'candidate_id' => $c->id, 'application_id' => $a->id, 'pnr' => strtoupper(bin2hex(random_bytes(3))), 'airline' => 'Air India', 'flight_number' => 'AI' . mt_rand(900, 999),
            'departure_airport' => 'BOM', 'arrival_airport' => 'DXB', 'departure_at' => ($rank >= 13 ? $ago(mt_rand(1, 20)) : $ahead(mt_rand(1, 14)) . ' 04:30:00'), 'status' => $rank >= 13 ? 'flown' : 'booked', 'created_by' => $staff['docs']->id,
        ]);
    }
    if ($x['status'] === 'placed') {
        $db->insertRow('placements', [
            'public_id' => Ulid::generate(), 'candidate_id' => $c->id, 'application_id' => $a->id, 'employer_id' => $a->employerId, 'job_id' => $a->jobId, 'branch_id' => $x['row']['branch'],
            'placed_on' => gmdate('Y-m-d', strtotime('-' . mt_rand(2, 40) . ' days')), 'monthly_salary' => (string) mt_rand(1300, 2400) . '.00', 'currency' => 'AED', 'status' => 'active',
        ]);
    }
}

// ---- invoices and payments -----------------------------------------------------------------------------------------------
$invSvc = $app->get(InvoiceService::class);
$paySvc = $app->get(PaymentService::class);
$invRepo = $app->get(InvoiceRepository::class);
$scope = $app->get(\App\Auth\BranchScopeResolver::class)->resolve($staff['accounts']);
$k = 0;
foreach ($apps as $x) {
    if (in_array($x['status'], ['applied', 'rejected', 'cancelled'], true) || $x['row']['branch'] !== $mumbai) {
        continue;
    }
    $k++;
    $amount = [15000, 22000, 30000, 45000][mt_rand(0, 3)];
    try {
        $draft = $invSvc->createForApplication($x['a'], (new InvoiceValidator())->invoice([
            'currency' => 'inr', 'line_description' => ['Recruitment service fee', 'Documentation charges'], 'line_quantity' => ['1', '1'], 'line_unit_price' => [(string) $amount, '3500'],
        ]), $staff['accounts']);
        $inv = $invSvc->issue($draft, $staff['accounts'], $draft->recordVersion);
        $due = $k % 4 === 0 ? -mt_rand(3, 25) : mt_rand(5, 30);
        $db->affectingStatement('UPDATE invoices SET due_on = ?, issued_on = ? WHERE id = ?', [gmdate('Y-m-d', strtotime(($due >= 0 ? '+' : '') . $due . ' days')), gmdate('Y-m-d', strtotime('-' . mt_rand(10, 120) . ' days')), $inv->id]);
        $inv = $invRepo->findById($inv->id, $scope);
        $portion = [1.0, 1.0, 0.5, 0.0, 0.3][$k % 5];
        if ($portion > 0) {
            $pay = $paySvc->recordForInvoice($inv, (new PaymentValidator())->payment([
                'amount' => number_format(($amount + 3500) * $portion, 2, '.', ''), 'method' => ['upi', 'cash', 'bank_transfer', 'card'][mt_rand(0, 3)], 'reference' => 'DEMO-' . strtoupper(bin2hex(random_bytes(3))),
            ]), $staff['accounts'])['payment'];
            $db->affectingStatement('UPDATE payments SET paid_at = ? WHERE id = ?', [$ago(mt_rand(1, 170)), $pay->id]);
        }
    } catch (\Throwable $e) {
        $say('  (skipped an invoice: ' . $e->getMessage() . ')');
    }
}
$say('invoices: ' . (int) $db->selectValue('SELECT COUNT(*) FROM invoices') . ', payments: ' . (int) $db->selectValue('SELECT COUNT(*) FROM payments'));

// ---- tours -----------------------------------------------------------------------------------------------------------------
$pk = $app->get(TourPackageService::class);
$bk = $app->get(TourBookingService::class);
$bv = new TourBookingValidator();
$packages = [];
foreach ([['Dubai Escape 5N/6D', 'Dubai', '48500'], ['Bali Honeymoon 6N/7D', 'Bali', '72000'], ['Singapore & Malaysia 5N/6D', 'Singapore', '61500']] as [$name, $dest, $price]) {
    $p = $pk->create((new TourPackageValidator())->validate(['name' => $name, 'destination' => $dest, 'price' => $price, 'currency' => 'inr', 'duration_days' => '6', 'duration_nights' => '5',
        'inclusions' => "Return flights\nHotel with breakfast\nAirport transfers\nSightseeing", 'exclusions' => "Visa fees\nPersonal expenses"]), $staff['manager']);
    foreach (['Arrival and transfer to hotel', 'City tour and sightseeing', 'Free day / optional excursions', 'Departure'] as $d => $line) {
        $pk->addItem($p, (new TourPackageValidator())->item(['day_no' => (string) ($d + 1), 'title' => $line]), $staff['manager']);
    }
    $p = $pk->changeStatus($p, 'active', $staff['manager']);
    $packages[] = $pk->setPublic($p, true, $staff['manager']);
}
foreach ([['Kapoor family', 4, 0], ['Rohit & Sneha', 2, 1], ['Dhanraj Traders group', 8, 2], ['Farah Khan', 1, 0], ['Lakshmi Iyer', 3, 2]] as $j => [$who, $pax, $pi]) {
    try {
        $b = $bk->create($bv->customer(['customer_name' => $who, 'customer_phone' => (string) (9830000000 + $j)]), $bv->trip(['adults' => (string) $pax, 'package' => $packages[$pi]->publicId, 'travel_date' => $ahead(20 + $j * 9)]), $staff['manager']);
        if ($j % 2 === 0) {
            $bk->changeStatus($b, 'confirmed', $staff['manager'], $b->recordVersion);
        }
    } catch (\Throwable $e) {
        $say('  (skipped a booking: ' . $e->getMessage() . ')');
    }
}

$say("\nDone. Sign in with any of these (password: " . PASSWORD . "):");
foreach (array_keys($staff) as $key) {
    $say(sprintf('  demo.%s@example.test', $key));
}
