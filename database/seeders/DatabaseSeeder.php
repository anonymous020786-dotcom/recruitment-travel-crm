<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Application;
use App\Support\Db;
use App\Support\Seeder;

/**
 * Master seeder. Runs the reference-data seeders in dependency order.
 *
 * RBAC (roles/permissions), lead statuses, document types, etc. are added by
 * their own steps (1.7 and the relevant feature phases). Reference data here is
 * safe, non-business data only.
 */
final class DatabaseSeeder extends Seeder
{
    /** @var list<class-string<Seeder>> */
    private const SEEDERS = [
        CountriesSeeder::class,
        RolesSeeder::class,
    ];

    public function run(): void
    {
        foreach (self::SEEDERS as $class) {
            $this->info('→ ' . $class);
            /** @var Seeder $seeder */
            $seeder = new $class($this->db, $this->app);
            $seeder->run();
        }
    }

    public static function make(Db $db, Application $app): self
    {
        return new self($db, $app);
    }
}
