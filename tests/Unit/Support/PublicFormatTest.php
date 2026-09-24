<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PublicFormat;
use PHPUnit\Framework\TestCase;

final class PublicFormatTest extends TestCase
{
    public function test_salary_wording(): void
    {
        self::assertSame('AED 1,800 – 2,400 / month', PublicFormat::salary('1800.00', '2400.00', 'AED'));
        self::assertSame('SAR 3,000+ / month', PublicFormat::salary('3000', null, 'SAR'));
        self::assertSame('QAR 2,500.50 / month', PublicFormat::salary(null, '2500.5', 'QAR'));
        self::assertSame('1,000 / month', PublicFormat::salary('1000', '1000', ''), 'equal bounds show once, no currency when unknown');
        self::assertNull(PublicFormat::salary(null, null, 'AED'));
        self::assertNull(PublicFormat::salary('0', '0.00', 'AED'), 'a zero salary is "not published"');
    }

    public function test_price_and_duration(): void
    {
        self::assertSame('INR 45,000', PublicFormat::price('45000.00', 'INR'));
        self::assertNull(PublicFormat::price(null, 'INR'));
        self::assertNull(PublicFormat::price('0', 'INR'));
        self::assertSame('5 days / 4 nights', PublicFormat::duration(5, 4));
        self::assertSame('3 days', PublicFormat::duration(3, null));
        self::assertSame('2 nights', PublicFormat::duration(0, 2));
        self::assertNull(PublicFormat::duration(null, null));
    }

    public function test_benefits_list_only_what_is_offered(): void
    {
        self::assertSame(['Accommodation provided', 'Transport allowance'], PublicFormat::benefits(['accommodation' => 'provided', 'food' => 'none', 'transport' => 'allowance']));
        self::assertSame([], PublicFormat::benefits(['accommodation' => 'none']));
    }

    public function test_excerpt_strips_html_decodes_entities_and_truncates_on_a_word_edge(): void
    {
        self::assertSame('Install wiring & panels.', PublicFormat::excerpt('<p>Install <strong>wiring</strong> &amp; panels.</p>', 100));
        $long = PublicFormat::excerpt('<p>' . str_repeat('alpha beta ', 40) . '</p>', 50);
        self::assertLessThanOrEqual(50, mb_strlen($long));
        self::assertStringEndsWith('…', $long);
        self::assertSame('', PublicFormat::excerpt(null));
    }

    public function test_job_posting_json_ld(): void
    {
        $job = ['title' => 'Driver', 'country' => 'AE', 'city' => 'Dubai', 'created_at' => '2026-09-01 10:00:00', 'deadline' => '2026-10-15', 'salary_min' => '1500', 'salary_max' => null, 'currency' => 'AED', 'description_html' => '<p>Drive.</p>', 'contract_duration_months' => 24];
        $ld = PublicFormat::jobPostingLd($job, 'Acme', 'https://x.in', 'https://x.in/overseas-jobs/driver');

        self::assertSame('JobPosting', $ld['@type']);
        self::assertSame('2026-09-01', $ld['datePosted']);
        self::assertSame('2026-10-15T23:59:59+00:00', $ld['validThrough']);
        self::assertSame('Acme', $ld['hiringOrganization']['name']);
        self::assertSame(['@type' => 'PostalAddress', 'addressCountry' => 'AE', 'addressLocality' => 'Dubai'], $ld['jobLocation']['address']);
        self::assertSame(1500.0, $ld['baseSalary']['value']['minValue']);
        self::assertArrayNotHasKey('maxValue', $ld['baseSalary']['value']);
        self::assertSame('CONTRACTOR', $ld['employmentType']);

        $bare = PublicFormat::jobPostingLd(['title' => 'X', 'country' => 'IN', 'created_at' => '2026-01-01 00:00:00'] + $job, 'Acme', 'https://x.in', 'https://x.in/j');
        $noSalary = PublicFormat::jobPostingLd(['salary_min' => null, 'salary_max' => null] + $job, 'Acme', 'https://x.in', 'https://x.in/j');
        self::assertArrayNotHasKey('baseSalary', $noSalary);
        self::assertSame('X', $bare['title']);
    }

    public function test_json_cannot_break_out_of_a_script_element(): void
    {
        $json = PublicFormat::json(['name' => '</script><script>alert(1)</script> & <b>']);

        self::assertStringNotContainsString('</script>', $json);
        self::assertStringNotContainsString('<', $json);
        self::assertSame('</script><script>alert(1)</script> & <b>', json_decode($json, true)['name']);
    }
}
