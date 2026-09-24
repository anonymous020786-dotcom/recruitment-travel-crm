<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Domain\StatusMachine;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Models\TourPackage;
use App\Repositories\TourPackageItemRepository;
use App\Repositories\TourPackageRepository;
use App\Services\TourPackageService;
use App\Support\ListQuery;
use App\Validators\TourPackageValidator;

/** Tour package screens: catalogue, create/edit, itinerary, lifecycle and publish actions. */
final class TourPackageController extends CrmController
{
    private const FIELDS = [
        'name', 'destination', 'duration_days', 'duration_nights', 'start_location', 'price', 'currency',
        'hotel_summary', 'transport_summary', 'meals_summary', 'inclusions', 'exclusions', 'terms',
    ];

    public function __construct(
        private readonly TourPackageRepository $packages,
        private readonly TourPackageItemRepository $items,
        private readonly TourPackageService $service,
        private readonly StatusMachine $statuses,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, TourPackageRepository::SORT, TourPackageRepository::FILTER_KEYS, 'created_at');

        return view_response('crm.tours.packages.index', [
            'page'      => $this->packages->paginate($query),
            'query'     => $query,
            'canCreate' => can('tours.packages.create'),
        ]);
    }

    public function create(): Response
    {
        return view_response('crm.tours.packages.create');
    }

    public function store(Request $request): Response
    {
        try {
            $package = $this->service->create((new TourPackageValidator())->validate($request->only(self::FIELDS)), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/tours/packages/create');
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You cannot create tour packages.');

            return redirect_with_errors([], $request->all(), '/tours/packages/create');
        }

        flash('status', "Package “{$package->name}” created as a draft.");

        return Response::redirect('/tours/packages/' . $package->publicId);
    }

    public function show(string $package): Response
    {
        $model = $this->find($package);
        authorize('view', $model);

        return view_response('crm.tours.packages.show', [
            'package'      => $model,
            'items'        => $this->items->forPackage($model->id),
            'canEdit'      => can('update', $model),
            'canDelete'    => can('delete', $model),
            'canPublish'   => can('publish', $model),
            'nextStatuses' => $this->statuses->transitionsFrom('tour_package', $model->status),
        ]);
    }

    public function edit(string $package): Response
    {
        $model = $this->find($package);
        authorize('update', $model);

        return view_response('crm.tours.packages.edit', ['package' => $model]);
    }

    public function update(Request $request, string $package): Response
    {
        $model = $this->find($package);
        authorize('update', $model);

        try {
            $this->service->update($model, (new TourPackageValidator())->validate($request->only(self::FIELDS)), $this->currentUser());
        } catch (ValidationException $e) {
            return redirect_with_errors($e->errors(), $request->all(), '/tours/packages/' . $model->publicId . '/edit');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return Response::redirect('/tours/packages/' . $model->publicId);
        }

        flash('status', 'Package updated.');

        return Response::redirect('/tours/packages/' . $model->publicId);
    }

    public function changeStatus(Request $request, string $package): Response
    {
        $model = $this->find($package);

        return $this->run($model, 'Package status updated.', function () use ($model, $request): void {
            $this->service->changeStatus($model, (string) $request->input('status', ''), $this->currentUser());
        });
    }

    public function publish(Request $request, string $package): Response
    {
        $model = $this->find($package);
        $on = $request->boolean('public');

        return $this->run($model, $on ? 'Package published.' : 'Package unpublished.', function () use ($model, $on): void {
            $this->service->setPublic($model, $on, $this->currentUser());
        });
    }

    public function destroy(string $package): Response
    {
        $model = $this->find($package);

        try {
            $this->service->delete($model, $this->currentUser());
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());

            return Response::redirect('/tours/packages/' . $model->publicId);
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to delete tour packages.');

            return Response::redirect('/tours/packages/' . $model->publicId);
        }

        flash('status', 'Package deleted.');

        return Response::redirect('/tours/packages');
    }

    public function storeItem(Request $request, string $package): Response
    {
        $model = $this->find($package);

        return $this->run($model, 'Itinerary line added.', function () use ($model, $request): void {
            $this->service->addItem($model, (new TourPackageValidator())->item($request->only(['day_no', 'title', 'description'])), $this->currentUser());
        }, '#itinerary');
    }

    public function updateItem(Request $request, string $package, string $item): Response
    {
        $model = $this->find($package);

        return $this->run($model, 'Itinerary line saved.', function () use ($model, $request, $item): void {
            $this->service->updateItem($model, (int) $item, (new TourPackageValidator())->item($request->only(['day_no', 'title', 'description'])), $this->currentUser());
        }, '#itinerary');
    }

    public function destroyItem(string $package, string $item): Response
    {
        $model = $this->find($package);

        return $this->run($model, 'Itinerary line removed.', function () use ($model, $item): void {
            $this->service->removeItem($model, (int) $item, $this->currentUser());
        }, '#itinerary');
    }

    // ---- internals -------------------------------------------------

    /** Run an action, flash the outcome, and return to the package page. */
    private function run(TourPackage $model, string $success, callable $do, string $anchor = ''): Response
    {
        try {
            $do();
            flash('status', $success);
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Please check the details.');
        } catch (StaleRecordException) {
            session()?->flash('error_toast', 'This package changed just now. Please review it and try again.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to do that.');
        }

        return Response::redirect('/tours/packages/' . $model->publicId . $anchor);
    }

    private function find(string $publicId): TourPackage
    {
        $model = $this->packages->findByPublicId($publicId);
        if ($model === null) {
            abort(404, 'Tour package not found.');
        }

        return $model;
    }
}
