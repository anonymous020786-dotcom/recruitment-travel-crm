<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\Db;

/**
 * Read-only queries for the public site. Nothing here can see a draft, a job that is not open and marked public,
 * a package that is not active and marked public, or a soft-deleted row — the visibility rules live in ONE place
 * (JOB_VISIBLE / PACKAGE_VISIBLE) so no page can forget one. Internal ids, job numbers, the employer and the branch are
 * never selected: a public visitor sees the vacancy, not who is behind it.
 */
final class PublicCatalogRepository
{
    private const JOB_VISIBLE = "j.is_public = 1 AND j.status = 'open' AND j.deleted_at IS NULL AND (j.deadline IS NULL OR j.deadline >= UTC_DATE())";
    private const PACKAGE_VISIBLE = "p.is_public = 1 AND p.status = 'active' AND p.deleted_at IS NULL";

    private const JOB_COLUMNS = 'j.slug, j.title, j.country, c.name AS country_name, j.city, j.vacancies, j.salary_min, j.salary_max, j.currency,
        j.experience_required, j.qualification, j.age_min, j.age_max, j.gender_requirement, j.accommodation, j.food, j.transport,
        j.working_hours, j.overtime, j.contract_duration_months, j.interview_type, j.deadline, j.description_html, j.created_at, j.updated_at';

    private const PACKAGE_COLUMNS = 'p.slug, p.name, p.destination, p.duration_days, p.duration_nights, p.start_location, p.price, p.currency,
        p.hotel_summary, p.transport_summary, p.meals_summary, p.inclusions_html, p.exclusions_html, p.terms_html, p.created_at, p.updated_at';

    public function __construct(private readonly Db $db)
    {
    }

    // ---- jobs --------------------------------------------------------------------------

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function jobs(string $country, string $search, int $page, int $perPage = 12): array
    {
        [$where, $bind] = $this->jobFilter($country, $search);
        $limit = max(1, min($perPage, 50));
        $offset = (max(1, $page) - 1) * $limit;

        $total = (int) $this->db->selectValue("SELECT COUNT(*) FROM jobs j WHERE {$where}", $bind);
        $rows = $this->db->select(
            'SELECT ' . self::JOB_COLUMNS . " FROM jobs j JOIN countries c ON c.code = j.country WHERE {$where}
             ORDER BY j.created_at DESC, j.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function job(string $slug): ?array
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::JOB_COLUMNS . ' FROM jobs j JOIN countries c ON c.code = j.country WHERE j.slug = :s AND ' . self::JOB_VISIBLE,
            ['s' => $slug],
        );
        if ($row === null) {
            return null;
        }
        $row['requirements'] = array_map('strval', array_column($this->db->select(
            'SELECT r.label FROM job_requirements r JOIN jobs j ON j.id = r.job_id WHERE j.slug = :s ORDER BY r.is_mandatory DESC, r.weight DESC, r.id',
            ['s' => $slug],
        ), 'label'));

        return $row;
    }

    /** @return int|null internal id of a publicly visible job (for attaching an enquiry), never exposed to the page */
    public function jobId(string $slug): ?int
    {
        $id = $this->db->selectValue('SELECT j.id FROM jobs j WHERE j.slug = :s AND ' . self::JOB_VISIBLE, ['s' => $slug]);

        return $id !== null ? (int) $id : null;
    }

    /** @return list<array{code:string,name:string,n:int}> countries that currently have public jobs */
    public function jobCountries(): array
    {
        $rows = $this->db->select(
            'SELECT c.code, c.name, COUNT(*) AS n FROM jobs j JOIN countries c ON c.code = j.country WHERE ' . self::JOB_VISIBLE . ' GROUP BY c.code, c.name ORDER BY c.name',
        );

        return array_map(static fn (array $r): array => ['code' => (string) $r['code'], 'name' => (string) $r['name'], 'n' => (int) $r['n']], $rows);
    }

    /** @return list<array{slug:string,updated_at:string}> */
    public function jobSitemap(int $limit = 5000): array
    {
        return $this->sitemapRows($this->db->select('SELECT j.slug, j.updated_at FROM jobs j WHERE ' . self::JOB_VISIBLE . ' ORDER BY j.updated_at DESC LIMIT ' . max(1, min($limit, 50000))));
    }

    // ---- packages ----------------------------------------------------------------------

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function packages(string $search, int $page, int $perPage = 12): array
    {
        $where = self::PACKAGE_VISIBLE;
        $bind = [];
        if ($search !== '') {
            $where .= ' AND (p.name LIKE :q1 OR p.destination LIKE :q2)';
            $bind['q1'] = $bind['q2'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        }
        $limit = max(1, min($perPage, 50));
        $offset = (max(1, $page) - 1) * $limit;

        $total = (int) $this->db->selectValue("SELECT COUNT(*) FROM tour_packages p WHERE {$where}", $bind);
        $rows = $this->db->select(
            'SELECT ' . self::PACKAGE_COLUMNS . " FROM tour_packages p WHERE {$where} ORDER BY p.created_at DESC, p.id DESC LIMIT {$limit} OFFSET {$offset}",
            $bind,
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string,mixed>|null */
    public function package(string $slug): ?array
    {
        $row = $this->db->selectOne('SELECT ' . self::PACKAGE_COLUMNS . ' FROM tour_packages p WHERE p.slug = :s AND ' . self::PACKAGE_VISIBLE, ['s' => $slug]);
        if ($row === null) {
            return null;
        }
        $row['itinerary'] = $this->db->select(
            'SELECT i.day_no, i.title, i.description FROM tour_package_items i JOIN tour_packages p ON p.id = i.tour_package_id
             WHERE p.slug = :s ORDER BY i.sort_order, i.day_no, i.id',
            ['s' => $slug],
        );

        return $row;
    }

    public function packageId(string $slug): ?int
    {
        $id = $this->db->selectValue('SELECT p.id FROM tour_packages p WHERE p.slug = :s AND ' . self::PACKAGE_VISIBLE, ['s' => $slug]);

        return $id !== null ? (int) $id : null;
    }

    /** @return list<array{slug:string,updated_at:string}> */
    public function packageSitemap(int $limit = 5000): array
    {
        return $this->sitemapRows($this->db->select('SELECT p.slug, p.updated_at FROM tour_packages p WHERE ' . self::PACKAGE_VISIBLE . ' ORDER BY p.updated_at DESC LIMIT ' . max(1, min($limit, 50000))));
    }

    // ---- internals ---------------------------------------------------------------------

    /** @return array{0:string,1:array<string,mixed>} */
    private function jobFilter(string $country, string $search): array
    {
        $where = self::JOB_VISIBLE;
        $bind = [];
        if (preg_match('/^[A-Za-z]{2}$/', $country) === 1) {
            $where .= ' AND j.country = :country';
            $bind['country'] = strtoupper($country);
        }
        if ($search !== '') {
            $where .= ' AND (j.title LIKE :q1 OR j.city LIKE :q2)';
            $bind['q1'] = $bind['q2'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        }

        return [$where, $bind];
    }

    /** @param list<array<string,mixed>> $rows @return list<array{slug:string,updated_at:string}> */
    private function sitemapRows(array $rows): array
    {
        return array_map(static fn (array $r): array => ['slug' => (string) $r['slug'], 'updated_at' => (string) $r['updated_at']], $rows);
    }
}
