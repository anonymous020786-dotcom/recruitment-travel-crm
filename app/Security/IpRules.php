<?php

declare(strict_types=1);

namespace App\Security;

use App\Audit\AuditService;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Support\Application;
use App\Support\Db;

/**
 * IP allow / block rules (Admin → Security → IP rules) plus the automatic block after repeated failed sign-ins.
 *
 * Rules are a single address or a CIDR range, IPv4 or IPv6, stored as 16 bytes (IPv4 as ::ffff:a.b.c.d) so one indexed BETWEEN
 * finds every rule covering an address. An ALLOW rule always wins, so a trusted office range can never be shut out by a broader
 * block. Guard rails: you cannot block the address you are working from (unless an allow rule covers it), ranges wider than
 * /8 (IPv4) or /32 (IPv6) are refused, and at most MAX_RULES rules exist. Temporary rules expire by themselves.
 *
 * The request path costs nothing while there are no rules (a flag in the settings, see SecurityPolicy::setIpRuleCount) and one
 * indexed query when there are.
 */
final class IpRules
{
    public const MAX_RULES = 500;
    private const MIN_V4_PREFIX = 8;
    private const MIN_V6_PREFIX = 32;

    public function __construct(
        private readonly Db $db,
        private readonly AuditService $audit,
        private readonly SecurityPolicy $policy,
        private readonly Application $app,
    ) {
    }

    // ---- the request-time check ---------------------------------------------------------------------------------

    /** 'allow' | 'block' | 'none' for an address (text form). Expired rules are ignored. */
    public function verdict(string $ip): string
    {
        $bytes = self::pack($ip);
        if ($bytes === null) {
            return 'none';
        }
        $effects = $this->db->select(
            'SELECT DISTINCT effect FROM ip_rules WHERE UNHEX(:h1) BETWEEN ip_from AND ip_to AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())',
            ['h1' => bin2hex($bytes)],
        );
        $set = array_column($effects, 'effect');

        return in_array('allow', $set, true) ? 'allow' : (in_array('block', $set, true) ? 'block' : 'none');
    }

    /** Remember that a rule turned someone away — at most one write per rule every 30 seconds. */
    public function noteBlocked(string $ip): void
    {
        $bytes = self::pack($ip);
        if ($bytes === null) {
            return;
        }
        $this->db->affectingStatement(
            "UPDATE ip_rules SET last_blocked_at = UTC_TIMESTAMP()
             WHERE effect = 'block' AND UNHEX(:h1) BETWEEN ip_from AND ip_to AND (last_blocked_at IS NULL OR last_blocked_at < (UTC_TIMESTAMP() - INTERVAL 30 SECOND))",
            ['h1' => bin2hex($bytes)],
        );
    }

    // ---- listing ------------------------------------------------------------------------------------------------

    /** @return list<array<string,mixed>> newest first, with `active` (not expired) */
    public function all(): array
    {
        return $this->db->select(
            "SELECT r.id, r.effect, r.cidr, r.note, r.source, r.expires_at, r.last_blocked_at, r.created_at, u.name AS created_by_name,
                    (r.expires_at IS NULL OR r.expires_at > UTC_TIMESTAMP()) AS active
             FROM ip_rules r LEFT JOIN users u ON u.id = r.created_by
             ORDER BY r.created_at DESC, r.id DESC",
        );
    }

    public function count(): int
    {
        return (int) $this->db->selectValue('SELECT COUNT(*) FROM ip_rules', [], 0);
    }

    // ---- changes ------------------------------------------------------------------------------------------------

