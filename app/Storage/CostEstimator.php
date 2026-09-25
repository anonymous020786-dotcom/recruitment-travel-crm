<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Compares the monthly cost of keeping the same documents in different places, from the provider list prices in
 * config/storage_pricing.php. Pure arithmetic — no network — so the numbers are reproducible and testable.
 *
 * The lifecycle scenarios assume the share of data older than the threshold (`coldShare`, measured from the real documents)
 * moves to the cheaper class(es) and that only fresh data is read; the plain scenarios keep everything on Standard.
 */
final class CostEstimator
{
    /**
     * @param array{as_of:string,currency:string,providers:array<string,array<string,mixed>>} $pricing
     * @param float $totalGb         data stored
     * @param float $readShare       fraction of the stored data downloaded each month (0–1)
     * @param float $coldShare       fraction of the data older than the tier-down age (0–1)
     * @param int   $newFilesPerMonth uploads per month
     * @param int   $readsPerMonth   downloads per month
     * @return list<array{key:string,label:string,storage:float,egress:float,requests:float,total:float,saving:float,note:string}>
     */
    public static function compare(array $pricing, float $totalGb, float $readShare, float $coldShare, int $newFilesPerMonth, int $readsPerMonth): array
    {
        $readShare = max(0.0, min(1.0, $readShare));
        $coldShare = max(0.0, min(1.0, $coldShare));
        $totalGb = max(0.0, $totalGb);
        $s3 = $pricing['providers']['s3'];
        $r2 = $pricing['providers']['r2'];

        $rows = [
            self::row('s3-standard', $s3['label'] . ' — Standard', $totalGb * $s3['standard'], self::egress($s3, $totalGb * $readShare), self::requests($s3, $newFilesPerMonth, $readsPerMonth), 'Everything on the standard tier.'),
            self::row('s3-lifecycle', $s3['label'] . ' — with cost-saving rules',
                $totalGb * (1 - $coldShare) * $s3['standard'] + $totalGb * $coldShare * (0.35 * $s3['infrequent'] + 0.65 * $s3['archive']),
                self::egress($s3, $totalGb * (1 - $coldShare) * $readShare), self::requests($s3, $newFilesPerMonth, $readsPerMonth),
                'Old documents move to Standard-IA, then Glacier Instant Retrieval (still opens instantly).'),
            self::row('r2-standard', $r2['label'] . ' — Standard', max(0.0, $totalGb - $r2['free_gb']) * $r2['standard'], 0.0, self::requests($r2, $newFilesPerMonth, $readsPerMonth), 'No charge for downloads (egress).'),
            self::row('r2-lifecycle', $r2['label'] . ' — with cost-saving rules',
                max(0.0, $totalGb * (1 - $coldShare) - $r2['free_gb']) * $r2['standard'] + $totalGb * $coldShare * $r2['infrequent'], 0.0, self::requests($r2, $newFilesPerMonth, $readsPerMonth),
                'Old documents move to Infrequent Access.'),
        ];

        $baseline = $rows[0]['total'];
        foreach ($rows as &$r) {
            $r['saving'] = round($baseline - $r['total'], 2);
        }
        unset($r);

        return $rows;
    }

    /** @param array<string,mixed> $p */
    private static function egress(array $p, float $gb): float
    {
        return max(0.0, $gb - (float) $p['egress_free_gb']) * (float) $p['egress'];
    }

    /** @param array<string,mixed> $p */
    private static function requests(array $p, int $puts, int $gets): float
    {
        return $puts / 1000 * (float) $p['put_per_1k'] + $gets / 1000 * (float) $p['get_per_1k'];
    }

    /** @return array{key:string,label:string,storage:float,egress:float,requests:float,total:float,saving:float,note:string} */
    private static function row(string $key, string $label, float $storage, float $egress, float $requests, string $note): array
    {
        return ['key' => $key, 'label' => $label, 'storage' => round($storage, 2), 'egress' => round($egress, 2), 'requests' => round($requests, 2),
            'total' => round($storage + $egress + $requests, 2), 'saving' => 0.0, 'note' => $note];
    }
}
