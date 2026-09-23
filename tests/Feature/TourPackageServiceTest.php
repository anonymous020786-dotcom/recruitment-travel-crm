<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\TourPackage;
use App\Models\User;
use App\Repositories\TourPackageItemRepository;
use App\Repositories\TourPackageRepository;
use App\Services\TourPackageService;
use App\Support\Hash;
use App\Support\ListQuery;
use App\Support\Ulid;
use App\Validators\TourPackageValidator;
use Tests\Support\DbTestCase;

final class TourPackageServiceTest extends DbTestCase
{
    private const PREFIX = 'TPX ';

    private TourPackageService $service;
    private TourPackageRepository $repo;
    private TourPackageItemRepository $items;
    /** @var array<string,int> */
    private array $roles = [];
    /** @var list<int> */
    private array $userIds = [];
    private int $branch;

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) $this->db->selectValue('SELECT COUNT(*) FROM roles') === 0) {
            self::markTestSkipped('run php scripts/seed.php first');
        }
        foreach ($this->db->select('SELECT id, name FROM roles') as $r) {
            $this->roles[$r['name']] = (int) $r['id'];
        }
        $this->service = $this->app->get(TourPackageService::class);
        $this->repo = $this->app->get(TourPackageRepository::class);
        $this->items = $this->app->get(TourPackageItemRepository::class);
        $this->branch = (int) $this->db->insertRow('branches', [
            'public_id' => Ulid::generate(), 'name' => 'Branch TPX', 'code' => 'TPX-' . bin2hex(random_bytes(3)),
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->affectingStatement("DELETE FROM tour_packages WHERE name LIKE 'TPX %'"); // items cascade
        $this->db->affectingStatement("DELETE FROM activity_logs WHERE module = 'tours'");
        if ($this->userIds !== []) {
            $ph = implode(',', array_fill(0, count($this->userIds), '?'));
            $this->db->affectingStatement("DELETE FROM user_branches WHERE user_id IN ({$ph})", $this->userIds);
            $this->db->affectingStatement("DELETE FROM users WHERE id IN ({$ph})", $this->userIds);
        }
        $this->db->affectingStatement('DELETE FROM branches WHERE id = ?', [$this->branch]);
    }

    // ---- fixtures --------------------------------------------------

    private function actor(string $role = 'manager'): User
    {
        $id = (int) $this->db->insertRow('users', [
            'public_id' => Ulid::generate(), 'name' => "Actor {$role}", 'email' => 'tp_' . bin2hex(random_bytes(4)) . '@dev.local',
            'password_hash' => (new Hash(['algo' => PASSWORD_BCRYPT, 'bcrypt' => ['cost' => 4]]))->make('x'),
            'role_id' => $this->roles[$role], 'primary_branch_id' => $this->branch, 'is_active' => 1,
        ]);
        $this->db->insertRow('user_branches', ['user_id' => $id, 'branch_id' => $this->branch]);
        $this->userIds[] = $id;

        return $this->app->get(\App\Repositories\UserRepository::class)->findById($id);
    }

    /** @param array<string,mixed> $extra */
    private function data(array $extra = []): array
    {
        return (new TourPackageValidator())->validate($extra + [
            'name' => self::PREFIX . 'Dubai Discovery', 'destination' => 'Dubai, UAE', 'duration_days' => '5', 'duration_nights' => '4',
            'start_location' => 'Delhi', 'price' => '45000', 'currency' => 'inr',
        ]);
    }

    private function make(User $actor, array $extra = []): TourPackage
    {
        return $this->service->create($this->data($extra), $actor);
    }

    private function line(string $title = 'Desert safari', ?string $day = '2'): array
    {
        return (new TourPackageValidator())->item(['day_no' => $day ?? '', 'title' => $title]);
    }

    /** A package that is active, priced and has an itinerary — ready to publish. */
    private function ready(User $actor): TourPackage
    {
        $p = $this->make($actor);
        $this->service->addItem($p, $this->line(), $actor);

        return $this->service->changeStatus($this->repo->findById($p->id), 'active', $actor);
    }

    // ---- create / update -------------------------------------------------

    public function test_create_makes_a_private_draft_with_a_stable_unique_slug(): void
    {
        $actor = $this->actor();
        $a = $this->make($actor);
        $b = $this->make($actor);

        self::assertSame('draft', $a->status);
        self::assertFalse($a->isPublic);
        self::assertSame('INR', $a->currency);
        self::assertSame('45000.00', $a->price);
        self::assertSame('5 days / 4 nights', $a->durationLabel());
        self::assertSame('INR 45,000.00', $a->priceLabel());
        self::assertMatchesRegularExpression('/^tpx-dubai-discovery-[a-z0-9]{6}$/', $a->slug);
        self::assertNotSame($a->slug, $b->slug, 'two packages with the same name still get distinct slugs');
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='tours' AND action='created' AND record_id = ?", [$a->id]));

        $renamed = $this->service->update($a, $this->data(['name' => self::PREFIX . 'Dubai Deluxe']), $actor);
        self::assertSame(self::PREFIX . 'Dubai Deluxe', $renamed->name);
        self::assertSame($a->slug, $renamed->slug, 'renaming never breaks the public URL');
    }

    public function test_validator_rejects_bad_input_and_escapes_text(): void
    {
        $v = new TourPackageValidator();
        foreach ([
            'no name'                 => ['name' => '', 'destination' => 'X'],
            'no destination'          => ['name' => 'X', 'destination' => ''],
            'price without currency'  => ['name' => 'X', 'destination' => 'Y', 'price' => '100'],
            'bad currency'            => ['name' => 'X', 'destination' => 'Y', 'price' => '100', 'currency' => 'RUPEE'],
            'negative price'          => ['name' => 'X', 'destination' => 'Y', 'price' => '-5', 'currency' => 'INR'],
            'more nights than days'   => ['name' => 'X', 'destination' => 'Y', 'duration_days' => '3', 'duration_nights' => '5'],
            'zero days'               => ['name' => 'X', 'destination' => 'Y', 'duration_days' => '0'],
        ] as $why => $input) {
            try {
                $v->validate($input);
                self::fail("accepted: {$why}");
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }

        $ok = $v->validate(['name' => 'X', 'destination' => 'Y', 'inclusions' => "Hotel <script>alert(1)</script>\n\nBreakfast"]);
        self::assertStringNotContainsString('<script>', (string) $ok['inclusions_html']);
        self::assertStringContainsString('&lt;script&gt;', (string) $ok['inclusions_html']);
        self::assertNull($ok['price']);
        self::assertNull($ok['terms_html']);

        $this->expectException(ValidationException::class);
        $v->item(['title' => '', 'day_no' => '1']);
    }

    public function test_an_archived_package_is_read_only_until_reactivated(): void
    {
        $actor = $this->actor();
        $p = $this->make($actor);
        $p = $this->service->changeStatus($p, 'archived', $actor);

        foreach ([
            fn () => $this->service->update($p, $this->data(), $actor),
            fn () => $this->service->addItem($p, $this->line(), $actor),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('archived packages cannot be edited');
            } catch (DomainRuleException) {
                self::assertTrue(true);
            }
        }

        $p = $this->service->changeStatus($p, 'active', $actor);
        self::assertSame('active', $p->status);
        self::assertSame(self::PREFIX . 'Dubai Discovery', $this->service->update($p, $this->data(), $actor)->name);
    }

    // ---- lifecycle / publish -------------------------------------------

    public function test_status_moves_follow_the_machine(): void
    {
        $actor = $this->actor();
        $p = $this->make($actor);

        $p = $this->service->changeStatus($p, 'active', $actor);
        $p = $this->service->changeStatus($p, 'draft', $actor);
        $p = $this->service->changeStatus($p, 'archived', $actor);

        $this->expectException(DomainRuleException::class);
        $this->service->changeStatus($p, 'draft', $actor); // archived can only be reactivated
    }

    public function test_publishing_needs_an_active_priced_package_with_an_itinerary(): void
    {
        $actor = $this->actor();

        $draft = $this->make($actor);
        $this->service->addItem($draft, $this->line(), $actor);
        try {
            $this->service->setPublic($this->repo->findById($draft->id), true, $actor);
            self::fail('a draft cannot be public');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $noPrice = $this->service->changeStatus($this->make($actor, ['price' => '', 'currency' => '']), 'active', $actor);
        $this->service->addItem($noPrice, $this->line(), $actor);
        try {
            $this->service->setPublic($this->repo->findById($noPrice->id), true, $actor);
            self::fail('a package without a price cannot be public');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $noItems = $this->service->changeStatus($this->make($actor), 'active', $actor);
        try {
            $this->service->setPublic($noItems, true, $actor);
            self::fail('a package without an itinerary cannot be public');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $ready = $this->ready($actor);
        $public = $this->service->setPublic($ready, true, $actor);
        self::assertTrue($public->isPublic);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='tours' AND action='published' AND record_id = ?", [$ready->id]));
        self::assertFalse($this->service->setPublic($public, false, $actor)->isPublic);
    }

    public function test_leaving_active_withdraws_the_package_from_the_public_site(): void
    {
        $actor = $this->actor();
        $public = $this->service->setPublic($this->ready($actor), true, $actor);

        self::assertFalse($this->service->changeStatus($public, 'draft', $actor)->isPublic);

        $again = $this->service->setPublic($this->service->changeStatus($this->repo->findById($public->id), 'active', $actor), true, $actor);
        self::assertFalse($this->service->changeStatus($again, 'archived', $actor)->isPublic);
    }

    public function test_a_public_package_is_unpublished_when_it_loses_its_price_or_its_last_itinerary_line(): void
    {
        $actor = $this->actor();
        $public = $this->service->setPublic($this->ready($actor), true, $actor);

        $edited = $this->service->update($public, $this->data(['price' => '', 'currency' => '']), $actor);
        self::assertNull($edited->price);
        self::assertFalse($edited->isPublic, 'no price → no public page');

        $second = $this->service->setPublic($this->ready($actor), true, $actor);
        $only = $this->items->forPackage($second->id)[0];
        $this->service->removeItem($second, $only->id, $actor);
        self::assertFalse($this->repo->findById($second->id)->isPublic, 'no itinerary → no public page');
    }

    // ---- delete ----------------------------------------------------

    public function test_only_draft_or_archived_packages_can_be_deleted_and_deletion_is_soft(): void
    {
        $actor = $this->actor();
        $active = $this->ready($actor);

        try {
            $this->service->delete($active, $actor);
            self::fail('an active package must be archived first');
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }

        $archived = $this->service->changeStatus($active, 'archived', $actor);
        $this->service->delete($archived, $actor);

        self::assertNull($this->repo->findByPublicId($archived->publicId));
        self::assertSame(1, (int) $this->db->selectValue('SELECT COUNT(*) FROM tour_packages WHERE id = ? AND deleted_at IS NOT NULL AND is_public = 0', [$archived->id]), 'the row stays for history');
        self::assertSame(0, $this->repo->paginate(ListQuery::of(['search' => 'Dubai']))->total);
        self::assertTrue($this->db->exists("SELECT 1 FROM activity_logs WHERE module='tours' AND action='deleted' AND record_id = ?", [$archived->id]));
    }

    // ---- itinerary -------------------------------------------------

    public function test_itinerary_lines_are_ordered_by_day_then_insertion_and_undated_lines_come_last(): void
    {
        $actor = $this->actor();
        $p = $this->make($actor);
        foreach ([['Day three', '3'], ['Anytime extra', null], ['Day one', '1'], ['Day one again', '1']] as [$title, $day]) {
            $this->service->addItem($p, $this->line($title, $day), $actor);
        }

        self::assertSame(['Day one', 'Day one again', 'Day three', 'Anytime extra'], array_map(static fn ($i) => $i->title, $this->items->forPackage($p->id)));
        self::assertSame(4, $this->repo->findById($p->id)->itemCount);
    }

    public function test_removing_an_itinerary_line_is_scoped_to_its_package(): void
    {
        $actor = $this->actor();
        $a = $this->make($actor);
        $b = $this->make($actor);
        $this->service->addItem($a, $this->line('A line'), $actor);
        $this->service->addItem($b, $this->line('B line'), $actor);
        $bLine = $this->items->forPackage($b->id)[0];

        try {
            $this->service->removeItem($a, $bLine->id, $actor);
            self::fail("a line of another package must not be removable through this one");
        } catch (DomainRuleException) {
            self::assertTrue(true);
        }
        self::assertCount(1, $this->items->forPackage($b->id));

        $this->service->removeItem($b, $bLine->id, $actor);
        self::assertSame([], $this->items->forPackage($b->id));
    }

    public function test_the_itinerary_is_capped(): void
    {
        $actor = $this->actor();
        $p = $this->make($actor);
        for ($i = 0; $i < TourPackageService::MAX_ITEMS; $i++) {
            $this->items->add($p->id, null, "Line {$i}", null);
        }

        $this->expectException(DomainRuleException::class);
        $this->service->addItem($p, $this->line(), $actor);
    }

    // ---- permissions -----------------------------------------------

    public function test_permissions(): void
    {
        $manager = $this->actor();
        $p = $this->ready($manager);

        foreach (['read_only', 'counselor'] as $role) {
            $denied = $this->actor($role);
            foreach ([
                fn () => $this->service->create($this->data(), $denied),
                fn () => $this->service->update($p, $this->data(), $denied),
                fn () => $this->service->changeStatus($p, 'draft', $denied),
                fn () => $this->service->setPublic($p, true, $denied),
                fn () => $this->service->addItem($p, $this->line(), $denied),
                fn () => $this->service->delete($p, $denied),
            ] as $attempt) {
                try {
                    $attempt();
                    self::fail("{$role} must not manage tour packages");
                } catch (AuthorizationException) {
                    self::assertTrue(true);
                }
            }
        }

        // the travel-agency role manages the catalogue
        $agent = $this->actor('travel');
        $mine = $this->make($agent);
        self::assertSame('draft', $mine->status);
        $this->service->addItem($mine, $this->line(), $agent);
        self::assertSame(1, $this->repo->findById($mine->id)->itemCount);
    }

    // ---- read side -------------------------------------------------

    public function test_catalogue_search_filters_sorting_and_pick_list(): void
    {
        $actor = $this->actor();
        $dubai = $this->ready($actor);
        $this->make($actor, ['name' => self::PREFIX . 'Kerala Backwaters', 'destination' => 'Kochi, India', 'start_location' => 'Mumbai', 'price' => '18000']);
        $archived = $this->service->changeStatus($this->make($actor, ['name' => self::PREFIX . 'Old Nepal Trek', 'destination' => 'Pokhara', 'price' => '9000']), 'archived', $actor);
        $this->service->setPublic($dubai, true, $actor);

        $all = $this->repo->paginate(ListQuery::of(['search' => self::PREFIX]));
        self::assertSame(3, $all->total);

        self::assertSame(1, $this->repo->paginate(ListQuery::of(['search' => 'Kerala']))->total, 'search by name');
        self::assertSame(1, $this->repo->paginate(ListQuery::of(['search' => 'Pokhara']))->total, 'search by destination');
        self::assertSame(1, $this->repo->paginate(ListQuery::of(['search' => 'Mumbai']))->total, 'search by departure point');
        self::assertSame(0, $this->repo->paginate(ListQuery::of(['search' => 'nowhere-at-all']))->total);

        $status = fn (string $s): int => $this->repo->paginate(ListQuery::of(['search' => self::PREFIX, 'filters' => ['status' => $s]]))->total;
        self::assertSame(1, $status('active'));
        self::assertSame(1, $status('draft'));
        self::assertSame(1, $status('archived'));

        $vis = fn (string $v): int => $this->repo->paginate(ListQuery::of(['search' => self::PREFIX, 'filters' => ['visibility' => $v]]))->total;
        self::assertSame(1, $vis('public'));
        self::assertSame(2, $vis('private'));

        $cheapestFirst = $this->repo->paginate(ListQuery::of(['search' => self::PREFIX, 'sort' => 'price', 'direction' => 'asc']));
        self::assertSame(self::PREFIX . 'Old Nepal Trek', $cheapestFirst->items[0]->name);

        $options = $this->repo->activeOptions();
        self::assertArrayHasKey($dubai->publicId, $options);
        self::assertArrayNotHasKey($archived->publicId, $options, 'only active packages are offered for booking');
        self::assertSame(self::PREFIX . 'Dubai Discovery — Dubai, UAE', $options[$dubai->publicId]);
    }
}
