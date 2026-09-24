<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Http\Response;
use App\Services\CronHealthService;
use App\Services\IntegrityService;

/** Admin screen for the scheduled jobs: state of each, recent runs of one, and the last data-integrity result. */
final class CronController extends CrmController
{
    public function __construct(
        private readonly CronHealthService $health,
        private readonly IntegrityService $integrity,
    ) {
    }

    public function index(): Response
    {
        $jobs = $this->health->overview($this->now());

        return view_response('crm.admin.cron.index', [
            'jobs' => $jobs,
            'unhealthy' => count(array_filter($jobs, static fn (array $j): bool => in_array($j['state'], CronHealthService::UNHEALTHY, true))),
            'integrity' => $this->integrity->last(),
        ]);
    }

    public function show(string $job): Response
    {
        if (!$this->health->has($job)) {
            abort(404, 'Unknown job.');
        }
        $overview = array_values(array_filter(
            $this->health->overview($this->now()),
            static fn (array $j): bool => $j['job'] === $job,
        ))[0];

        return view_response('crm.admin.cron.show', ['job' => $overview, 'runs' => $this->health->history($job)]);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
