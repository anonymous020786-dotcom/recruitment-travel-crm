<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\PublicEnquiryRepository;
use App\Services\EnquiryInboxService;
use App\Support\Db;
use App\Support\ListQuery;

/** The website enquiry inbox: contact messages, job applications and package enquiries submitted on the public site. */
final class EnquiryController extends CrmController
{
    public function __construct(
        private readonly PublicEnquiryRepository $enquiries,
        private readonly EnquiryInboxService $inbox,
        private readonly Db $db,
    ) {
    }

    public function index(Request $request): Response
    {
        // Default to the work queue: new enquiries first.
        $query = ListQuery::fromRequest($request, PublicEnquiryRepository::SORT, PublicEnquiryRepository::FILTER_KEYS, 'created_at');
        if ($query->filter('status') === null && $request->query('status') === null) {
            $query = ListQuery::of(['page' => $query->page, 'perPage' => $query->perPage, 'sort' => $query->sort, 'direction' => $query->direction, 'search' => $query->search, 'filters' => $query->filters + ['status' => 'new']]);
        }

        return view_response('crm.enquiries.index', [
            'page'   => $this->enquiries->paginate($query),
            'query'  => $query,
            'counts' => $this->enquiries->counts(),
        ]);
    }

    public function show(string $enquiry): Response
    {
        $row = $this->find($enquiry);
        [$branchSql, $bind] = $this->scope()->whereClause('id');

        return view_response('crm.enquiries.show', [
            'e'          => $row,
            'meta'       => json_decode((string) ($row['meta_json'] ?? '{}'), true) ?: [],
            'canConvert' => can('public_enquiries.convert') && $row['status'] !== 'converted',
            'branches'   => $this->db->select("SELECT id, name FROM branches WHERE is_active = 1 AND {$branchSql} ORDER BY name", $bind),
            'primaryBranch' => $this->currentUser()->primaryBranchId,
        ]);
    }

    public function status(Request $request, string $enquiry): Response
    {
        $row = $this->find($enquiry);

        try {
            $this->inbox->setStatus((int) $row['id'], (string) $request->input('from', ''), (string) $request->input('to', ''), $this->currentUser());
            flash('status', 'Enquiry updated.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect($this->back($request, (int) $row['id']));
    }

    public function convert(Request $request, string $enquiry): Response
    {
        $row = $this->find($enquiry);
        $branchId = ctype_digit((string) $request->input('branch_id', '')) ? (int) $request->input('branch_id') : $this->currentUser()->primaryBranchId;

        try {
            if ($branchId === null || !$this->scope()->contains($branchId)) {
                throw new DomainRuleException('ENQUIRY_BRANCH', 'Choose a branch for the new lead.', [], 422);
            }
            $r = $this->inbox->convert((int) $row['id'], (string) $row['status'], $branchId, $this->currentUser());
            flash('status', $r['created'] ? "Lead {$r['lead_number']} created." : "Already a lead — linked to {$r['lead_number']}.");
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to create leads.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/enquiries/' . (int) $row['id']);
    }

    // ---- internals -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function find(string $id): array
    {
        $row = ctype_digit($id) ? $this->enquiries->find((int) $id) : null;
        if ($row === null) {
            abort(404, 'Enquiry not found.');
        }

        return $row;
    }

    private function back(Request $request, int $id): string
    {
        return $request->input('return') === 'list' ? '/enquiries' : '/enquiries/' . $id;
    }
}
