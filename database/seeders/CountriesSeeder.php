<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Seeder;

/**
 * ISO-3166-1 alpha-2 country reference used by leads / candidates / jobs /
 * visa / employers. `is_gcc` flags the Gulf destinations that dominate overseas
 * recruitment. Idempotent.
 */
final class CountriesSeeder extends Seeder
{
    public function run(): void
    {
        $gcc = ['AE', 'SA', 'QA', 'KW', 'OM', 'BH'];

        $countries = [
            ['AE', 'United Arab Emirates', '+971'],
            ['SA', 'Saudi Arabia', '+966'],
            ['QA', 'Qatar', '+974'],
            ['KW', 'Kuwait', '+965'],
            ['OM', 'Oman', '+968'],
            ['BH', 'Bahrain', '+973'],
            ['IN', 'India', '+91'],
            ['NP', 'Nepal', '+977'],
            ['BD', 'Bangladesh', '+880'],
            ['LK', 'Sri Lanka', '+94'],
            ['PH', 'Philippines', '+63'],
            ['MY', 'Malaysia', '+60'],
            ['SG', 'Singapore', '+65'],
            ['MV', 'Maldives', '+960'],
            ['JO', 'Jordan', '+962'],
            ['LB', 'Lebanon', '+961'],
            ['IL', 'Israel', '+972'],
            ['TR', 'Türkiye', '+90'],
            ['GB', 'United Kingdom', '+44'],
            ['DE', 'Germany', '+49'],
            ['RO', 'Romania', '+40'],
            ['PL', 'Poland', '+48'],
            ['PT', 'Portugal', '+351'],
            ['MT', 'Malta', '+356'],
            ['CA', 'Canada', '+1'],
            ['US', 'United States', '+1'],
            ['AU', 'Australia', '+61'],
            ['NZ', 'New Zealand', '+64'],
            ['MU', 'Mauritius', '+230'],
            ['SC', 'Seychelles', '+248'],
        ];

        $rows = array_map(static fn ($c) => [
            'code'      => $c[0],
            'name'      => $c[1],
            'dial_code' => $c[2],
            'is_gcc'    => in_array($c[0], $gcc, true) ? 1 : 0,
            'is_active' => 1,
        ], $countries);

        $n = $this->upsert('countries', $rows, ['name', 'dial_code', 'is_gcc', 'is_active']);
        $this->info("countries: " . count($rows) . " rows ({$n} affected)");
    }
}
