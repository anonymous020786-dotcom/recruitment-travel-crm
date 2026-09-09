<?php

declare(strict_types=1);

namespace App\Audit;

use App\Auth\Auth;
use App\Http\Request;
use App\Models\User;
use App\Repositories\ActivityLogRepository;
use App\Support\Application;
use App\Support\Logger;
use Throwable;

/**
 * Writes business/security events to `activity_logs`. Called from services
 * INSIDE the same transaction as the change they describe.
 *
 * Actor / IP / user-agent / request-id are read from the current request +
 * auth context when available (web); on CLI/cron the actor is null ("system").
 * A failure to write the audit row is logged but never bubbles up to break the
 * business operation — except in strict mode.
 */
final class AuditService
{
    public function __construct(
        private readonly Application $app,
        private readonly ActivityLogRepository $repo,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string,mixed>|null $old
     * @param array<string,mixed>|null $new
     */
    public function log(
        string $action,
        string $module,
        string $recordType,
        int|string|null $recordId = null,
        ?array $old = null,
        ?array $new = null,
        ?string $context = null,
        ?User $actor = null,
    ): void {
        [$userId, $ip, $ua] = $this->context($actor);

        try {
            $this->repo->insert(
                $userId,
                $action,
                $module,
                $recordType,
                $recordId,
                $this->prune($old),
                $this->prune($new),
                $ip,
                $ua,
                $this->withRequestId($context),
            );
        } catch (Throwable $e) {
            $this->logger->error('audit write failed for {module}.{action}', [
                'module' => $module, 'action' => $action, 'record' => $recordId, 'exception' => $e,
            ]);
        }
    }

    /** Convenience: a create/update/delete diff on a record. */
    public function recorded(string $module, string $recordType, int|string $recordId, string $action, ?array $old, ?array $new): void
    {
        $this->log($action, $module, $recordType, $recordId, $old, $new);
    }

    /** @return array{0:?int,1:?string,2:?string} */
    private function context(?User $actor): array
    {
        $userId = $actor?->id;
        $ip = null;
        $ua = null;

        if ($this->app->bound(Request::class)) {
            $request = $this->app->get(Request::class);
            $ip = $request->ipBinary();
            $ua = $request->userAgent();
        }

        if ($userId === null && $this->app->bound(Auth::class)) {
            $userId = $this->app->get(Auth::class)->id();
        }

        return [$userId, $ip, $ua];
    }

    private function withRequestId(?string $context): ?string
    {
        if (!$this->app->bound(Request::class)) {
            return $context;
        }
        $rid = $this->app->get(Request::class)->attribute('request_id');
        if ($rid === null) {
            return $context;
        }

        return $context !== null ? "{$context} [req:{$rid}]" : "req:{$rid}";
    }

    /** Drop noisy / sensitive keys from a value snapshot before it is stored. */
    private function prune(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $drop = ['password', 'password_hash', 'remember_token', '_token', 'token_hash'];
        foreach ($drop as $key) {
            unset($values[$key]);
        }

        return $values;
    }
}
