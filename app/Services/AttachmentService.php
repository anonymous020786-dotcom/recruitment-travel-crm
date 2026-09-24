<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditService;
use App\Auth\BranchScopeResolver;
use App\Auth\Gate;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\CandidateDocument;
use App\Models\FlightBooking;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Repositories\CandidateRepository;
use App\Repositories\DocumentTypeRepository;
use App\Repositories\FlightRepository;
use App\Repositories\MedicalRepository;

/**
 * Attaches the paperwork that belongs to a record: a medical certificate to a medical, a ticket to a flight.
 *
 * The file goes through the ordinary candidate-document pipeline (DocumentService::upload — MIME by content, size limit,
 * re-encoded images, private storage, verification workflow, access log), so it appears in the candidate's Documents tab like any
 * other upload; the record just points at it. Replacing keeps the old file (it is still in Documents) and moves the pointer.
 */
final class AttachmentService
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentTypeRepository $types,
        private readonly CandidateRepository $candidates,
        private readonly BranchScopeResolver $scopes,
        private readonly Gate $gate,
        private readonly MedicalRepository $medical,
        private readonly FlightRepository $flights,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param array<string,mixed> $file the uploaded file entry
     * @throws AuthorizationException|DomainRuleException|ValidationException
     */
    public function medicalCertificate(MedicalRecord $record, array $file, User $actor): CandidateDocument
    {
        if (!$this->gate->forUser($actor)->allows('edit', $record)) {
            throw AuthorizationException::forPermission('medical.edit');
        }
        if (in_array($record->status, ['pending', 'scheduled'], true)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'Attach the certificate after the candidate has attended the medical.', []);
        }

        $doc = $this->store($record->candidateId, 'medical_certificate', $file, $record->expiresAt, $actor);
        $this->medical->setCertificate($record->id, $doc->id);
        $this->audit->log('certificate_attached', 'medical', 'medical_record', $record->id, ['document_id' => null], ['document_id' => $doc->id], null, $actor);

        return $doc;
    }

    /** @param array<string,mixed> $file @throws AuthorizationException|DomainRuleException|ValidationException */
    public function flightTicket(FlightBooking $flight, array $file, User $actor): CandidateDocument
    {
        if (!$this->gate->forUser($actor)->allows('edit', $flight)) {
            throw AuthorizationException::forPermission('travel.tickets.manage');
        }
        if (!in_array($flight->status, ['booked', 'issued', 'changed'], true)) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'A ticket can be attached once the flight is booked, and not after it is cancelled or flown.', []);
        }

        $doc = $this->store($flight->candidateId, 'flight_ticket', $file, null, $actor);
        $this->flights->setTicket($flight->id, $doc->id);
        $this->audit->log('ticket_attached', 'travel', 'flight_booking', $flight->id, ['document_id' => null], ['document_id' => $doc->id], null, $actor);

        return $doc;
    }

    /** @param array<string,mixed> $file */
    private function store(int $candidateId, string $typeKey, array $file, ?string $expiresAt, User $actor): CandidateDocument
    {
        $candidate = $this->candidates->findById($candidateId, $this->scopes->resolve($actor));
        if ($candidate === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, 'The candidate is not available to you.', []);
        }
        $type = $this->types->findByKey($typeKey);
        if ($type === null) {
            throw new DomainRuleException(DomainRuleException::RULE_VIOLATION, "The “{$typeKey}” document type is not configured.", []);
        }

        return $this->documents->upload($candidate, $type->id, $file, null, $expiresAt, $actor);
    }
}
