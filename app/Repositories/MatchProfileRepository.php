<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\BranchScope;
use App\Support\Db;

/**
 * Bulk-loads the plain data the match engine scores against, in a fixed number
 * of queries regardless of pool size (candidates, then one query each for
 * skills, preferences and passports) — no N+1 on a shared host.
 */
final class MatchProfileRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Active candidates in scope with their engine-ready profile.
     *
     * @param list<int>|null $ids restrict to these candidate ids (still branch-scoped)
     * @return list<array{meta:array{id:int,public_id:string,number:string,name:string,stage:string},profile:array<string,mixed>}>
     */
    public function candidateProfiles(BranchScope $scope, ?array $ids, int $limit): array
    {
        [$branchSql, $bind] = $scope->whereClause('c.branch_id');
        $where = "c.is_active = 1 AND c.deleted_at IS NULL AND {$branchSql}";
        if ($ids !== null) {
            if ($ids === []) {
                return [];
            }
            $ph = [];
            foreach (array_values($ids) as $i => $id) {
                $ph[] = ":id{$i}";
                $bind["id{$i}"] = (int) $id;
            }
            $where .= ' AND c.id IN (' . implode(', ', $ph) . ')';
        }
        $limit = max(1, min($limit, 1000));

        $rows = $this->db->select(
            "SELECT c.id, c.public_id, c.candidate_number, c.stage, c.total_experience_years, c.highest_qualification,
                    p.full_name, p.gender, p.date_of_birth
             FROM candidates c JOIN persons p ON p.id = c.person_id
             WHERE {$where} ORDER BY c.updated_at DESC, c.id DESC LIMIT {$limit}",
            $bind,
        );
        if ($rows === []) {
            return [];
        }

        $candidateIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $skills = $this->skillsFor($candidateIds);
        $prefs = $this->preferencesFor($candidateIds);
        $passports = $this->latestPassportExpiry($candidateIds);

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $pref = $prefs[$id] ?? null;
            $out[] = [
                'meta' => [
                    'id' => $id, 'public_id' => (string) $r['public_id'], 'number' => (string) $r['candidate_number'],
                    'name' => (string) $r['full_name'], 'stage' => (string) $r['stage'],
                ],
                'profile' => [
                    'experience_years'    => isset($r['total_experience_years']) ? (float) $r['total_experience_years'] : null,
                    'qualification'       => $r['highest_qualification'] ?? null,
                    'dob'                 => $r['date_of_birth'] ?? null,
                    'gender'              => $r['gender'] ?? null,
                    'skills'              => $skills[$id] ?? [],
                    'preferred_countries' => $pref['countries'] ?? null,
                    'min_expected_salary' => $pref['salary'] ?? null,
                    'salary_currency'     => $pref['currency'] ?? null,
                    'passport_expiry'     => $passports[$id] ?? null,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,list<array{id:int,name:string}>>
     */
    private function skillsFor(array $ids): array
    {
        [$in, $bind] = $this->in($ids);
        $out = [];
        foreach ($this->db->select(
            "SELECT cs.candidate_id, s.id, s.name FROM candidate_skills cs JOIN skills s ON s.id = cs.skill_id WHERE cs.candidate_id IN ({$in})",
            $bind,
        ) as $r) {
            $out[(int) $r['candidate_id']][] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,array{countries:list<string>,salary:?float,currency:?string}>
     */
    private function preferencesFor(array $ids): array
    {
        [$in, $bind] = $this->in($ids);
        $out = [];
        foreach ($this->db->select(
            "SELECT candidate_id, preferred_countries, min_expected_salary, salary_currency FROM candidate_preferences WHERE candidate_id IN ({$in})",
            $bind,
        ) as $r) {
            $decoded = is_string($r['preferred_countries'] ?? null) ? json_decode($r['preferred_countries'], true) : null;
            $out[(int) $r['candidate_id']] = [
                'countries' => is_array($decoded) ? array_values(array_map('strval', $decoded)) : [],
                'salary'    => isset($r['min_expected_salary']) ? (float) $r['min_expected_salary'] : null,
                'currency'  => $r['salary_currency'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int,string> candidate_id => latest passport expiry
     */
    private function latestPassportExpiry(array $ids): array
    {
        [$in, $bind] = $this->in($ids);
        $out = [];
        foreach ($this->db->select(
            "SELECT candidate_id, MAX(expiry_date) AS expiry FROM passports WHERE candidate_id IN ({$in}) AND expiry_date IS NOT NULL GROUP BY candidate_id",
            $bind,
        ) as $r) {
            $out[(int) $r['candidate_id']] = (string) $r['expiry'];
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array{0:string,1:array<string,int>}
     */
    private function in(array $ids): array
    {
        $ph = [];
        $bind = [];
        foreach (array_values($ids) as $i => $id) {
            $ph[] = ":m{$i}";
            $bind["m{$i}"] = (int) $id;
        }

        return [implode(', ', $ph), $bind];
    }
}
