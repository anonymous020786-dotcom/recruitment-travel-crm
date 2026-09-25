<?php

declare(strict_types=1);

namespace App\Controllers\Crm;

use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\StaleRecordException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Repositories\CandidateDocumentRepository;
use App\Repositories\CandidateRepository;
use App\Services\DocumentService;
use App\Storage\ObjectStorage;
use App\Support\DocumentUpload;

/**
 * Document upload (nested under a candidate) plus the two top-level,
 * public_id-only routes docs/00-ARCHITECTURE.md §11.3 specifies for serving a
 * file: authenticate, authorize, load, stream, log — never `include`/`require`
 * an uploaded file and never pass its path to a shell.
 */
final class DocumentController extends CrmController
{
    public function __construct(
        private readonly CandidateRepository $candidates,
        private readonly CandidateDocumentRepository $documents,
        private readonly DocumentService $service,
        private readonly DocumentUpload $upload,
        private readonly ObjectStorage $objects,
    ) {
    }

    public function store(Request $request, string $candidate): Response
    {
        $model = $this->findCandidate($candidate);

        try {
            $typeId = (int) $request->input('document_type_id', 0);
            $this->service->upload(
                $model,
                $typeId,
                $request->file('file') ?? [],
                $this->blankToNull((string) $request->input('issued_on', '')),
                $this->blankToNull((string) $request->input('expires_at', '')),
                $this->currentUser(),
            );
            flash('status', 'Document uploaded.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Could not upload that document.');
        }

        return Response::redirect('/candidates/' . $model->publicId . '#documents');
    }

    public function destroy(string $candidate, string $document): Response
    {
        $model = $this->findCandidate($candidate);

        try {
            $this->service->delete($model, (int) $document, $this->currentUser());
            flash('status', 'Document removed.');
        } catch (AuthorizationException $e) {
            session()?->flash('error_toast', 'You do not have permission to remove documents.');
        } catch (DomainRuleException $e) {
            session()?->flash('error_toast', $e->getMessage());
        }

        return Response::redirect('/candidates/' . $model->publicId . '#documents');
    }

    public function startReview(string $candidate, string $document): Response
    {
        $model = $this->findCandidate($candidate);

        try {
            $doc = $this->findDocument($model, $document);
            $this->service->startReview($model, $doc->id, $this->currentUser(), $doc->recordVersion);
            flash('status', 'Marked as under review.');
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to review documents.');
        } catch (DomainRuleException|StaleRecordException $e) {
            session()?->flash('error_toast', $e instanceof StaleRecordException
                ? 'This document changed just now. Please try again.'
                : $e->getMessage());
        }

        return Response::redirect('/candidates/' . $model->publicId . '#documents');
    }

    public function verify(string $candidate, string $document): Response
    {
        $model = $this->findCandidate($candidate);

        try {
            $doc = $this->findDocument($model, $document);
            $this->service->verify($model, $doc->id, $this->currentUser(), $doc->recordVersion);
            flash('status', 'Document verified.');
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to verify documents.');
        } catch (DomainRuleException|StaleRecordException $e) {
            session()?->flash('error_toast', $e instanceof StaleRecordException
                ? 'This document changed just now. Please try again.'
                : $e->getMessage());
        }

        return Response::redirect('/candidates/' . $model->publicId . '#documents');
    }

    public function reject(Request $request, string $candidate, string $document): Response
    {
        $model = $this->findCandidate($candidate);

        try {
            $doc = $this->findDocument($model, $document);
            $this->service->reject($model, $doc->id, (string) $request->input('rejection_reason', ''), $this->currentUser(), $doc->recordVersion);
            flash('status', 'Document rejected.');
        } catch (ValidationException $e) {
            session()?->flash('error_toast', $e->first() ?? 'Give a reason for rejecting this document.');
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to reject documents.');
        } catch (DomainRuleException|StaleRecordException $e) {
            session()?->flash('error_toast', $e instanceof StaleRecordException
                ? 'This document changed just now. Please try again.'
                : $e->getMessage());
        }

        return Response::redirect('/candidates/' . $model->publicId . '#documents');
    }

