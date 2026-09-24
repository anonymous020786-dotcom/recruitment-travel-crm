<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\Gate;
use App\Domain\StatusMachine;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Models\TourPackage;
use App\Models\User;
use App\Repositories\TourPackageItemRepository;
use App\Repositories\TourPackageRepository;
use App\Support\Db;
use App\Support\Slug;
use App\Support\Ulid;

/**
 * Tour package catalogue workflows. Lifecycle follows the `tour_package`
 * StatusMachine; "public" is only ever true while a package is `active`, has a
 * price and at least one itinerary line, and is cleared automatically on any move
 * away from `active` or on delete. An archived package is read-only.
 */
final class TourPackageService
{
    /** A package with more itinerary lines than this is almost certainly a mistake. */
    public const MAX_ITEMS = 60;

    private const DELETABLE = ['draft', 'archived'];

    public function __construct(
        private readonly Db $db,
        private readonly TourPackageRepository $packages,
        private readonly TourPackageItemRepository $items,
        private readonly StatusMachine $statuses,
        private readonly Gate $gate,
        private readonly AuditService $audit,
    ) {
    }

    /** @param array<string,mixed> $data validated TourPackageValidator::validate() output */
    public function create(array $data, User $actor): TourPackage
    {
        if (!$this->gate->forUser($actor)->allows('tours.packages.create')) {
            throw AuthorizationException::forPermission('tours.packages.create');
        }

        return $this->db->transaction(function () use ($data, $actor): TourPackage {
            $publicId = Ulid::generate();
            $id = $this->packages->create($data + [
                'public_id'  => $publicId,
                // The suffix keeps slugs unique without a lookup; the slug never changes when the package is renamed.
                'slug'       => Slug::make((string) $data['name'], 150) . '-' . strtolower(substr($publicId, -6)),
                'status'     => 'draft',
                'is_public'  => 0,
                'created_by' => $actor->id,
            ]);
            $this->audit->log('created', 'tours', 'tour_package', $id, null, ['name' => $data['name'], 'destination' => $data['destination']], null, $actor);

            return $this->reload($id);
        });
    }

    /** @param array<string,mixed> $data */
    public function update(TourPackage $package, array $data, User $actor): TourPackage
    {
        $this->authorize('update', $package, $actor, 'tours.packages.edit');
        $this->assertEditable($package);

        return $this->db->transaction(function () use ($package, $data, $actor): TourPackage {
            $this->packages->update($package->id, $data);
            $fresh = $this->reload($package->id);
            $this->audit->log('updated', 'tours', 'tour_package', $package->id, $this->snapshot($package), $this->snapshot($fresh), null, $actor);

            // A public package must stay complete: editing the price away would leave a broken public page.
            if ($fresh->isPublic && $fresh->price === null) {
                $this->packages->update($package->id, ['is_public' => 0]);
                $this->audit->log('unpublished', 'tours', 'tour_package', $package->id, null, null, 'Price was removed', $actor);
                $fresh = $this->reload($package->id);
            }

            return $fresh;
        });
    }

    public function changeStatus(TourPackage $package, string $to, User $actor): TourPackage
    {
        $this->authorize('update', $package, $actor, 'tours.packages.edit');
        $this->statuses->assert('tour_package', $package->status, $to);

        return $this->db->transaction(function () use ($package, $to, $actor): TourPackage {
            $extra = $to === 'active' ? [] : ['is_public' => 0];
            if ($this->packages->transition($package->id, $package->status, $to, $extra) === 0) {
                throw new StaleRecordException('tour_package', $package->publicId);
            }
            $this->audit->log('status_changed', 'tours', 'tour_package', $package->id, ['status' => $package->status], ['status' => $to], null, $actor);

            return $this->reload($package->id);
        });
    }

