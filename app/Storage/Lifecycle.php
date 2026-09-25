<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Builds the bucket lifecycle rules that cut a storage bill without anyone lifting a finger:
 *
 *  - documents nobody has opened for a while move to a cheaper storage class (Standard-IA after `$infrequentAfterDays`, and on
 *    Amazon S3 also Glacier Instant Retrieval after `$archiveAfterDays` — still millisecond access, a fraction of the price);
 *  - half-finished multipart uploads (which are billed but invisible) are aborted after 7 days;
 *  - backups under their own prefix are deleted after the retention period, so they cannot grow forever.
 *
 * S3 will not transition objects to Standard-IA before 30 days, so smaller values are raised to 30.
 */
final class Lifecycle
{
    public static function xml(string $documentsPrefix, int $infrequentAfterDays, ?int $archiveAfterDays, ?string $backupsPrefix = null, ?int $backupRetentionDays = null): string
    {
        $ia = max(30, $infrequentAfterDays);
        $rules = '<Rule><ID>crm-documents-tier-down</ID><Filter><Prefix>' . self::x($documentsPrefix) . '</Prefix></Filter><Status>Enabled</Status>'
            . '<Transition><Days>' . $ia . '</Days><StorageClass>STANDARD_IA</StorageClass></Transition>';
        if ($archiveAfterDays !== null) {
            $rules .= '<Transition><Days>' . max($ia + 1, $archiveAfterDays) . '</Days><StorageClass>GLACIER_IR</StorageClass></Transition>';
        }
        $rules .= '</Rule>';

        if ($backupsPrefix !== null && $backupRetentionDays !== null) {
            $rules .= '<Rule><ID>crm-backups-expire</ID><Filter><Prefix>' . self::x($backupsPrefix) . '</Prefix></Filter><Status>Enabled</Status>'
                . '<Expiration><Days>' . max(1, $backupRetentionDays) . '</Days></Expiration></Rule>';
        }
        $rules .= '<Rule><ID>crm-abort-stale-uploads</ID><Filter><Prefix></Prefix></Filter><Status>Enabled</Status>'
            . '<AbortIncompleteMultipartUpload><DaysAfterInitiation>7</DaysAfterInitiation></AbortIncompleteMultipartUpload></Rule>';

        return '<?xml version="1.0" encoding="UTF-8"?><LifecycleConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/">' . $rules . '</LifecycleConfiguration>';
    }

    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
