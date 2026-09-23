<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\BranchScopeResolver;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\User;
use App\Repositories\ApplicationHistoryRepository;
use App\Repositories\ApplicationRepository;
use App\Repositories\InterviewRepository;
use App\Services\ApplicationService;
use App\Services\EmployerService;
use App\Services\InterviewService;
use App\Services\JobService;
use App\Services\LeadService;
use App\Support\Hash;
use App\Support\Ulid;
use App\Validators\InterviewValidator;
use App\Validators\JobValidator;
use Tests\Support\DbTestCase;

final class InterviewServiceTest extends DbTestCase
{
    private InterviewService $service;
    private ApplicationService $applications;
    private ApplicationRepository $appRepo;
    private InterviewRepository $repo;
    private ApplicationHistoryRepository $history;
    private int $branchA;
    private int $branchB;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $personIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->db->selectValue('SELECT COUNT(*) FROM lead_statuses') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->service = $this->app->get(InterviewService::class);
        $this->applications = $this->app->get(ApplicationService::class);
        $this->appRepo = $this->app->get(ApplicationRepository::class);
        $this->repo = $this->app->get(InterviewRepository::class);
        $this->history = $this->app->get(ApplicationHistoryRepository::class);
        $this->branchA = $this->branch('IX-A');
        $this->branchB = $this->branch('IX-B');
    }

    protected function tearDown(): void
    {
        $like = "branch_id IN (SELECT id FROM branches WHERE code LIKE 'IX-%')";
        $this->db->affectingStatement("DELETE FROM interviews WHERE application_id IN (SELECT id FROM applications WHERE {$like})");
        $this->db->affectingStatement("DELETE FROM applications WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM jobs WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM employers WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM candidates WHERE {$like}");
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module IN ('applications', 'interviews', 'jobs', 'employers', 'leads', 'candidates')");
        $this->db->affectingStatement("DELETE FROM leads WHERE {$like}");
        if ($this->personIds !== []) {
            $ph = implode(',', array_fill(0, count($this->personIds), '?'));
            $this->db->affectingStatement("DELETE FROM persons WHERE id IN ({$ph})", $this->personIds);
        }
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM notifications WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement("DELETE FROM branches WHERE code LIKE 'IX-%'");
        $this->db->affectingStatement("DELETE FROM number_sequences WHERE scope REGEXP '^(job|employer|lead|candidate|application):'");
    }

    private function branch(string $code): int
    {
        return (int) $this->db->insertRow('branches', [
            'public_id' => Ulid::generate(), 'name' => "Branch {$code}", 'code' => $code . '-' . bin2hex(random_bytes(2)),
        ]);
    }

    private function actor(string $role = 'manager', ?int $branchId = null): User
    {
        $branchId ??= $this->branchA;
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'ix_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $branchId, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $branchId]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    private function candidate(User $actor): Candidate
    {
        $leads = $this->app->get(LeadService::class);
        $lead = $leads->create([
            'name' => 'Cand', 'phone' => '94' . random_int(10000000, 99999999), 'priority' => 'medium',
        ], $actor, $this->branchA, confirmedNotDuplicate: true);
        $c = $leads->convert($lead, $actor, $lead->recordVersion);
        $this->personIds[] = $c->personId;

        return $c;
    }

    /** An application that is already shortlisted, i.e. ready for an interview. */
    private function shortlisted(User $actor): Application
    {
        $employer = $this->app->get(EmployerService::class)->create(['company_name' => 'Al Noor', 'country' => 'AE', 'status' => 'active'], $actor, $this->branchA);
        $jobs = $this->app->get(JobService::class);
        $job = $jobs->changeStatus($jobs->create($employer, (new JobValidator())->validate(['title' => 'Driver', 'country' => 'AE', 'vacancies' => '2']), $actor), 'open', $actor);
        $app = $this->applications->create($this->candidate($actor), $job, $actor);

        return $this->applications->changeStatus($app, 'shortlisted', $actor, $app->recordVersion);
    }

    /** @return array<string,mixed> */
    private function slot(string $date = '', string $type = 'telephonic', array $extra = []): array
    {
        return (new InterviewValidator())->schedule(['type' => $type, 'scheduled_date' => $date !== '' ? $date : gmdate('Y-m-d'), 'scheduled_time' => '10:30'] + $extra);
    }

    private function fresh(Application $a): Application
    {
        return $this->appRepo->findById($a->id, $this->scopeFor($this->userIds[0]));
    }

    private function scopeFor(int $userId): \App\Auth\BranchScope
    {
        return $this->app->get(BranchScopeResolver::class)->resolve($this->app->get(\App\Repositories\UserRepository::class)->findById($userId));
    }

    /** @return list<string> */
    private function trail(Application $a): array
    {
        return array_reverse(array_map(static fn (array $h): string => $h['to'], $this->history->forApplication($a->id)));
    }

    public function test_schedule_opens_round_one_and_moves_the_application(): void
    {
        $actor = $this->actor();
        $app = $this->shortlisted($actor);

        $i = $this->service->schedule($app, $this->slot(gmdate('Y-m-d', strtotime('+3 days'))), $actor);

        self::assertSame(1, $i->roundNo);
        self::assertSame('scheduled', $i->status);
        self::assertSame('pending', $i->result);
        self::assertSame('10:30', $i->scheduledTime);
        self::assertSame('interview_scheduled', $this->fresh($app)->status);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='interviews' AND action='scheduled' AND record_id = ?", [$i->id]));
    }

    public function test_cannot_schedule_before_shortlisting(): void
    {
        $actor = $this->actor();
        $app = $this->shortlisted($actor);
        $applied = $this->applications->create($this->candidate($actor), $this->jobOf($app), $actor);

        try {
            $this->service->schedule($applied, $this->slot(), $actor);
            self::fail('an applied candidate has no interview yet');
        } catch (DomainRuleException $e) {
            self::assertStringContainsString('Shortlist', $e->getMessage());
        }
        self::assertSame([], $this->repo->forApplication($applied->id, $this->scopeFor($this->userIds[0])));
    }

    public function test_only_one_open_interview_at_a_time(): void
    {
        $actor = $this->actor();
        $app = $this->shortlisted($actor);
        $this->service->schedule($app, $this->slot(), $actor);

        $this->expectException(DomainRuleException::class);
        $this->service->schedule($this->fresh($app), $this->slot(), $actor);
    }

    public function test_confirm_marks_scheduled_interview_and_cannot_repeat(): void
    {
        $actor = $this->actor();
        $i = $this->service->schedule($this->shortlisted($actor), $this->slot(), $actor);

        $confirmed = $this->service->confirm($i, $actor);
        self::assertSame('confirmed', $confirmed->status);

        $this->expectException(DomainRuleException::class);
        $this->service->confirm($confirmed, $actor);
    }

    public function test_reschedule_keeps_the_round_and_walks_the_application_through_rescheduled(): void
    {
        $actor = $this->actor();
        $app = $this->shortlisted($actor);
        $old = $this->service->schedule($app, $this->slot(), $actor);
        $newDate = gmdate('Y-m-d', strtotime('+4 days'));

        $new = $this->service->reschedule($old, $this->slot($newDate, 'video', ['meeting_link' => 'https://meet.example.com/x']), 'Employer unavailable', $actor);

        self::assertNotSame($old->id, $new->id);
        self::assertSame($old->roundNo, $new->roundNo);
        self::assertSame($newDate, $new->scheduledDate);
        $oldNow = $this->repo->findById($old->id, $this->scopeFor($this->userIds[0]));
        self::assertSame('rescheduled', $oldNow->status);
        self::assertStringContainsString('Employer unavailable', (string) $oldNow->notes);
        self::assertSame(['applied', 'shortlisted', 'interview_scheduled', 'rescheduled', 'interview_scheduled'], $this->trail($app));
        self::assertSame('interview_scheduled', $this->fresh($app)->status);
    }

    public function test_reschedule_needs_a_reason(): void
    {
        $actor = $this->actor();
        $i = $this->service->schedule($this->shortlisted($actor), $this->slot(), $actor);

        $this->expectException(ValidationException::class);
        $this->service->reschedule($i, $this->slot(), '  ', $actor);
    }

    public function test_selected_outcome_selects_the_application(): void
    {
        $actor = $this->actor();
        $app = $this->shortlisted($actor);
        $i = $this->service->schedule($app, $this->slot(), $actor);

        $done = $this->service->recordOutcome($i, 'selected', 'Strong driver', $actor);

        self::assertSame('selected', $done->status);
        self::assertSame('selected', $done->result);
        self::assertSame('Strong driver', $done->feedback);
        self::assertSame('selected', $this->fresh($app)->status);
        self::assertSame(['applied', 'shortlisted', 'interview_scheduled', 'interview_completed', 'selected'], $this->trail($app));
        self::assertSame('selected', (string) $this->db->selectValue('SELECT stage FROM candidates WHERE id = ?', [$app->candidateId]));
    }

    public function test_rejected_outcome_needs_feedback_and_closes_the_application(): void
    {
        $actor = $this->actor();
        $app = $this->shortlisted($actor);
        $i = $this->service->schedule($app, $this->slot(), $actor);

        try {
            $this->service->recordOutcome($i, 'rejected', null, $actor);
            self::fail('feedback required');
        } catch (ValidationException) {
            self::assertSame('interview_scheduled', $this->fresh($app)->status);
        }

        $this->service->recordOutcome($i, 'rejected', 'Failed the driving test', $actor);
        $after = $this->fresh($app);
        self::assertSame('rejected', $after->status);
        self::assertNotNull($after->closedAt);
    }

    public function test_hold_parks_the_application_and_the_next_round_is_two(): void
    {
        $actor = $this->actor();
        $app = $this->shortlisted($actor);
        $first = $this->service->schedule($app, $this->slot(), $actor);
        $held = $this->service->recordOutcome($first, 'hold', 'Client to decide', $actor);

        self::assertSame('completed', $held->status);
        self::assertSame('hold', $held->result);
        self::assertSame('interview_completed', $this->fresh($app)->status);

        $second = $this->service->schedule($this->fresh($app), $this->slot(), $actor);
        self::assertSame(2, $second->roundNo);
    }

    public function test_no_show_can_be_rescheduled_in_the_same_round(): void
    {
        $actor = $this->actor();
        $app = $this->shortlisted($actor);
        $i = $this->service->schedule($app, $this->slot(), $actor);

        $missed = $this->service->recordOutcome($i, 'no_show', null, $actor);
        self::assertSame('no_show', $missed->status);
        self::assertSame('no_show', $this->fresh($app)->status);

        $again = $this->service->schedule($this->fresh($app), $this->slot(), $actor);
        self::assertSame($i->roundNo, $again->roundNo);
        self::assertSame('interview_scheduled', $this->fresh($app)->status);
    }

    public function test_outcome_cannot_be_recorded_early_or_twice(): void
    {
        $actor = $this->actor();
        $future = $this->service->schedule($this->shortlisted($actor), $this->slot(gmdate('Y-m-d', strtotime('+5 days'))), $actor);

        try {
            $this->service->recordOutcome($future, 'selected', null, $actor);
            self::fail('interview has not happened yet');
        } catch (DomainRuleException $e) {
            self::assertStringContainsString('once it has taken place', $e->getMessage());
        }

        $today = $this->service->schedule($this->shortlisted($actor), $this->slot(), $actor);
        $done = $this->service->recordOutcome($today, 'selected', null, $actor);

        $this->expectException(DomainRuleException::class);
        $this->service->recordOutcome($done, 'rejected', 'changed my mind', $actor);
    }

    public function test_outcome_rolls_back_when_the_application_moved_underneath(): void
    {
        $actor = $this->actor();
        $app = $this->shortlisted($actor);
        $i = $this->service->schedule($app, $this->slot(), $actor);
        $live = $this->fresh($app);
        $this->applications->changeStatus($live, 'cancelled', $actor, $live->recordVersion, 'Withdrew');

        try {
            $this->service->recordOutcome($i, 'selected', null, $actor);
            self::fail('a cancelled application cannot be selected');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        self::assertSame('scheduled', $this->repo->findById($i->id, $this->scopeFor($this->userIds[0]))->status, 'interview row rolled back with the failed transition');
    }

    public function test_permissions_and_branch_scope_are_enforced(): void
    {
        $manager = $this->actor();
        $app = $this->shortlisted($manager);

        foreach (['counselor', 'read_only'] as $role) {
            try {
                $this->service->schedule($app, $this->slot(), $this->actor($role));
                self::fail("{$role} must not schedule");
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }

        $i = $this->service->schedule($app, $this->slot(), $manager);
        $outsider = $this->actor('manager', $this->branchB);

        foreach ([
            fn () => $this->service->confirm($i, $outsider),
            fn () => $this->service->recordOutcome($i, 'selected', null, $outsider),
            fn () => $this->service->recordOutcome($i, 'selected', null, $this->actor('counselor')),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('expected AuthorizationException');
            } catch (AuthorizationException) {
                self::assertTrue(true);
            }
        }

        self::assertNull($this->repo->findByPublicId($i->publicId, $this->app->get(BranchScopeResolver::class)->resolve($outsider)));
    }

    public function test_reminder_query_lists_open_interviews_for_owned_applications_only(): void
    {
        $actor = $this->actor();
        $app = $this->shortlisted($actor);
        $i = $this->service->schedule($app, $this->slot(), $actor);

        self::assertSame([], $this->repo->openOn(gmdate('Y-m-d')), 'unowned applications are skipped');

        $this->db->affectingStatement('UPDATE applications SET assigned_to = ? WHERE id = ?', [$actor->id, $app->id]);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->repo->openOn(gmdate('Y-m-d')));
        self::assertContains($i->id, $ids);
    }

    public function test_validator_rules(): void
    {
        $v = new InterviewValidator();
        $tomorrow = gmdate('Y-m-d', strtotime('+1 day'));

        foreach ([
            'past date'            => ['type' => 'telephonic', 'scheduled_date' => '2020-01-01'],
            'video without a link' => ['type' => 'video', 'scheduled_date' => $tomorrow],
            'in person no place'   => ['type' => 'in_person', 'scheduled_date' => $tomorrow],
            'bad time'             => ['type' => 'telephonic', 'scheduled_date' => $tomorrow, 'scheduled_time' => '25:99'],
            'non-http link'        => ['type' => 'video', 'scheduled_date' => $tomorrow, 'meeting_link' => 'ftp://x.example.com/a'],
            'unknown type'         => ['type' => 'carrier_pigeon', 'scheduled_date' => $tomorrow],
        ] as $label => $input) {
            try {
                $v->schedule($input);
                self::fail("{$label} should be rejected");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }

        $ok = $v->schedule(['type' => 'in_person', 'scheduled_date' => $tomorrow, 'location' => 'Colombo office', 'scheduled_time' => '09:00']);
        self::assertSame('09:00:00', $ok['scheduled_time']);
        self::assertNull($ok['meeting_link']);

        $this->expectException(ValidationException::class);
        $v->outcome(['outcome' => 'maybe']);
    }

    private function jobOf(Application $app): \App\Models\Job
    {
        return $this->app->get(\App\Repositories\JobRepository::class)->findById($app->jobId, $this->scopeFor($this->userIds[0]));
    }
}
