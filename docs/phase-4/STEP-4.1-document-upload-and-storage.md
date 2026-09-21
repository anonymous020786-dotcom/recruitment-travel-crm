# Step 4.1 — Document upload, private storage & secure serve

**Status:** implemented; 471 tests green (+14). Opens Phase 4 (Documents).

**Scope note:** covers docs/00-ARCHITECTURE.md §11.1 (accept pipeline), §11.2
(storage), §11.3 (serve/download) — every upload is treated as hostile and
must pass every check, fail-closed. §11.4 (verify/reject/expire workflow) and
the per-candidate document checklist are the next Phase 4 slices.

## A. Files created

| File | Purpose |
|---|---|
| `app/Support/DocumentUpload.php` | The accept pipeline: upload-error check, size cap, server-detected MIME (`finfo`) against the document type's allowlist, magic-byte signature verification, best-effort PDF active-content scan (`/JavaScript`, `/JS`, `/OpenAction`, `/Launch`), image re-encode via GD (strips EXIF/metadata, caps dimensions at 6000px) when GD is available, sha256, and the final move into `storage/private/documents/<yy>/<mm>/<shard>/<ULID>.<ext>`. |
| `app/Models/DocumentType.php`, `app/Models/CandidateDocument.php` | Read models. `CandidateDocument` carries `isExpired()`/`isExpiringSoon()` (mirrors `Passport`) and `sizeLabel()`. |
| `app/Repositories/DocumentTypeRepository.php`, `app/Repositories/CandidateDocumentRepository.php` | The latter's `findByPublicId()` joins through to `candidates` for branch scope — download/preview are keyed only by the document's own `public_id` (§11.3), so the caller doesn't already know which candidate it belongs to. `updateFields()` is optimistic-locked (`record_version`) for the verification workflow the next step adds. `logAccess()` writes `document_access_log`. |
| `app/Services/DocumentService.php` | `upload()` (authorize → validate document type → run the pipeline → insert → audit), `authorizeAccess()` + `logAccess()` (called by the controller before streaming), `delete()` (row + the actual file on disk). |
| `app/Controllers/Crm/DocumentController.php` | `store`/`destroy` nested under a candidate; `download`/`preview` are the two top-level `public_id`-only routes §11.3 specifies. Streams via the existing `Response::stream()` (`readfile`, chunked, never loaded fully into memory — same idiom already used for CSV exports), sets `Content-Type`/`Content-Disposition`/`X-Content-Type-Options: nosniff`/`Cache-Control: private, no-store`, and logs the access after authorizing but before streaming. |
| `database/seeders/DocumentTypesSeeder.php` | 11 document types (photo, passport, national ID, CV, educational/experience certificates, medical, police clearance, visa copy, flight ticket, employment agreement) — PDF/JPEG/PNG by default, CV also accepts `.docx`. Registered in `DatabaseSeeder`. |
| `tests/Feature/DocumentServiceTest.php` | Upload success (real minimal PDF and PNG fixtures), rejection of disallowed MIME, oversized files, a PDF carrying an embedded `/JavaScript` token, upload errors, and an invalid document type; permission and cross-branch denial; delete (row + file); access logging; branch-scoped `findByPublicId`. |

## B. Files modified

- `app/Policies/CandidatePolicy.php` — `uploadDocument()` (branch-scoped `documents.upload` check).
- `app/Controllers/Crm/CandidateController.php` — `show()` now also loads `documents`, `documentTypes`, `canUploadDocument`, `canDeleteDocument`; the timeline label map gained `document_uploaded`/`document_deleted`.
- `resources/views/crm/candidates/show.php` — a "Documents" card: multipart upload form (type, file, optional expiry date), a list with status/expiry badges, Preview/Download links, and a gated Delete button.
- `routes/web.php` — `POST /candidates/{candidate}/documents`, `DELETE /candidates/{candidate}/documents/{document}`, and the two top-level `GET /documents/{document}/download` / `/preview` routes.
- `database/seeders/DatabaseSeeder.php` — registers `DocumentTypesSeeder`.
- **`.gitignore`** — fixed a real gap, same class of bug Step 2.7 found for `storage/private/.htaccess`: a bare `/storage/private/*` makes git treat `storage/private/documents` and `storage/private/backups` as opaque ignored directory *entries*, so it never even looks inside them — meaning `!/storage/**/.gitkeep` could never reach a `.gitkeep` one level deeper, and neither subdirectory (nor any `.htaccess` inside them) would survive a fresh clone. Fixed by explicitly un-ignoring the two subdirectory paths, then re-ignoring *their* contents one level deeper (`/storage/private/documents/**`) so real uploaded/backup files still never get tracked. Verified with `git check-ignore`: the placeholder `.gitkeep` files are now tracked, while a synthetic real file placed inside `storage/private/documents/` stays ignored.