    /**
     * @param string $effect block|allow
     * @param int|null $minutes lifetime in minutes, null = until removed
     * @param string|null $yourIp the address of the person adding it (for the self-lockout guard)
     * @throws ValidationException
     */
    public function add(string $effect, string $input, ?string $note, ?int $minutes, ?User $actor, ?string $yourIp = null, string $source = 'manual'): int
    {
        $errors = [];
        if (!in_array($effect, ['block', 'allow'], true)) {
            $errors['effect'] = ['Choose block or allow.'];
        }
        $range = self::parse($input);
        if ($range === null) {
            $errors['cidr'] = ['Enter an IP address (1.2.3.4 or 2001:db8::1) or a range such as 203.0.113.0/24.'];
        } elseif ($effect === 'block' && $range['prefix'] < ($range['v6'] ? self::MIN_V6_PREFIX : self::MIN_V4_PREFIX)) {
            $errors['cidr'] = ['That range is too wide to block safely (narrower than /' . ($range['v6'] ? self::MIN_V6_PREFIX : self::MIN_V4_PREFIX) . ' is required).'];
        }
        $note = $note === null ? null : trim($note);
        if ($note !== null && mb_strlen($note) > 200) {
            $errors['note'] = ['The note is too long (200 characters at most).'];
        }
        if ($minutes !== null && ($minutes < 1 || $minutes > 525600)) {
            $errors['minutes'] = ['A temporary rule lasts 1 minute to 1 year.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        \assert($range !== null);

        if ($effect === 'block' && $yourIp !== null && $this->covers($range, $yourIp) && $this->verdict($yourIp) !== 'allow') {
            throw new ValidationException(['cidr' => ['That would block the address you are using right now (' . $yourIp . '). Add an allow rule for it first.']]);
        }
        if ($source === 'manual' && $this->count() >= self::MAX_RULES && (int) $this->db->selectValue('SELECT COUNT(*) FROM ip_rules WHERE effect = :e AND cidr = :c', ['e' => $effect, 'c' => $range['cidr']], 0) === 0) {
            throw new ValidationException(['cidr' => ['There are already ' . self::MAX_RULES . ' rules. Remove some first.']]);
        }

        $expires = $minutes === null ? null : gmdate('Y-m-d H:i:s', time() + $minutes * 60);
        $this->db->affectingStatement(
            'INSERT INTO ip_rules (effect, cidr, ip_from, ip_to, note, source, expires_at, created_by)
             VALUES (:e, :c, UNHEX(:f), UNHEX(:t), :n, :s, :x, :u)
             ON DUPLICATE KEY UPDATE note = VALUES(note), expires_at = VALUES(expires_at), source = VALUES(source), created_by = VALUES(created_by)',
            ['e' => $effect, 'c' => $range['cidr'], 'f' => bin2hex($range['from']), 't' => bin2hex($range['to']), 'n' => $note === '' ? null : $note, 's' => $source, 'x' => $expires, 'u' => $actor?->id],
        );
        $id = (int) $this->db->selectValue('SELECT id FROM ip_rules WHERE effect = :e AND cidr = :c', ['e' => $effect, 'c' => $range['cidr']], 0);
        $this->policy->setIpRuleCount($this->count());
        $this->audit->log($source === 'auto' ? 'ip_auto_blocked' : 'ip_rule_added', 'security', 'ip_rule', $id, null, ['effect' => $effect, 'cidr' => $range['cidr'], 'minutes' => $minutes, 'note' => $note], null, $actor);

        return $id;
    }

    public function remove(int $id, ?User $actor): bool
    {
        $row = $this->db->selectOne('SELECT effect, cidr FROM ip_rules WHERE id = :id', ['id' => $id]);
        if ($row === null) {
            return false;
        }
        $this->db->affectingStatement('DELETE FROM ip_rules WHERE id = :id', ['id' => $id]);
        $this->policy->setIpRuleCount($this->count());
        $this->audit->log('ip_rule_removed', 'security', 'ip_rule', $id, ['effect' => $row['effect'], 'cidr' => $row['cidr']], null, null, $actor);

        return true;
    }

    /** Delete expired rules (nightly). @return int rules removed */
    public function pruneExpired(): int
    {
        $n = $this->db->affectingStatement('DELETE FROM ip_rules WHERE expires_at IS NOT NULL AND expires_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
        if ($n > 0) {
            $this->policy->setIpRuleCount($this->count());
        }

        return $n;
    }

    /** Remove every rule — the emergency exit used by scripts/security-unblock.php. */
    public function clearAll(): int
    {
        $n = $this->db->affectingStatement('DELETE FROM ip_rules');
        $this->policy->setIpRuleCount(0);

        return $n;
    }

    // ---- automatic block ----------------------------------------------------------------------------------------

    /**
     * Called after a failed sign-in. When the automatic block is on and this address has failed `threshold` times in the
     * last 15 minutes, block it for the configured time. Never blocks an address an allow rule covers.
     *
     * @return bool whether a block was added
     */
    public function maybeAutoBlock(string $ipBinary, int $failuresInWindow): bool
    {
        $threshold = (int) $this->app->config()->get('security.autoblock.threshold', 0);
        if ($threshold <= 0 || $failuresInWindow < $threshold) {
            return false;
        }
        $ip = @inet_ntop($ipBinary);
        if ($ip === false || $this->verdict($ip) !== 'none') {
            return false;
        }
        $minutes = max(1, (int) $this->app->config()->get('security.autoblock.minutes', 60));
        $range = self::parse($ip);
        if ($range === null) {
            return false;
        }
        $this->add('block', $range['cidr'], "Automatic: {$failuresInWindow} failed sign-ins in 15 minutes", $minutes, null, null, 'auto');

        return true;
    }

    // ---- address maths ------------------------------------------------------------------------------------------

    /** 16-byte form of a text address, IPv4 as ::ffff:a.b.c.d. */
    public static function pack(string $ip): ?string
    {
        $raw = @inet_pton(trim($ip));
        if ($raw === false) {
            return null;
        }

        return strlen($raw) === 4 ? str_repeat("\0", 10) . "\xff\xff" . $raw : $raw;
    }

    /** @return array{cidr:string,from:string,to:string,prefix:int,v6:bool}|null */
    public static function parse(string $input): ?array
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 50 || preg_match('/^[0-9a-fA-F:.\/]+$/D', $input) !== 1) {
            return null;
        }
        [$addr, $prefixText] = array_pad(explode('/', $input, 2), 2, null);
        $raw = @inet_pton($addr);
        if ($raw === false) {
            return null;
        }
        $v6 = strlen($raw) === 16;
        $max = $v6 ? 128 : 32;
        if ($prefixText === null) {
            $prefix = $max;
        } elseif (preg_match('/^\d{1,3}$/D', $prefixText) === 1 && (int) $prefixText <= $max) {
            $prefix = (int) $prefixText;
        } else {
            return null;
        }
        // work on the 16-byte form: an IPv4 /n is a /(96+n)
        $bytes = $v6 ? $raw : str_repeat("\0", 10) . "\xff\xff" . $raw;
        $bits = $v6 ? $prefix : 96 + $prefix;
        $from = '';
        $to = '';
        for ($i = 0; $i < 16; $i++) {
            $keep = max(0, min(8, $bits - $i * 8));
            $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $b = ord($bytes[$i]);
            $from .= chr($b & $mask);
            $to .= chr(($b & $mask) | (~$mask & 0xFF));
        }
        $text = inet_ntop($v6 ? $from : substr($from, 12));
        if ($text === false) {
            return null;
        }

        return ['cidr' => $prefix === $max ? $text : $text . '/' . $prefix, 'from' => $from, 'to' => $to, 'prefix' => $prefix, 'v6' => $v6];
    }

    /** @param array{from:string,to:string} $range */
    private function covers(array $range, string $ip): bool
    {
        $bytes = self::pack($ip);

        return $bytes !== null && strcmp($bytes, $range['from']) >= 0 && strcmp($bytes, $range['to']) <= 0;
    }
}
