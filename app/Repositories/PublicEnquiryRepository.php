<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

final class PublicEnquiryRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array{type:string,name:string,phone:string,email:?string,message:?string,
     *   job_id:?int,tour_package_id:?int,meta:array<string,mixed>,ip:?string} $data
     */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('public_enquiries', [
            'type'            => $data['type'],
            'job_id'          => $data['job_id'] ?? null,
            'tour_package_id' => $data['tour_package_id'] ?? null,
            'name'            => mb_substr($data['name'], 0, 150),
            'phone'           => mb_substr($data['phone'], 0, 30),
            'email'           => isset($data['email']) && $data['email'] !== '' ? mb_substr($data['email'], 0, 180) : null,
            'message'         => isset($data['message']) && $data['message'] !== '' ? mb_substr($data['message'], 0, 1000) : null,
            'meta_json'       => json_encode($data['meta'] ?? [], JSON_UNESCAPED_SLASHES),
            'ip_address'      => $data['ip'] ?? null,
            'status'          => 'new',
        ]);
    }

    /** Count recent submissions from an IP (anti-flood, in addition to rate-limit middleware). */
    public function recentFromIp(string $ipBinary, int $withinSeconds): int
    {
        return (int) $this->db->selectValue(
            'SELECT COUNT(*) FROM public_enquiries
             WHERE ip_address = :ip AND created_at >= (UTC_TIMESTAMP() - INTERVAL :s SECOND)',
            ['ip' => $ipBinary, 's' => $withinSeconds],
            0,
        );
    }
}
