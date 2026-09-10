<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Seeder;

/**
 * Lead statuses (the pipeline) and common lead sources. Idempotent.
 * Status keys must match config('statuses.lead').
 */
final class LeadReferenceSeeder extends Seeder
{
    private const STATUSES = [
        // key, label, is_terminal, is_won, sort
        ['new',            'New',            0, 0, 10],
        ['contacted',      'Contacted',      0, 0, 20],
        ['follow_up',      'Follow Up',      0, 0, 30],
        ['interested',     'Interested',     0, 0, 40],
        ['counselling',    'Counselling',    0, 0, 50],
        ['converted',      'Converted',      1, 1, 60],
        ['not_interested', 'Not Interested', 1, 0, 70],
        ['lost',           'Lost',           1, 0, 80],
    ];

    private const SOURCES = [
        'Walk-in', 'Referral', 'Website', 'Facebook', 'Instagram', 'Google Ads',
        'WhatsApp', 'Phone Enquiry', 'Newspaper', 'Job Fair', 'Agent', 'Other',
    ];

    public function run(): void
    {
        $statusRows = array_map(static fn ($s) => [
            'key_name'    => $s[0],
            'label'       => $s[1],
            'is_terminal' => $s[2],
            'is_won'      => $s[3],
            'sort_order'  => $s[4],
            'is_active'   => 1,
        ], self::STATUSES);
        $n = $this->upsert('lead_statuses', $statusRows, ['label', 'is_terminal', 'is_won', 'sort_order', 'is_active']);
        $this->info('lead_statuses: ' . count($statusRows) . " (affected {$n})");

        $sourceRows = [];
        foreach (self::SOURCES as $i => $name) {
            $sourceRows[] = ['name' => $name, 'is_active' => 1, 'sort_order' => ($i + 1) * 10];
        }
        $m = $this->upsert('lead_sources', $sourceRows, ['is_active', 'sort_order']);
        $this->info('lead_sources: ' . count($sourceRows) . " (affected {$m})");
    }
}
