<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\BranchScopeResolver;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Repositories\AuditLogRepository;

/**
 * The audit-log viewer's rules: normalise and validate the filters (dates real and in order, at most a year apart;
 * modules and record types plain identifiers), default to the last 30 days, scope to the viewer's branches, and shape
 * each row for display (readable IP, decoded before/after values). The log is read-only here — nothing can edit or
 * delete an entry.
 */
final class AuditLogService
{
    public const PER_PAGE = 50;
    public const DEFAULT_DAYS = 30;
    public const MAX_RANGE_DAYS = 366;
    private const DETAIL_LIMIT = 6000;

    public function __construct(
        private readonly AuditLogRepository $logs,
        private readonly BranchScopeResolver $scopes,
    ) {
    }

    /**
     * @param array<string,mixed> $input module, q, from, to, record_type, record_id
     * @return array{module:string,q:string,from:string,to:string,record_type:string,record_id:string}
     * @throws ValidationException
     */
    public function filters(array $input): array
    {
        $errors = [];
        $text = static fn (string $k, int $max): string => mb_substr(trim((string) ($input[$k] ?? '')), 0, $max);

        $module = strtolower($text('module', 40));
        $recordType = strtolower($text('record_type', 40));
        $recordId = $text('record_id', 20);
        if ($module !== '' && preg_match('/^[a-z0-9_]{1,40}$/D', $module) !== 1) {
            $errors['module'] = ['Choose a module from the list.'];
        }
        if ($recordType !== '' && preg_match('/^[a-z0-9_]{1,40}$/D', $recordType) !== 1) {
            $errors['record_type'] = ['That record type is not valid.'];
        }
        if ($recordId !== '' && preg_match('/^\d{1,18}$/D', $recordId) !== 1) {
            $errors['record_id'] = ['A record id is a number.'];
        }

        $today = gmdate('Y-m-d');
        $from = $this->date($input['from'] ?? null) ?? gmdate('Y-m-d', strtotime('-' . (self::DEFAULT_DAYS - 1) . ' days'));
        $to = $this->date($input['to'] ?? null) ?? $today;
        foreach (['from', 'to'] as $k) {
            if (trim((string) ($input[$k] ?? '')) !== '' && $this->date($input[$k]) === null) {
                $errors[$k] = ['Use a real date, e.g. 2026-09-25.'];
            }
        }
        if (!isset($errors['from']) && !isset($errors['to'])) {
            if ($from > $to) {
                $errors['from'] = ['The start date cannot be after the end date.'];
            } elseif ((strtotime($to) - strtotime($from)) / 86400 > self::MAX_RANGE_DAYS) {
                $errors['to'] = ['Choose a period of at most a year.'];
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['module' => $module, 'q' => $text('q', 80), 'from' => $from, 'to' => $to, 'record_type' => $recordType, 'record_id' => $recordId];
    }

    /**
     * @param array{module:string,q:string,from:string,to:string,record_type:string,record_id:string} $filters
     * @return array{rows:list<array<string,mixed>>,total:int,capped:bool}
     */
    public function page(User $viewer, array $filters, int $page): array
    {
        $result = $this->logs->search($this->scopes->resolve($viewer), $filters, $page, self::PER_PAGE);
        $result['rows'] = array_map(fn (array $r): array => $this->present($r), $result['rows']);

        return $result;
    }

    /** @return list<string> */
    public function modules(): array
    {
        return $this->logs->modules();
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function present(array $r): array
    {
        $r['ip'] = $this->ip($r['ip_address'] ?? null);
        $r['old_pretty'] = $this->pretty($r['old_values'] ?? null);
        $r['new_pretty'] = $this->pretty($r['new_values'] ?? null);
        unset($r['ip_address'], $r['old_values'], $r['new_values']);

        return $r;
    }

    private function ip(mixed $binary): ?string
    {
        if (!is_string($binary) || $binary === '') {
            return null;
        }
        $text = @inet_ntop($binary);

        return $text === false ? null : $text;
    }

    private function pretty(mixed $json): ?string
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        $text = $decoded === null && $json !== 'null'
            ? $json
            : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return mb_strlen($text) > self::DETAIL_LIMIT ? mb_substr($text, 0, self::DETAIL_LIMIT) . "\n… (truncated)" : $text;
    }

    private function date(mixed $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v) !== 1) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));

        return checkdate($m, $d, $y) && $y >= 2000 ? $v : null;
    }
}