## C. Migration

None — `document_types`, `candidate_documents`, `candidate_document_checklist`, `document_access_log` all shipped in `0001_initial_schema.sql`. `document_types` is populated by the new seeder, not a migration (it's reference/config data, same treatment as `lead_statuses`/`lead_sources`).

## F. Security / correctness

- **Never trusts client-supplied metadata.** The browser's `Content-Type` is ignored; the stored extension is looked up from `DocumentUpload::MIME_MAP` by the *server-detected* MIME type, never read from the client filename — this defeats the classic double-extension attack (`invoice.php.pdf`) architecturally rather than by pattern-matching the filename, which the spec's literal "reject double extensions" instruction would only approximate.
- Every accept-pipeline check is fail-closed and in order: error code → size → MIME allowlist → magic-byte signature → (PDF) active-content scan → (image) re-encode. A file that fails any step never reaches the filesystem or the database. Verified over HTTP: a `.txt` file renamed to `fake.pdf` with a forged `Content-Type: application/pdf` was rejected — `finfo` still saw plain text — and no row was created.
- Storage path is fully random (`ULID`), sharded by upload date + a 2-character shard from the ULID's own tail — no user input reaches the filesystem path at any point.
- Download/preview: `authorizeAccess()` runs *before* the file is touched; a missing row is a 404 with no distinction from an out-of-scope one (branch filtering happens inside `findByPublicId`'s own query, so "wrong branch" and "doesn't exist" are indistinguishable to the caller — no existence leak). Every successful access — download or preview — writes a `document_access_log` row with the actor and their IP (via the existing `Request::ipBinary()`), satisfying threat T14's audit requirement.
- Deleting a document removes the database row and the on-disk file together; if the row delete affects zero rows (already gone / wrong candidate), the file is never touched.
- **Documented, honest limitation**: the dev sandbox this was built and tested on has no `gd`/`imagick` extension loaded. `DocumentUpload::reencodeImage()` checks `extension_loaded('gd')` and, when absent, logs a warning and skips re-encoding rather than failing every image upload — the signature + `finfo` checks upstream still gate the file's real type either way. Hostinger shared hosting ships GD by default, so production is expected to exercise the full re-encode path; this was called out rather than silently assumed.

## H. Manual QA — verified (over HTTP, `php -S` against `crm_dev`)

- [x] Uploaded a real minimal PDF (multipart, spoofed original filename `my passport.pdf`) against the "Passport copy" type with an expiry date → 302; row created with server-detected `mime_type=application/pdf`, correct sha256, file present at the private sharded path
- [x] Downloaded it back → 200, `Content-Disposition: attachment; filename="my passport.pdf"`, `Content-Length` matching, byte-for-byte identical to the original upload, `nosniff` + `no-store` headers present
- [x] Previewed it → 200, `Content-Disposition: inline` (PDF is in the inline-eligible list)
- [x] Both accesses produced a `document_access_log` row with an IP recorded
- [x] Uploaded a `.txt` file renamed to `fake.pdf` with a forged `Content-Type: application/pdf` → rejected, document count unchanged (still 1, not 2)
- [x] Deleted the real document → row gone, file gone from disk, "No documents uploaded yet" reappeared
- [x] Cleaned up all synthetic lead/candidate/person/document/access-log rows; re-ran the full suite to confirm no leftover state
- [x] Dev admin password re-randomized post-test
- [x] Full suite **471 tests, 1051 assertions** (+14 new)

## I. Performance

- Upload is single-pass: one `finfo_file` call, one small byte-range read for the signature, one full-content read only for PDFs (the active-content scan) or images (re-encode), one `hash_file`, one filesystem move — no double-buffering of the whole file in memory beyond what GD's own re-encode needs for images.
- Download/preview streams via `readfile()` inside `Response::stream()` — the same chunked-write idiom already proven for CSV exports — so a large document never has to fit in PHP's memory limit at once.
- `CandidateDocumentRepository::forCandidate()` uses `idx_cand_docs_candidate`; `findByPublicId()`'s branch join uses the existing `uq_cand_docs_public_id` + `candidates` PK — both single indexed lookups.
