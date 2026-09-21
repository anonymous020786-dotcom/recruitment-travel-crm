<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Seeder;

/**
 * The document catalogue candidates upload against. `allowed_mime` defaults
 * to PDF/JPEG/PNG for every type except the CV, which also accepts a Word
 * document. Idempotent.
 */
final class DocumentTypesSeeder extends Seeder
{
    private const PDF_JPEG_PNG = 'application/pdf,image/jpeg,image/png';
    private const CV_MIME = 'application/pdf,image/jpeg,image/png,application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /** key, label, category, has_expiry, is_required_default, allowed_mime, max_size_kb, sort_order */
    private const TYPES = [
        ['photo', 'Passport-size photo', 'identity', 0, 1, self::PDF_JPEG_PNG, 2048, 10],
        ['passport', 'Passport copy', 'identity', 1, 1, self::PDF_JPEG_PNG, 8192, 20],
        ['national_id', 'National ID / Aadhaar', 'identity', 0, 1, self::PDF_JPEG_PNG, 4096, 30],
        ['cv', 'CV / Resume', 'other', 0, 1, self::CV_MIME, 4096, 40],
        ['educational_certificate', 'Educational certificate', 'education', 0, 0, self::PDF_JPEG_PNG, 8192, 50],
        ['experience_certificate', 'Experience certificate', 'experience', 0, 0, self::PDF_JPEG_PNG, 8192, 60],
        ['medical_certificate', 'Medical fitness certificate', 'medical', 1, 0, self::PDF_JPEG_PNG, 8192, 70],
        ['police_clearance', 'Police clearance certificate', 'police', 1, 0, self::PDF_JPEG_PNG, 8192, 80],
        ['visa_copy', 'Visa copy', 'visa', 1, 0, self::PDF_JPEG_PNG, 8192, 90],
        ['flight_ticket', 'Flight ticket', 'travel', 0, 0, self::PDF_JPEG_PNG, 4096, 100],
        ['employment_agreement', 'Employment agreement', 'agreement', 0, 0, self::PDF_JPEG_PNG, 8192, 110],
    ];

    public function run(): void
    {
        $rows = array_map(static fn (array $t) => [
            'key_name' => $t[0], 'label' => $t[1], 'category' => $t[2],
            'has_expiry' => $t[3], 'is_required_default' => $t[4],
            'allowed_mime' => $t[5], 'max_size_kb' => $t[6], 'sort_order' => $t[7],
            'is_active' => 1,
        ], self::TYPES);

        $n = $this->upsert('document_types', $rows, [
            'label', 'category', 'has_expiry', 'is_required_default', 'allowed_mime', 'max_size_kb', 'sort_order', 'is_active',
        ]);
        $this->info('document_types: ' . count($rows) . " (affected {$n})");
    }
}
