<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Base class for database seeders. Seeders must be idempotent — running twice
 * must not create duplicates (use INSERT ... ON DUPLICATE KEY UPDATE or a
 * pre-check). Never seed fake business/financial data.
 */
abstract class Seeder
{
    public function __construct(
        protected readonly Db $db,
        protected readonly Application $app,
    ) {
    }

    abstract public function run(): void;

    protected function info(string $message): void
    {
        if ($this->app->runningInConsole()) {
            fwrite(STDOUT, "    {$message}\n");
        }
    }

    /**
     * Insert rows that do nothing if the unique key already exists.
     *
     * @param list<array<string,mixed>> $rows
     */
    protected function upsert(string $table, array $rows, array $updateColumns = []): int
    {
        if ($rows === []) {
            return 0;
        }

        $columns = array_keys($rows[0]);
        $placeholders = '(' . implode(', ', array_map(static fn ($c) => ':' . $c, $columns)) . ')';

        $update = $updateColumns === []
            ? '`' . $columns[0] . '` = `' . $columns[0] . '`' // no-op keeps it INSERT IGNORE-like
            : implode(', ', array_map(static fn ($c) => "`{$c}` = VALUES(`{$c}`)", $updateColumns));

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES %s ON DUPLICATE KEY UPDATE %s',
            $table,
            implode(', ', array_map(static fn ($c) => "`{$c}`", $columns)),
            $placeholders,
            $update,
        );

        $count = 0;
        foreach ($rows as $row) {
            $count += $this->db->affectingStatement($sql, $row);
        }

        return $count;
    }
}
