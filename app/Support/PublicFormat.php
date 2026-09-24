<?php

declare(strict_types=1);

namespace App\Support;

/** Presentation helpers for the public jobs / packages pages, kept out of the views so they can be tested. */
final class PublicFormat
{
    /** "AED 2,000 – 2,500 / month", "AED 2,000+ / month", or null when no salary is published. */
    public static function salary(mixed $min, mixed $max, mixed $currency): ?string
    {
        $lo = $min !== null && (float) $min > 0 ? (float) $min : null;
        $hi = $max !== null && (float) $max > 0 ? (float) $max : null;
        if ($lo === null && $hi === null) {
            return null;
        }
        $fmt = static fn (float $v): string => number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2);
        $cur = trim((string) $currency);
        $amount = match (true) {
            $lo !== null && $hi !== null && $lo !== $hi => $fmt($lo) . ' – ' . $fmt($hi),
            $lo !== null && $hi === null => $fmt($lo) . '+',
            default => $fmt($hi ?? $lo),
        };

        return ($cur !== '' ? $cur . ' ' : '') . $amount . ' / month';
    }

    public static function price(mixed $price, mixed $currency): ?string
    {
        if ($price === null || (float) $price <= 0) {
            return null;
        }
        $v = (float) $price;

        return trim((string) $currency . ' ' . number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2));
    }

    /** Human wording for the none / provided / allowance enums. */
    public static function benefit(string $what, ?string $value): ?string
    {
        return match ($value) {
            'provided'  => "{$what} provided",
            'allowance' => "{$what} allowance",
            default     => null,
        };
    }

    /** @return list<string> the benefits a job actually offers */
    public static function benefits(array $job): array
    {
        return array_values(array_filter([
            self::benefit('Accommodation', $job['accommodation'] ?? null),
            self::benefit('Food', $job['food'] ?? null),
            self::benefit('Transport', $job['transport'] ?? null),
        ]));
    }

    /** A plain-text teaser of an HTML fragment. */
    public static function excerpt(?string $html, int $max = 160): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1), " ,.;:-") . '…';
    }

    public static function duration(mixed $days, mixed $nights): ?string
    {
        $d = (int) $days;
        $n = (int) $nights;

        return match (true) {
            $d > 0 && $n > 0 => "{$d} days / {$n} nights",
            $d > 0           => "{$d} days",
            $n > 0           => "{$n} nights",
            default          => null,
        };
    }

    /**
     * schema.org JobPosting for Google Jobs. The employer is deliberately not named: the agency is the hiring
     * organisation of record for the public listing.
     *
     * @param array<string,mixed> $job a PublicCatalogRepository::job() row
     * @return array<string,mixed>
     */
    public static function jobPostingLd(array $job, string $orgName, string $siteUrl, string $pageUrl): array
    {
        $ld = [
            '@context'    => 'https://schema.org',
            '@type'       => 'JobPosting',
            'title'       => (string) $job['title'],
            'description' => (string) ($job['description_html'] ?? '') !== '' ? (string) $job['description_html'] : '<p>' . htmlspecialchars((string) $job['title'], ENT_QUOTES) . '</p>',
            'datePosted'  => substr((string) $job['created_at'], 0, 10),
            'url'         => $pageUrl,
            'directApply' => false,
            'hiringOrganization' => ['@type' => 'Organization', 'name' => $orgName, 'sameAs' => $siteUrl],
            'jobLocation' => ['@type' => 'Place', 'address' => array_filter([
                '@type' => 'PostalAddress', 'addressCountry' => (string) $job['country'], 'addressLocality' => (string) ($job['city'] ?? ''),
            ], static fn ($v): bool => $v !== '')],
        ];
        if (!empty($job['deadline'])) {
            $ld['validThrough'] = (string) $job['deadline'] . 'T23:59:59+00:00';
        }
        $lo = $job['salary_min'] ?? null;
        $hi = $job['salary_max'] ?? null;
        if (((float) $lo > 0 || (float) $hi > 0) && (string) ($job['currency'] ?? '') !== '') {
            $value = ['@type' => 'QuantitativeValue', 'unitText' => 'MONTH'];
            if ((float) $lo > 0) {
                $value['minValue'] = (float) $lo;
            }
            if ((float) $hi > 0) {
                $value['maxValue'] = (float) $hi;
            }
            $ld['baseSalary'] = ['@type' => 'MonetaryAmount', 'currency' => (string) $job['currency'], 'value' => $value];
        }
        if ((int) ($job['contract_duration_months'] ?? 0) > 0) {
            $ld['employmentType'] = 'CONTRACTOR';
        }

        return $ld;
    }

    /**
     * @param array<string,mixed> $pkg a PublicCatalogRepository::package() row
     * @return array<string,mixed>
     */
    public static function tripLd(array $pkg, string $orgName, string $pageUrl): array
    {
        $ld = [
            '@context'    => 'https://schema.org',
            '@type'       => 'TouristTrip',
            'name'        => (string) $pkg['name'],
            'description' => self::excerpt((string) ($pkg['inclusions_html'] ?? ''), 300) ?: (string) $pkg['name'] . ' — ' . (string) $pkg['destination'],
            'url'         => $pageUrl,
            'provider'    => ['@type' => 'Organization', 'name' => $orgName],
            'touristType' => 'Leisure',
        ];
        if ($pkg['price'] !== null && (float) $pkg['price'] > 0 && (string) ($pkg['currency'] ?? '') !== '') {
            $ld['offers'] = ['@type' => 'Offer', 'price' => (float) $pkg['price'], 'priceCurrency' => (string) $pkg['currency'], 'url' => $pageUrl, 'availability' => 'https://schema.org/InStock'];
        }

        return $ld;
    }

    /** JSON safe to place inside a <script type="application/ld+json"> element. */
    public static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
