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

    public const SORT = ['created_at' => 'e.created_at', 'name' => 'e.name', 'status' => 'e.status', 'type' => 'e.type'];
    public const FILTER_KEYS = ['status', 'type'];
    public const STATUSES = ['new', 'reviewed', 'converted', 'spam'];

    private const COLUMNS = 'e.id, e.type, e.name, e.phone, e.email, e.message, e.meta_json, e.status, e.lead_id, e.created_at, e.job_id, j.country AS job_country,
        j.title AS job_title, j.slug AS job_slug, p.name AS package_name, p.slug AS package_slug, l.lead_number, l.public_id AS lead_public_id';

    private const JOINS = 'FROM public_enquiries e
        LEFT JOIN jobs j ON j.id = e.job_id
        LEFT JOIN tour_packages p ON p.id = e.tour_package_id
        LEFT JOIN leads l ON l.id = e.lead_id';

    /**
     * Enquiries are organisation-wide (a visitor belongs to no branch), so access is by permission, not branch scope.
     *
     * @return \App\Support\Page<array<string,mixed>>
     */
    public function paginate(\App\Support\ListQuery $q): \App\Support\Page
    {
        $conds = ['1 = 1'];
        $bind = [];
        if (($status = $q->filter('status')) !== null && in_array($status, self::STATUSES, true)) {
            $conds[] = 'e.status = :f_status';
            $bind['f_status'] = $status;
        }
        if (($type = $q->filter('type')) !== null && in_array($type, ['contact', 'job_apply', 'travel_enquiry'], true)) {
            $conds[] = 'e.type = :f_type';
            $bind['f_type'] = $type;
        }
        if ($q->hasSearch()) {
            $conds[] = '(e.name LIKE :s_name OR e.phone LIKE :s_phone OR e.email LIKE :s_email)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
            $bind['s_name'] = $bind['s_phone'] = $bind['s_email'] = $like;
        }
        $where = implode(' AND ', $conds);

        $total = (int) $this->db->selectValue("SELECT COUNT(*) FROM public_enquiries e WHERE {$where}", $bind);
        $order = (self::SORT[$q->sort] ?? 'e.created_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' ' . self::JOINS . " WHERE {$where} ORDER BY {$order}, e.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new \App\Support\Page($rows, $total, $q->page, $q->perPage);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT ' . self::COLUMNS . ' ' . self::JOINS . ' WHERE e.id = :id', ['id' => $id]);
    }

    /** @return array<string,int> status => count */
    public function counts(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->select('SELECT status, COUNT(*) AS n FROM public_enquiries GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    /** Guarded on the status the caller last saw, so two people working the inbox cannot overwrite each other. */
    public function transition(int $id, string $from, string $to): bool
    {
        return $this->db->affectingStatement('UPDATE public_enquiries SET status = :to WHERE id = :id AND status = :from', ['id' => $id, 'from' => $from, 'to' => $to]) === 1;
    }

    public function markConverted(int $id, int $leadId, string $from): bool
    {
        return $this->db->affectingStatement(
            "UPDATE public_enquiries SET status = 'converted', lead_id = :lead WHERE id = :id AND status = :from",
            ['id' => $id, 'lead' => $leadId, 'from' => $from],
        ) === 1;
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
