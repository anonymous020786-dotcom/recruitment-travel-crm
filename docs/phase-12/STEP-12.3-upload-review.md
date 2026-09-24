# Step 12.3 — Upload review

Scope: the candidate-document pipeline (`App\Support\DocumentUpload`, `DocumentService`, `DocumentController`) and
the lead-import CSV upload (`LeadImportService`). Checked against `docs/00-ARCHITECTURE.md` §11.

## Findings and fixes

| # | Finding | Severity | Fix |
|---|---|---|---|
| 1 | **The sanitised image was never stored.** Images are re-encoded (EXIF/metadata/trailing data stripped) into a temp copy, but for a real HTTP upload the code then `move_uploaded_file()`d the **original** into storage, and recorded the hash of the re-encoded copy. So the control was bypassed in production and the stored hash did not match the stored bytes. Tests missed it because they use plain temp files (a different branch). | high | store the bytes that were validated and hashed: the re-encoded copy for images, the upload for everything else |
| 2 | Decompression bomb: GD allocates ~4 bytes/pixel *before* the dimension cap could run, so a tiny PNG declaring 30000×30000 px would exhaust memory (500 on the upload). | medium | read the declared size from the header first (`getimagesize`) and refuse what cannot fit in the remaining `memory_limit` ("too many pixels") |
| 3 | The size limit trusted the client-reported `size`. | medium | check `filesize()` of the file on disk |
| 4 | Any existing path was accepted as `tmp_name` and copied when it was not an HTTP upload (a testing convenience). | medium (only if a request could ever name a path) | in production the file must be an `is_uploaded_file()`; elsewhere a temp file is still accepted for tests/scripts |
| 5 | PDF scan was substring-based: `/J#61vaScript` (hex-escaped name) slipped through, `/JSON` was a false positive; no check for `/RichMedia`, `/EmbeddedFile`, `/AA`, `/Encrypt` (an encrypted PDF hides its content from the scan). | medium | decode `#xx` escapes first, match whole names only, add those tokens; encrypted PDFs get a clear "upload an unprotected copy" message |
| 6 | CSV import checked only that the *client's filename* ended in `.csv`. | low | also require a text MIME by content and no NUL bytes (a zip / UTF-16 file renamed `.csv` is refused with a clear message) |
| 7 | Import files were written to `storage/imports` with mode 0755 / default file mode. | low | directory 0700, file 0600 |

Verified as already correct: extension is derived from the detected MIME (never the client name — `passport.php.exe`
is stored as `<ULID>.pdf`); random ULID storage names under `storage/private/documents/yy/mm/shard/`; `storage/` is
outside the web root; downloads are permission- and branch-scoped, logged (`document_access_log`), streamed with
`Content-Type` from the DB, `Content-Disposition` with a sanitised name, `X-Content-Type-Options: nosniff` and
`Cache-Control: private, no-store`; inline preview only for PDF/JPEG/PNG; exports are CSV-injection-safe (`Csv`).

## Known limits (accepted, documented)

- The PDF scan cannot see keys inside compressed object streams. The real defences are that stored PDFs are never
  executed or parsed server-side and are always served with `nosniff`.
- No antivirus (optional in the design; needs `clamdscan`, unavailable on shared hosting).
- If the `gd` extension is missing the image re-encode is skipped (and logged) and the signature + `finfo` checks still
  apply. **Hostinger enables GD by default — confirm in hPanel › PHP extensions during the deployment dry-run.**

## Tests

`tests/Feature/DocumentUploadTest.php` (23): stored under a random name with the extension from content; hash and
size are those of the stored bytes; renamed executable / PHP / HTML refused; disallowed type; empty, oversized,
failed-upload and lying-size cases; production refuses non-HTTP uploads; nine active-content PDFs (incl. the hex
escapes) refused; encrypted PDF message; `/JSON`, `/Launchpad`, `/AAAAAA+Arial` not mistaken for tokens; a JPEG with
appended `<?php` code is re-encoded so the code never reaches storage; a 30000×30000 declared image refused before
decoding; an undecodable image refused; CSV upload must be text. Image tests need GD (skipped otherwise — run
`php -d extension=gd vendor/bin/phpunit`). `DocumentServiceTest` now uses a genuinely oversized file instead of a
forged `size`. Full suite: **882 tests, 2813 assertions** with GD (2805 + 3 skipped without).

**HTTP smoke test** (real multipart upload on `php -S`): a valid PDF is stored, listed and downloads byte-identical
with the right headers; a JavaScript PDF and an `MZ…` executable named `.pdf` are each refused with the right message.
Rows and files removed afterwards.