    public function setPublic(TourPackage $package, bool $public, User $actor): TourPackage
    {
        $this->authorize('publish', $package, $actor, 'tours.packages.publish');
        if ($public) {
            if ($package->status !== 'active') {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Only an active package can be published to the public site.', []);
            }
            if ($package->price === null) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Give the package a price before publishing it.', []);
            }
            if ($package->itemCount === 0) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Add at least one itinerary line before publishing the package.', []);
            }
        }

        $this->packages->update($package->id, ['is_public' => $public ? 1 : 0]);
        $this->audit->log($public ? 'published' : 'unpublished', 'tours', 'tour_package', $package->id, null, null, null, $actor);

        return $this->reload($package->id);
    }

    public function delete(TourPackage $package, User $actor): void
    {
        $this->authorize('delete', $package, $actor, 'tours.packages.delete');
        if (!in_array($package->status, self::DELETABLE, true)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Move an active package to draft or archive it before deleting it.', []);
        }
        if ($this->packages->softDelete($package->id) === 0) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Package not found.', [], 404);
        }
        $this->audit->log('deleted', 'tours', 'tour_package', $package->id, $this->snapshot($package), null, null, $actor);
    }

    /** @param array{day_no:?int,title:string,description:?string} $data from TourPackageValidator::item() */
    public function addItem(TourPackage $package, array $data, User $actor): void
    {
        $this->authorize('update', $package, $actor, 'tours.packages.edit');
        $this->assertEditable($package);
        if ($this->items->count($package->id) >= self::MAX_ITEMS) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A package can have at most ' . self::MAX_ITEMS . ' itinerary lines.', []);
        }

        $id = $this->items->add($package->id, $data['day_no'], $data['title'], $data['description']);
        $this->audit->log('item_added', 'tours', 'tour_package', $package->id, null, ['item_id' => $id, 'day_no' => $data['day_no'], 'title' => $data['title']], null, $actor);
    }

    /** @param array{day_no:?int,title:string,description:?string} $data from TourPackageValidator::item() */
    public function updateItem(TourPackage $package, int $itemId, array $data, User $actor): void
    {
        $this->authorize('update', $package, $actor, 'tours.packages.edit');
        $this->assertEditable($package);
        if (!$this->items->exists($itemId, $package->id)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Itinerary line not found.', [], 404);
        }

        $this->items->update($itemId, $package->id, $data['day_no'], $data['title'], $data['description']);
        $this->audit->log('item_updated', 'tours', 'tour_package', $package->id, null, ['item_id' => $itemId, 'day_no' => $data['day_no'], 'title' => $data['title']], null, $actor);
    }

    public function removeItem(TourPackage $package, int $itemId, User $actor): void
    {
        $this->authorize('update', $package, $actor, 'tours.packages.edit');
        $this->assertEditable($package);

        $this->db->transaction(function () use ($package, $itemId, $actor): void {
            if ($this->items->delete($itemId, $package->id) === 0) {
                throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Itinerary line not found.', [], 404);
            }
            $this->audit->log('item_removed', 'tours', 'tour_package', $package->id, ['item_id' => $itemId], null, null, $actor);

            // The last line going away leaves a public package with no itinerary.
            if ($package->isPublic && $this->items->count($package->id) === 0) {
                $this->packages->update($package->id, ['is_public' => 0]);
                $this->audit->log('unpublished', 'tours', 'tour_package', $package->id, null, null, 'Itinerary is empty', $actor);
            }
        });
    }

    // ---- internals -------------------------------------------------

    private function authorize(string $ability, TourPackage $package, User $actor, string $permission): void
    {
        if (!$this->gate->forUser($actor)->allows($ability, $package)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    private function assertEditable(TourPackage $package): void
    {
        if ($package->isArchived()) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'An archived package is read-only. Reactivate it to make changes.', []);
        }
    }

    private function reload(int $id): TourPackage
    {
        $package = $this->packages->findById($id);
        if ($package === null) {
            throw new \RuntimeException('Tour package vanished mid-operation.');
        }

        return $package;
    }

    /** @return array<string,mixed> */
    private function snapshot(TourPackage $p): array
    {
        return [
            'name' => $p->name, 'destination' => $p->destination, 'price' => $p->price, 'currency' => $p->currency,
            'duration_days' => $p->durationDays, 'status' => $p->status, 'is_public' => $p->isPublic,
        ];
    }
}