    public function toggleChecklist(Request $request, string $candidate, string $type): Response
    {
        $model = $this->findCandidate($candidate);

        try {
            $this->service->toggleChecklistRequirement($model, (int) $type, $request->boolean('required'), $this->currentUser());
        } catch (AuthorizationException) {
            session()?->flash('error_toast', 'You do not have permission to manage the document checklist.');
        }

        return Response::redirect('/candidates/' . $model->publicId . '#documents');
    }

    public function download(Request $request, string $document): Response
    {
        return $this->serve($request, $document, 'download');
    }

    public function preview(Request $request, string $document): Response
    {
        return $this->serve($request, $document, 'preview');
    }

    // ---- internals -------------------------------------------------

    private function serve(Request $request, string $publicId, string $action): Response
    {
        $doc = $this->documents->findByPublicId($publicId, $this->scope());
        if ($doc === null) {
            abort(404, 'Document not found.');
        }

        try {
            $this->service->authorizeAccess($doc, $this->currentUser(), $action);
        } catch (AuthorizationException) {
            abort(403);
        }

        if (ObjectStorage::isRemote($doc->storageDisk)) {
            return $this->serveFromBucket($request, $doc, $action);
        }

        $absolute = $this->upload->absolutePath($doc->storagePath);
        if (!is_file($absolute)) {
            abort(404, 'The file for this document is no longer available.');
        }

        $this->service->logAccess($doc, $this->currentUser(), $action, $request->ipBinary());

        $inline = $action === 'preview' && in_array($doc->mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true);
        $disposition = ($inline ? 'inline' : 'attachment') . '; filename="' . $this->sanitizeFilename($doc->originalName) . '"';

        return Response::stream(static function () use ($absolute): void {
            readfile($absolute);
        }, 200, [
            'Content-Type' => $doc->mimeType,
            'Content-Disposition' => $disposition,
            'Content-Length' => (string) filesize($absolute),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * A document that lives in an S3/R2 bucket. Access was authorised above and is logged here, before any byte moves.
     * A download is answered with a redirect to a signed link that expires in seconds (the bytes never touch this server); a
     * preview — or any request when the super admin chose "through this server" — is streamed through, so it stays inside the
     * page's own origin.
     */
    private function serveFromBucket(Request $request, CandidateDocument $doc, string $action): Response
    {
        $this->service->logAccess($doc, $this->currentUser(), $action, $request->ipBinary());
        $name = $this->sanitizeFilename($doc->originalName);
        $inline = $action === 'preview' && in_array($doc->mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true);

        if ($action === 'download' && $this->objects->deliveryMode() === 'redirect') {
            $url = $this->objects->signedUrl($doc->storageDisk, $doc->storagePath, $name, $doc->mimeType);
            if ($url !== null) {
                return Response::redirect($url, 302, allowExternal: true)->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
            }
        }

        $tmp = $this->objects->fetchToTemp($doc->storageDisk, $doc->storagePath);
        if ($tmp === null) {
            abort(503, 'The file store is temporarily unavailable. Please try again in a moment.');
        }

        return Response::stream(static function () use ($tmp): void {
            readfile($tmp);
            @unlink($tmp);
        }, 200, [
            'Content-Type' => $doc->mimeType,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            'Content-Length' => (string) filesize($tmp),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function findCandidate(string $publicId): Candidate
    {
        $model = $this->candidates->findByPublicId($publicId, $this->scope());
        if ($model === null) {
            abort(404, 'Candidate not found.');
        }

        return $model;
    }

    private function findDocument(Candidate $candidate, string $documentId): CandidateDocument
    {
        $doc = $this->documents->findInCandidate((int) $documentId, $candidate->id);
        if ($doc === null) {
            abort(404, 'Document not found.');
        }

        return $doc;
    }

    private function blankToNull(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }

    private function sanitizeFilename(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? 'document';

        return mb_substr($safe, 0, 150);
    }
}
