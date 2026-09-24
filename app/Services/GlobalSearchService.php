<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\BranchScope;
use App\Auth\Gate;
use App\Models\User;
use App\Repositories\ApplicationRepository;
use App\Repositories\CandidateRepository;
use App\Repositories\EmployerRepository;
use App\Repositories\InvoiceRepository;
use App\Repositories\JobRepository;
use App\Repositories\LeadRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\PublicEnquiryRepository;
use App\Repositories\TourBookingRepository;
use App\Support\ListQuery;

/**
 * One search box across the CRM. It does no querying of its own: each section calls the SAME scoped `paginate()` that
 * powers that module's list screen, so branch isolation, soft-delete rules and search semantics are exactly those of the
 * module, and a section the user has no permission for is never even queried.
 */
final class GlobalSearchService
{
    public const MIN_LENGTH = 2;
    public const PER_SECTION = 5;

    public function __construct(
        private readonly Gate $gate,
        private readonly LeadRepository $leads,
        private readonly CandidateRepository $candidates,
        private readonly ApplicationRepository $applications,
        private readonly EmployerRepository $employers,
        private readonly JobRepository $jobs,
        private readonly InvoiceRepository $invoices,
        private readonly PaymentRepository $payments,
        private readonly TourBookingRepository $bookings,
        private readonly PublicEnquiryRepository $enquiries,
    ) {
    }

    public static function normalise(string $q): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $q)), 0, 60);
    }

    /**
     * @return list<array{key:string,title:string,listUrl:string,total:int,hits:list<array{title:string,subtitle:string,url:string}>}> sections with at least one hit
     */
    public function search(string $q, User $user, BranchScope $scope): array
    {
        $q = self::normalise($q);
        if (mb_strlen($q) < self::MIN_LENGTH) {
            return [];
        }
        $can = $this->gate->forUser($user);
        $query = ListQuery::of(['search' => $q, 'perPage' => self::PER_SECTION]);
        $term = '?q=' . rawurlencode($q);
        $sections = [];

        $add = function (string $permission, string $key, string $title, string $listUrl, callable $run, callable $hit) use (&$sections, $can, $term): void {
            if (!$can->allows($permission)) {
                return;
            }
            $page = $run();
            if ($page->total > 0) {
                $sections[] = ['key' => $key, 'title' => $title, 'listUrl' => $listUrl . $term, 'total' => $page->total, 'hits' => array_map($hit, $page->items)];
            }
        };

        $add('leads.view', 'leads', 'Leads', '/leads', fn () => $this->leads->paginate($query, $scope),
            static fn ($l): array => ['title' => $l->name, 'subtitle' => $l->leadNumber . ' · ' . $l->phone, 'url' => '/leads/' . $l->publicId]);
        $add('candidates.view', 'candidates', 'Candidates', '/candidates', fn () => $this->candidates->paginate($query, $scope),
            static fn ($c): array => ['title' => $c->fullName, 'subtitle' => $c->candidateNumber . ($c->primaryPhone ? ' · ' . $c->primaryPhone : ''), 'url' => '/candidates/' . $c->publicId]);
        $add('applications.view', 'applications', 'Applications', '/applications', fn () => $this->applications->paginate($query, $scope),
            static fn ($a): array => ['title' => $a->candidateName . ' — ' . $a->jobTitle, 'subtitle' => $a->applicationNumber, 'url' => '/applications/' . $a->publicId]);
        $add('employers.view', 'employers', 'Employers', '/employers', fn () => $this->employers->paginate($query, $scope),
            static fn ($e): array => ['title' => $e->companyName, 'subtitle' => $e->employerNumber, 'url' => '/employers/' . $e->publicId]);
        $add('jobs.view', 'jobs', 'Jobs', '/jobs', fn () => $this->jobs->paginate($query, $scope),
            static fn ($j): array => ['title' => $j->title, 'subtitle' => $j->jobNumber . ' · ' . $j->employerName, 'url' => '/jobs/' . $j->publicId]);
        $add('invoices.view', 'invoices', 'Invoices', '/invoices', fn () => $this->invoices->paginate($query, $scope),
            static fn ($i): array => ['title' => $i->invoiceNumber, 'subtitle' => $i->customerName, 'url' => '/invoices/' . $i->publicId]);
        $add('payments.view', 'payments', 'Payments', '/payments', fn () => $this->payments->paginate($query, $scope),
            static fn ($p): array => ['title' => $p->paymentNumber, 'subtitle' => $p->customerName, 'url' => '/payments/' . $p->publicId]);
        $add('tours.bookings.view', 'bookings', 'Tour bookings', '/tours/bookings', fn () => $this->bookings->paginate($query, $scope),
            static fn ($b): array => ['title' => $b->bookingNumber, 'subtitle' => $b->customerName . ($b->packageName ? ' · ' . $b->packageName : ''), 'url' => '/tours/bookings/' . $b->publicId]);
        // Website enquiries belong to no branch (a visitor has none): permission alone decides.
        $add('public_enquiries.view', 'enquiries', 'Website enquiries', '/enquiries', fn () => $this->enquiries->paginate(ListQuery::of(['search' => $q, 'perPage' => self::PER_SECTION, 'sort' => 'created_at'])),
            static fn (array $e): array => ['title' => (string) $e['name'], 'subtitle' => (string) $e['phone'] . ' · ' . ucfirst((string) $e['status']), 'url' => '/enquiries/' . (int) $e['id']]);

        return $sections;
    }
}
