<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\TourPackageItem;
use App\Support\Db;

/** SQL for `tour_package_items` (the day-by-day itinerary). */
final class TourPackageItemRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<TourPackageItem> by day (undated lines last), then the order they were added */
    public function forPackage(int $packageId): array
    {
        $rows = $this->db->select(
            'SELECT id, tour_package_id, day_no, title, description, sort_order FROM tour_package_items
             WHERE tour_package_id = :p ORDER BY (day_no IS NULL), day_no, sort_order, id',
            ['p' => $packageId],
        );

        return array_map([TourPackageItem::class, 'fromRow'], $rows);
    }

    public function count(int $packageId): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM tour_package_items WHERE tour_package_id = :p', ['p' => $packageId]);
    }

    /** Appends after the package's last line. */
    public function add(int $packageId, ?int $dayNo, string $title, ?string $description): int
    {
        $next = (int) $this->db->selectValue('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM tour_package_items WHERE tour_package_id = :p', ['p' => $packageId]);

        return (int) $this->db->insertRow('tour_package_items', [
            'tour_package_id' => $packageId, 'day_no' => $dayNo, 'title' => $title, 'description' => $description, 'sort_order' => $next,
        ]);
    }

    /** @return int rows changed (0 when the line is not on that package, or nothing differed) */
    public function update(int $id, int $packageId, ?int $dayNo, string $title, ?string $description): int
    {
        return $this->db->affectingStatement(
            'UPDATE tour_package_items SET day_no = :d, title = :t, description = :desc WHERE id = :id AND tour_package_id = :p',
            ['d' => $dayNo, 't' => $title, 'desc' => $description, 'id' => $id, 'p' => $packageId],
        );
    }

    public function exists(int $id, int $packageId): bool
    {
        return $this->db->exists('SELECT 1 FROM tour_package_items WHERE id = :id AND tour_package_id = :p', ['id' => $id, 'p' => $packageId]);
    }

    public function delete(int $id, int $packageId): int
    {
        return $this->db->affectingStatement('DELETE FROM tour_package_items WHERE id = :id AND tour_package_id = :p', ['id' => $id, 'p' => $packageId]);
    }
}
