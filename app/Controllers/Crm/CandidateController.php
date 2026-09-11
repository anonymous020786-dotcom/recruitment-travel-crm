<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Http\Request;
use App\Http\Response;
use App\Repositories\CandidateRepository;
use App\Support\ListQuery;

/**
 * Minimal candidate screens. Candidates are created only via lead conversion
 * for now (see LeadService::convert()); the full profile — education,
 * experience, skills, passport, documents — is a later phase.
 */
final class CandidateController extends CrmController
{
    public function __construct(private readonly CandidateRepository $candidates)
    {
    }

    public function index(Request $request): Response
    {
        $query = ListQuery::fromRequest($request, CandidateRepository::SORT, CandidateRepository::FILTER_KEYS, 'created_at');
        $page = $this->candidates->paginate($query, $this->scope());

        return view_response('crm.candidates.index', [
            'page'   => $page,
            'query'  => $query,
            'stages' => $this->candidates->stageOptions(),
        ]);
    }

    public function show(Request $request, string $candidate): Response
    {
        $model = $this->candidates->findByPublicId($candidate, $this->scope());
        if ($model === null) {
            abort(404, 'Candidate not found.');
        }

        return view_response('crm.candidates.show', ['candidate' => $model]);
    }
}
