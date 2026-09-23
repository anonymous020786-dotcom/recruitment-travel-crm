<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\TourPackage;
use App\Support\Db;
use App\Support\ListQuery;
use App\Support\Page;

/** SQL for `tour_packages`. The catalogue is company-wide, so there is no branch scope. */
final class TourPackageRepository
{
    public const SORT = [
        'created_at'  => 'p.created_at',
        'name'        => 'p.name',
        'destination' => 'p.destination',
        'price'       => 'p.price',
        'status'      => 'p.status',
    ];

    public const FILTER_KEYS = ['status', 'visibility'];

    private const COLUMNS = 'p.id, p.public_id, p.slug, p.name, p.destination, p.duration_days, p.duration_nights, p.start_location,
        p.price, p.currency, p.hotel_summary, p.transport_summary, p.meals_summary, p.inclusions_html, p.exclusions_html,
        p.terms_html, p.status, p.is_public, p.created_at, p.updated_at,
        (SELECT COUNT(*) FROM tour_package_items i WHERE i.tour_package_id = p.id) AS item_count';

    public function __construct(private readonly Db $db)
    {
    }

    public function findById(int $id): ?TourPackage
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM tour_packages p WHERE p.id = :id AND p.deleted_at IS NULL', ['id' => $id]);

        return $row ? TourPackage::fromRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?TourPackage
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM tour_packages p WHERE p.public_id = :pid AND p.deleted_at IS NULL', ['pid' => $publicId]);

        return $row ? TourPackage::fromRow($row) : null;
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return (int) $this->db->insertRow('tour_packages', $data);
    }

    /**
     * @param array<string,mixed> $changes
     * @return int rows affected
     */
    public function update(int $id, array $changes): int
    {
        if ($changes === []) {
            return 0;
        }
        $set = ['updated_at = UTC_TIMESTAMP()'];
        $bind = ['id' => $id];
        foreach ($changes as $col => $val) {
            $set[] = "`{$col}` = :c_{$col}";
            $bind["c_{$col}"] = $val;
        }

        return $this->db->affectingStatement('UPDATE tour_packages SET ' . implode(', ', $set) . ' WHERE id = :id AND deleted_at IS NULL', $bind);
    }

    /**
     * Status move guarded on the status the caller last saw, so two people moving
     * the same package concurrently get one winner (0 rows for the loser).
     *
     * @param array<string,mixed> $extra
     */
    public function transition(int $id, string $from, string $to, array $extra = []): int
    {
        $set = ['status = :to', 'updated_at = UTC_TIMESTAMP()'];
        $bind = ['id' => $id, 'from' => $from, 'to' => $to];
        foreach ($extra as $col => $val) {
            $set[] = "`{$col}` = :c_{$col}";
            $bind["c_{$col}"] = $val;
        }

        return $this->db->affectingStatement(
            'UPDATE tour_packages SET ' . implode(', ', $set) . ' WHERE id = :id AND status = :from AND deleted_at IS NULL',
            $bind,
        );
    }

    public function softDelete(int $id): int
    {
        return $this->db->affectingStatement('UPDATE tour_packages SET deleted_at = UTC_TIMESTAMP(), is_public = 0 WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    /** @return array<string,string> public_id => "Name — Destination" for the active packages (pick-lists) */
    public function activeOptions(): array
    {
        $rows = $this->db->select("SELECT public_id, name, destination FROM tour_packages WHERE status = 'active' AND deleted_at IS NULL ORDER BY name LIMIT 500");
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['public_id']] = $r['name'] . ' — ' . $r['destination'];
        }

        return $out;
    }

    /** @return Page<TourPackage> */
    public function paginate(ListQuery $q): Page
    {
        [$where, $bind] = $this->buildWhere($q);

        $total = (int) $this->db->selectValue('SELECT COUNT(*) FROM tour_packages p WHERE ' . $where, $bind);

        $order = (self::SORT[$q->sort] ?? 'p.created_at') . ' ' . ($q->direction === 'asc' ? 'ASC' : 'DESC');
        $limit = $q->perPage;
        $offset = $q->offset();

        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . " FROM tour_packages p WHERE {$where} ORDER BY {$order}, p.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return new Page(array_map([TourPackage::class, 'fromRow'], $rows), $total, $q->page, $q->perPage);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function buildWhere(ListQuery $q): array
    {
        $where = ['p.deleted_at IS NULL'];
        $bind = [];

        if (($status = $q->filter('status')) !== null && $status !== '') {
            $where[] = 'p.status = :f_status';
            $bind['f_status'] = $status;
        }
        $visibility = $q->filter('visibility');
        if ($visibility === 'public') {
            $where[] = 'p.is_public = 1';
        } elseif ($visibility === 'private') {
            $where[] = 'p.is_public = 0';
        }
        if ($q->hasSearch()) {
            $where[] = '(p.name LIKE :s_name OR p.destination LIKE :s_destination OR p.start_location LIKE :s_start)';
            $bind['s_name'] = $bind['s_destination'] = $bind['s_start'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q->search) . '%';
        }

        return [implode(' AND ', $where), $bind];
    }
}
