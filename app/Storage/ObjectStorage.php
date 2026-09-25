<?php

declare(strict_types=1);

namespace App\Storage;

use App\Integrations\Credentials;
use App\Support\Logger;

/**
 * Where uploaded documents live: the server's private disk (the default) or an S3-compatible bucket (Amazon S3 or Cloudflare R2),
 * chosen by the super admin in Admin → Integrations → Storage. A document row remembers its own disk (`storage_disk`: private | s3 | r2),
 * so switching the active disk never strands existing files — old ones keep being served from where they are until they are moved.
 *
 * Fail-safe by design: an upload that cannot reach the bucket stays on the server disk (and is logged) rather than being lost or
 * failing the user's request; the migration tool moves it later.
 */
final class ObjectStorage
{
    public const LOCAL = 'private';
    public const REMOTE = ['s3', 'r2'];
    private const LOCAL_PREFIX = 'storage/private/';

    /** @var array<string,S3Client|null> */
    private array $clients = [];

    public function __construct(
        private readonly Credentials $credentials,
        private readonly Transport $transport,
        private readonly Logger $logger,
    ) {
    }

    /** The disk new uploads go to: the chosen remote provider if it is switched on and fully configured, else the server disk. */
    public function activeDisk(): string
    {
        $driver = (string) ($this->credentials->get('storage', 'driver') ?? self::LOCAL);

        return in_array($driver, self::REMOTE, true) && $this->client($driver) !== null ? $driver : self::LOCAL;
    }

    public static function isRemote(string $disk): bool
    {
        return in_array($disk, self::REMOTE, true);
    }

    /** 'redirect' (a short-lived signed link straight from the bucket — saves the server's bandwidth) or 'proxy' (bytes pass through PHP). */
    public function deliveryMode(): string
    {
        return $this->credentials->get('storage', 'delivery') === 'proxy' ? 'proxy' : 'redirect';
    }

    public function linkSeconds(): int
    {
        return max(30, min((int) ($this->credentials->get('storage', 'link_seconds') ?? 120), 3600));
    }

    /** The bucket key for a stored path: "storage/private/documents/…" becomes "documents/…" (the client adds its own prefix). */
    public static function key(string $storagePath): string
    {
        return str_starts_with($storagePath, self::LOCAL_PREFIX) ? substr($storagePath, strlen(self::LOCAL_PREFIX)) : ltrim($storagePath, '/');
    }

    /** A ready client for a provider, or null when it is switched off or missing a required field. */
    public function client(string $disk): ?S3Client
    {
        if (!self::isRemote($disk)) {
            return null;
        }
        if (array_key_exists($disk, $this->clients)) {
            return $this->clients[$disk];
        }
        $c = $this->credentials;
        if (!$c->isEnabled($disk) || !$c->isConfigured($disk)) {
            return $this->clients[$disk] = null;
        }
        $config = $disk === 'r2'
            ? ['host' => $c->get('r2', 'account_id') . '.r2.cloudflarestorage.com', 'region' => 'auto', 'path_style' => true]
            : ['host' => 's3.' . $c->get('s3', 'region') . '.amazonaws.com', 'region' => (string) $c->get('s3', 'region'), 'path_style' => false];
        $config += [
            'bucket' => (string) $c->get($disk, 'bucket'), 'key' => (string) $c->get($disk, 'access_key'), 'secret' => (string) $c->get($disk, 'secret_key'),
            'prefix' => (string) ($c->get($disk, 'prefix') ?? ''), 'storage_class' => (string) ($disk === 's3' ? ($c->get('s3', 'storage_class') ?? '') : ''),
        ];

        return $this->clients[$disk] = new S3Client($this->transport, $config);
    }

    /** Forget cached clients (after the credentials were changed). */
    public function reset(): void
    {
        $this->clients = [];
    }

    /** Upload a local file. False on any failure (the caller keeps the local copy). */
    public function put(string $disk, string $storagePath, string $absoluteFile, string $mime, string $sha256): bool
    {
        $client = $this->client($disk);
        if ($client === null) {
            return false;
        }
        $r = $client->put(self::key($storagePath), $absoluteFile, $mime, ['sha256' => $sha256], null, 'private, max-age=0, no-store');
        if (!$r['ok']) {
            $this->logger->warning('object storage upload failed ({disk} HTTP {status}: {error})', ['disk' => $disk, 'status' => $r['status'], 'error' => (string) $r['error']]);
        }

        return $r['ok'];
    }

    public function delete(string $disk, string $storagePath): bool
    {
        $client = $this->client($disk);

        return $client !== null && $client->delete(self::key($storagePath));
    }

    public function exists(string $disk, string $storagePath): bool
    {
        return $this->client($disk)?->head(self::key($storagePath)) !== null;
    }

    /** A short-lived direct-download URL, or null when the provider is not available. */
    public function signedUrl(string $disk, string $storagePath, string $downloadName, string $mime, bool $inline = false): ?string
    {
        return $this->client($disk)?->presignGet(self::key($storagePath), $this->linkSeconds(), $downloadName, $mime, $inline);
    }

    /** Download an object into a fresh temporary file (deleted by the caller). Null when it cannot be fetched. */
    public function fetchToTemp(string $disk, string $storagePath): ?string
    {
        $client = $this->client($disk);
        if ($client === null) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'obj');
        if ($tmp === false) {
            return null;
        }
        $r = $client->get(self::key($storagePath), $tmp);
        if (!$r['ok']) {
            @unlink($tmp);

            return null;
        }

        return $tmp;
    }
}
