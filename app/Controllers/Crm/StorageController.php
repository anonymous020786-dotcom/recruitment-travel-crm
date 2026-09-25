<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Http\Request;
use App\Http\Response;
use App\Integrations\Credentials;
use App\Repositories\StorageUsageRepository;
use App\Storage\CostEstimator;
use App\Storage\DocumentMigrator;
use App\Storage\Lifecycle;
use App\Storage\ObjectStorage;

/**
 * Admin → Storage: where documents live, what it costs, and the tools to move to (and economise on) an S3 or R2 bucket.
 * Viewing needs `integrations.view`; every action needs `integrations.manage` (and a fresh password confirmation for the ones
 * that change the bucket or move files).
 */
final class StorageController extends CrmController
{
    public function __construct(
        private readonly ObjectStorage $objects,
        private readonly Credentials $credentials,
        private readonly StorageUsageRepository $usage,
        private readonly DocumentMigrator $migrator,
    ) {
    }

    public function index(Request $request): Response
    {
        $gb = static fn (int $bytes): float => $bytes / 1_073_741_824;
        $byDisk = $this->usage->byDisk();
        $totalBytes = array_sum(array_column($byDisk, 'bytes'));
        $infrequentDays = max(30, (int) ($this->credentials->get('storage', 'infrequent_after_days') ?? 90));
        $coldShare = $totalBytes > 0 ? $this->usage->bytesOlderThan($infrequentDays) / $totalBytes : 0.7;

        // the estimate's two knobs come from the form (or from the real download history), never from anything unvalidated
        $readPercent = $request->query('read_percent');
        $readShare = is_numeric($readPercent) ? max(0.0, min(100.0, (float) $readPercent)) / 100 : min(1.0, $this->usage->readsPerMonth() / max(1, array_sum(array_column($byDisk, 'count'))));
        $newFiles = is_numeric($request->query('new_files')) ? max(0, min(1_000_000, (int) $request->query('new_files'))) : $this->usage->uploadsPerMonth();
        $reads = (int) round(array_sum(array_column($byDisk, 'count')) * $readShare);

        $providers = [];
        foreach (['s3', 'r2'] as $p) {
            $providers[$p] = ['label' => (string) $this->credentials->service($p)['label']] + $this->credentials->status($p);
        }

        return view_response('crm.admin.storage.index', [
            'active' => $this->objects->activeDisk(), 'chosen' => (string) ($this->credentials->get('storage', 'driver') ?? ObjectStorage::LOCAL),
            'delivery' => $this->objects->deliveryMode(), 'byDisk' => $byDisk, 'providers' => $providers, 'pending' => $this->migrator->pending(),
            'estimate' => CostEstimator::compare((array) config('storage_pricing'), $gb($totalBytes), $readShare, $coldShare, $newFiles, $reads),
            'pricing' => ['as_of' => (string) config('storage_pricing.as_of'), 'currency' => (string) config('storage_pricing.currency'), 'providers' => (array) config('storage_pricing.providers')],
            'inputs' => ['read_percent' => round($readShare * 100, 1), 'new_files' => $newFiles, 'cold_percent' => round($coldShare * 100, 1), 'total_gb' => round($gb($totalBytes), 3)],
            'canManage' => can('integrations.manage'),
        ])->withHeader('Cache-Control', 'no-store, private');
    }

    public function test(Request $request): Response
    {
        $provider = (string) $request->input('provider', '');
        $client = in_array($provider, ObjectStorage::REMOTE, true) ? $this->objects->client($provider) : null;
        if ($client === null) {
            session()?->flash('error_toast', 'That provider is not fully configured (or is switched off). Fill in its keys under Integrations first.');
        } else {
            $r = $client->checkBucket();
            $r['ok'] ? flash('status', $r['message']) : session()?->flash('error_toast', $r['message']);
        }

        return Response::redirect('/admin/storage');
    }

    public function lifecycle(Request $request): Response
    {
        $disk = $this->objects->activeDisk();
        $client = $this->objects->client($disk);
        if ($client === null) {
            session()?->flash('error_toast', 'Choose S3 or R2 as the storage location and configure it first.');

            return Response::redirect('/admin/storage');
        }
        $ia = (int) ($this->credentials->get('storage', 'infrequent_after_days') ?? 90);
        $archive = $disk === 's3' ? (int) ($this->credentials->get('storage', 'archive_after_days') ?? 365) : null;
        $retention = (int) ($this->credentials->get('storage', 'backup_retention_days') ?? 30);
        $xml = Lifecycle::xml($client->fullKey('documents/'), $ia, $archive, $client->fullKey('backups/'), $retention);

        $r = $client->putLifecycle($xml);
        $r['ok']
            ? flash('status', 'Cost-saving rules applied: documents move to cheaper storage after ' . max(30, $ia) . ' days' . ($archive !== null ? ' and to the archive tier later' : '') . '; backups are kept ' . max(1, $retention) . ' days; unfinished uploads are cleaned up.')
            : session()?->flash('error_toast', 'The bucket refused the rules (' . ($r['error'] ?? 'HTTP ' . $r['status']) . '). Check that the key may change the bucket lifecycle.');

        return Response::redirect('/admin/storage');
    }

    public function migrate(): Response
    {
        $r = $this->migrator->moveBatch(25, false, $this->currentUser());
        if (isset($r['error'])) {
            session()?->flash('error_toast', $r['error']);
        } elseif ($r['moved'] === 0 && $r['failed'] === 0 && $r['missing'] === 0) {
            flash('status', 'Nothing left to move — every document is already in the bucket.');
        } else {
            flash('status', sprintf('Moved %d document%s (%s). %d failed, %d missing on disk. %d still on the server — run it again to continue.', $r['moved'], $r['moved'] === 1 ? '' : 's', $this->size($r['bytes']), $r['failed'], $r['missing'], $r['remaining']));
        }

        return Response::redirect('/admin/storage');
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1_048_576 ? number_format($bytes / 1_048_576, 1) . ' MB' : number_format($bytes / 1024, 0) . ' KB';
    }
}
