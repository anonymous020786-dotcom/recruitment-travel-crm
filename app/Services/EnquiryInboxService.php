<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Repositories\PublicEnquiryRepository;
use App\Support\Db;

/**
 * Working the website enquiry inbox: mark reviewed / spam, and turn an enquiry into a lead. Every change is guarded on
 * the status the person last saw (two people working the inbox cannot overwrite each other) and audited.
 */
final class EnquiryInboxService
{
    /** Statuses an enquiry may be moved to by hand (converted only through convert()). */
    private const MANUAL = ['new', 'reviewed', 'spam'];

    public function __construct(
        private readonly PublicEnquiryRepository $enquiries,
        private readonly LeadService $leads,
        private readonly AuditService $audit,
        private readonly Db $db,
    ) {
    }

    /** @throws DomainRuleException when the enquiry changed under the caller or the move is not allowed */
    public function setStatus(int $id, string $from, string $to, User $actor): void
    {
        $enquiry = $this->enquiries->find($id) ?? throw new DomainRuleException('ENQUIRY_MISSING', 'That enquiry no longer exists.', [], 404);
        if (!in_array($to, self::MANUAL, true) || $enquiry['status'] === 'converted') {
            throw new DomainRuleException('ENQUIRY_STATUS', 'That change is not allowed.', [], 422);
        }
        if (!$this->enquiries->transition($id, $from, $to)) {
            throw new DomainRuleException('ENQUIRY_STALE', 'Someone else just changed this enquiry. Reload and try again.', [], 409);
        }
        $this->audit->log('enquiry_status_changed', 'public_enquiries', 'public_enquiry', $id, ['status' => $from], ['status' => $to], null, $actor);
    }

    /**
     * Creates a lead from the enquiry (source "Website") in the chosen branch. If the person is already a lead the
     * enquiry is linked to that lead instead of creating a duplicate.
     *
     * @return array{lead_id:int,lead_number:string,created:bool}
     * @throws DomainRuleException
     */
    public function convert(int $id, string $fromStatus, int $branchId, User $actor): array
    {
        $e = $this->enquiries->find($id) ?? throw new DomainRuleException('ENQUIRY_MISSING', 'That enquiry no longer exists.', [], 404);
        if ($e['status'] === 'converted') {
            throw new DomainRuleException('ENQUIRY_CONVERTED', 'This enquiry has already been converted.', [], 409);
        }

        $about = match ($e['type']) {
            'job_apply'      => 'Applied for job: ' . ($e['job_title'] ?? 'a vacancy'),
            'travel_enquiry' => 'Enquired about package: ' . ($e['package_name'] ?? 'a package'),
            default          => 'Website contact form',
        };
        $data = array_filter([
            'name'     => (string) $e['name'],
            'phone'    => (string) $e['phone'],
            'email'    => $e['email'] ?: null,
            'priority' => 'medium',
            'source_id' => $this->websiteSourceId(),
            'interested_country' => $e['job_country'] ?: null,
            'interested_job' => $e['type'] === 'job_apply' ? (string) ($e['job_title'] ?? '') : null,
            'notes'    => trim($about . ($e['message'] ? "\n\n" . $e['message'] : '')),
        ], static fn ($v): bool => $v !== null && $v !== '');

        try {
            $lead = $this->leads->create($data, $actor, $branchId, false);
            $created = true;
            $leadId = $lead->id;
            $number = $lead->leadNumber;
        } catch (DomainRuleException $dup) {
            if ($dup->ruleCode() !== DomainRuleException::DUPLICATE_LEAD) {
                throw $dup;
            }
            $first = ($dup->context()['duplicates'] ?? [])[0] ?? null;
            if ($first === null) {
                throw $dup;
            }
            $created = false;
            $leadId = (int) $first['id'];
            $number = (string) $first['lead_number'];
        }

        if (!$this->enquiries->markConverted($id, $leadId, $fromStatus)) {
            throw new DomainRuleException('ENQUIRY_STALE', 'Someone else just changed this enquiry. Reload and try again.', ['lead_created' => $created, 'lead_number' => $number], 409);
        }
        $this->audit->log('enquiry_converted', 'public_enquiries', 'public_enquiry', $id, ['status' => $fromStatus], ['status' => 'converted', 'lead_id' => $leadId, 'created' => $created], null, $actor);

        return ['lead_id' => $leadId, 'lead_number' => $number, 'created' => $created];
    }

    private function websiteSourceId(): ?int
    {
        $id = $this->db->selectValue("SELECT id FROM lead_sources WHERE name = 'Website' AND is_active = 1 LIMIT 1");

        return $id !== null ? (int) $id : null;
    }
}
