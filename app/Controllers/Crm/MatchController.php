<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\AuthorizationException;
use App\Http\Response;
use App\Repositories\JobRepository;
use App\Services\MatchService;

/** "Best-matching candidates" for a job, with the explainable score breakdown. */
final class MatchController extends CrmController
{
    public function __construct(
        private readonly JobRepository $jobs,
        private readonly MatchService $matches,
    ) {
    }

    public function forJob(string $job): Response
    {
        $model = $this->jobs->findByPublicId($job, $this->scope());
        if ($model === null) {
            abort(404, 'Job not found.');
        }

        try {
            $rows = $this->matches->rankCandidatesForJob($model, $this->currentUser());
        } catch (AuthorizationException) {
            abort(403);
        }

        return view_response('crm.jobs.matches', [
            'job' => $model,
            'rows' => $rows,
            'canApply' => can('applications.create') && $model->status === 'open',
        ]);
    }
}
