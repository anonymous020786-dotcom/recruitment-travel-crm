<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Small helpers for the one thing PDO cannot bind: identifiers. Column names in dynamic `UPDATE ... SET` lists
 * come from service code, never from request data — but a name is still checked before it reaches SQL, so a
 * future mistake cannot turn into injection.
 */
final class Sql
{
    /** @throws \InvalidArgumentException unless `$name` is a plain identifier */
    public static function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException("Unsafe SQL identifier: {$name}");
        }

        return $name;
    }

    /** Whether a WHERE fragment reads columns of a table alias (`p.full_name` → alias `p`). */
    public static function references(string $where, string $alias): bool
    {
        return preg_match('/(?<![A-Za-z0-9_])' . preg_quote($alias, '/') . '\./', $where) === 1;
    }

    /** "`col` = :prefixcol" for a SET list; the caller binds `prefix . col`. */
    public static function assign(string $column, string $bindPrefix): string
    {
        self::identifier($column);

        return "`{$column}` = :{$bindPrefix}{$column}";
    }
}
