<?php

declare(strict_types=1);

namespace App\Security\Waf;

/**
 * The managed rule sets of the application firewall. Each set is a group of patterns aimed at one kind of attack, the parts
 * of the request it looks at, and its default mode (block | log | off). The super admin can change the mode per set.
 *
 * The patterns are deliberately specific — they target attack syntax (`union select`, `<script`, `../`, `${jndi:`) rather
 * than words — so ordinary text such as "Select your country" or "drop us a line" never matches. They run on a normalised
 * copy of the input: URL-decoded (twice, to catch double encoding), HTML-entity-decoded, lowercased, whitespace collapsed,
 * SQL comments turned into spaces. Only the first 16 KB of any part is looked at.
 */
final class Signatures
{
    /** Parts a set can inspect: path, query, body, user_agent, headers. */
    public const SETS = [
        'sqli' => [
            'label' => 'SQL injection',
            'help' => 'Attempts to read or change the database through a form or link (UNION SELECT, OR 1=1, SLEEP(), stacked queries…).',
            'parts' => ['query', 'body', 'path', 'cookie'],
            'default' => 'block',
            'patterns' => [
                '/\bunion\b\s+(?:all\s+|distinct\s+)?select\b/',
                '/[\'"`]\s*(?:or|and|xor|\|\||&&)\s*[\'"`]?\d+[\'"`]?\s*(?:=|<>|!=|>|<|like)\s*[\'"`]?\d+/',
                '/[\'"`]\s*(?:or|and)\s*[\'"`][^\'"`]{0,20}[\'"`]\s*(?:=|like)\s*[\'"`]/',
                '/[\'"`]\s*(?:or|and)\s+(?:not\s+)?(?:true|false|\d+)\s*(?:--|#|\/\*|$)/',
                '/;\s*(?:drop|truncate|alter|create|rename)\s+(?:table|database|schema|user|view|index)\b|;\s*(?:insert\s+into|update\s+\w+\s+set|delete\s+from|exec(?:ute)?\s*[(@]|declare\s+@|shutdown\b)/',
                '/\b(?:sleep|benchmark|pg_sleep|load_file|extractvalue|updatexml|make_set|elt)\s*\(/',
                '/\bwaitfor\s+delay\s+[\'"]/',
                '/\binformation_schema\b|\bmysql\.user\b|\bsys\.(?:user_summary|schema_)|\bpg_catalog\b|\bsqlite_master\b/',
                '/\binto\s+(?:out|dump)file\b/',
                '/\/\*!\d{0,6}|\bconcat_ws\s*\(\s*0x|\bchar\s*\(\s*\d+\s*,\s*\d+\s*,\s*\d+/',
                '/[\'"`]\s*;\s*--|[\'"`]\s*--\s*$/',
            ],
        ],
        'xss' => [
            'label' => 'Cross-site scripting (XSS)',
            'help' => 'Script or HTML smuggled into links and forms to run in another visitor\'s browser.',
            'parts' => ['query', 'body', 'path', 'referer'],
            'default' => 'block',
            'patterns' => [
                '/<\s*\/?\s*(?:script|iframe|frame|object|embed|applet|meta|base|svg|math|template|link|style)\b/',
                '/\bjavascript\s*:|\bvbscript\s*:|\blivescript\s*:/',
                '/<[^>]*\s(?:on(?:error|load|mouseover|mouseenter|focus|blur|click|toggle|begin|animationstart|transitionend|pointerover|input|change|submit|keydown|keyup|wheel)\s*=)/',
                '/\bsrcdoc\s*=|\bformaction\s*=|\bxlink:href\s*=/',
                '/\bdocument\s*\.\s*(?:cookie|domain|write)\b|\bwindow\s*\.\s*location\b|\beval\s*\(\s*atob\b/',
                '/\bexpression\s*\(|\burl\s*\(\s*[\'"]?\s*javascript:/',
                '/data\s*:\s*text\/html/',
            ],
        ],
        'traversal' => [
            'label' => 'Path traversal & file inclusion',
            'help' => 'Attempts to read server files (../../etc/passwd) or include remote code (php://, file://).',
            'parts' => ['path', 'query', 'body'],
            'default' => 'block',
            'patterns' => [
                '/(?:^|[\/\\\\=\'"\s:])\.\.[\/\\\\]/',
                '/\/etc\/(?:passwd|shadow|hosts|group)\b|\/proc\/self\/|c:\\\\windows\\\\|boot\.ini\b|win\.ini\b/',
                '/\b(?:php|file|expect|zip|phar|glob|data|dict|gopher|ldap|jar)\s*:\/\//',
                '/\bwp-config\.php|\.htpasswd\b/',
            ],
        ],
        'rce' => [
            'label' => 'Code & command injection',
            'help' => 'Shell commands, server-side code and template injection, Log4Shell-style lookups.',
            'parts' => ['query', 'body', 'user_agent', 'referer', 'headers'],
            'default' => 'block',
            'patterns' => [
                '/\$\{\s*(?:jndi|env|sys|lower|upper|date|ctx)\s*:/',
                '/(?:;|\|\||&&|\|)\s*(?:cat|ls|wget|curl|nc|ncat|bash|sh|zsh|python\d?|perl|php|powershell|cmd(?:\.exe)?|ping|nslookup|chmod|rm)\s+(?:-[a-z]|\/|\.\/|\$|https?:|[\w.-]+\.(?:sh|php|py|pl)\b)/',
                '/(?:;|\|)\s*(?:id|whoami|uname\s+-a|pwd)\s*(?:;|\||$)|\$\(\s*(?:id|whoami|uname|cat|curl|wget)\b|`\s*(?:id|whoami|uname|cat)\b[^`]*`/',
                '/\b(?:system|exec|shell_exec|passthru|popen|proc_open|assert|eval|base64_decode|gzinflate|str_rot13|create_function|call_user_func)\(\s*[\'"$]/',
                '/<\?(?:php|=)/',
                '/\{\{\s*\d+\s*[*+]\s*\d+\s*\}\}|\$\{\s*\d+\s*\*\s*\d+\s*\}|<%=?\s*\d+\s*\*\s*\d+\s*%>/',
                '/\bruntime\s*\.\s*getruntime\s*\(|\bprocessbuilder\b|__import__\s*\(\s*[\'"]os/',
                '/\(\s*\)\s*\{\s*:\s*;\s*\}\s*;/',
            ],
        ],
        'scanners' => [
            'label' => 'Attack tools & bad bots',
            'help' => 'Well-known vulnerability scanners and hacking tools, identified by their user agent.',
            'parts' => ['user_agent'],
            'default' => 'block',
            'patterns' => [
                '/\b(?:sqlmap|nikto|nmap|masscan|zgrab|nuclei|acunetix|netsparker|invicti|wpscan|dirbuster|dirb|gobuster|ffuf|feroxbuster|havij|w3af|openvas|nessus|arachni|skipfish|jaeles|wfuzz|commix|xsstrike|burpcollaborator|interactsh|zmeu|morfeus|hydra|joomscan|whatweb|censysinspect|l9explore|l9tcpid)\b/',
                '/\bfimap\b|\bpangolin\b|\bparos\b|\bwebinspect\b|\bappscan\b|\bnetcraft ssl\b/',
            ],
        ],
        'probes' => [
            'label' => 'Probes for secrets & other software',
            'help' => 'Requests for files that only an attacker would ask for here: .env, .git, backups, WordPress and phpMyAdmin paths. Optionally bans the address.',
            'parts' => ['path'],
            'default' => 'block',
            'patterns' => [
                '/^\/(?:[^\/]*\/)*\.(?:env(?:\.[a-z]+)?|git|svn|hg|bzr|ds_store|aws|ssh|docker|npmrc|pypirc|idea|vscode|htaccess|htpasswd|bash_history|kube)(?:\/|$)/',
                '/\/(?:wp-(?:admin|login\.php|content|includes|config\.php|json)|xmlrpc\.php|wlwmanifest\.xml)(?:\/|$)/',
                '/\/(?:phpmyadmin|pma|myadmin|mysqladmin|adminer(?:\.php)?|phpinfo\.php|info\.php|server-status|server-info|cgi-bin|actuator|jmx-console|manager\/html|solr\/admin|telescope|_ignition|_profiler|debug\/default\/view)(?:\/|$)/',
                '/\/vendor\/phpunit\/|\/eval-stdin\.php|\/(?:shell|cmd|c99|r57|wso|alfa|b374k|webshell)\.php(?:$|\?)/',
                '/\.(?:sql|sqlite|db|bak|backup|old|orig|save|swp|tar|tar\.gz|tgz|zip|rar|7z|pem|key|p12|pfx|log|ini|conf|config|yml|yaml)$/',
            ],
        ],
        'protocol' => [
            'label' => 'Protocol abuse',
            'help' => 'Unusual methods (TRACE, CONNECT…), null bytes, over-long addresses and oversized headers.',
            'parts' => [],
            'default' => 'block',
            'patterns' => [],
        ],
        'empty_ua' => [
            'label' => 'Requests without a user agent',
            'help' => 'Real browsers always send one. Scripts often do not — but so do some uptime monitors, so this starts in log-only mode.',
            'parts' => [],
            'default' => 'log',
            'patterns' => [],
        ],
    ];

    public const ALLOWED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    public const MAX_URI = 2048;
    public const MAX_HEADER = 8192;
    public const MAX_INSPECT = 16384;

    /** The normalised copy of an input the patterns run on. */
    public static function normalize(string $s): string
    {
        $s = substr($s, 0, self::MAX_INSPECT);
        for ($i = 0; $i < 2 && preg_match('/%[0-9a-f]{2}|\+/i', $s) === 1; $i++) {
            $s = urldecode($s);
        }
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace("\0", '', $s);
        $s = (string) preg_replace('#/\*(?!!).*?\*/#s', ' ', $s);   // union/**/select → union select (keeps /*! for its own pattern)
        $s = strtolower($s);

        return (string) preg_replace('/\s+/', ' ', $s);
    }

    /** @return string|null the matched text of the first pattern of $set that matches $normalised */
    public static function match(string $set, string $normalised): ?string
    {
        foreach (self::SETS[$set]['patterns'] ?? [] as $p) {
            if (preg_match($p, $normalised, $m) === 1) {
                return $m[0] === '' ? $p : $m[0];
            }
        }

        return null;
    }
}
