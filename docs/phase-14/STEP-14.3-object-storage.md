# Step 14.3 — Amazon S3 and Cloudflare R2 document storage, with cost-saving tools

No SDK, no Composer package: `App\Storage` implements the S3 API directly (works for Amazon S3, Cloudflare R2 and other S3-compatible services).

## The building blocks
- **`SigV4`** — AWS Signature V4 (signed headers and pre-signed URLs). **Verified against the three worked examples published in the AWS documentation** (GET with a Range header, PUT with a storage class and a `$` in the key, and a pre-signed GET) — our signatures match Amazon's byte for byte, which is what every S3-compatible service checks.
- **`S3Client`** — `put` (streams the file, signs its real SHA-256 so the service rejects corruption; optional storage class, cache-control, `x-amz-meta-*`), `get` (to memory or streamed to a file), `head`, `delete`, `presignGet` (forced download name and content type, quotes stripped from the name, ≤ 7 days), `list` (paged), `checkBucket` (a "test connection" that explains 403 vs 404 vs unreachable), `putLifecycle`. Virtual-hosted addressing on S3, path-style on R2 and for bucket names with dots. Nothing throws on a bad HTTP status; callers get the status and S3 error code.
- **`Transport`** (`CurlTransport`: TLS verified, no redirects followed, bounded timeouts, files streamed both ways) — swapped for a recording fake in the tests, so nothing here needed a real account to be tested.

## What the super admin gets
- **Admin → Integrations → Document storage** (new): store new uploads on *This server* / *Amazon S3* / *Cloudflare R2*; delivery *signed link* (default) or *through this server*; link lifetime; the cost-saving ages; backup retention. S3 and R2 credentials are entered under their own Integrations cards (secrets encrypted, write-only — step 14.2).
- **Admin → Storage** (`integrations.view`; actions need `integrations.manage`):
  - where documents live now (server / S3 / R2: counts and sizes), provider status and **Test connection**;
  - **Move the next 25 documents** to the bucket (also `php scripts/storage-migrate.php [--dry-run] [--batch=N] [--max=N]` for big volumes): each document is uploaded with its hash, **read back with a HEAD and its size compared**, and only then is its record switched and the server copy deleted. Failures and missing files stay exactly as they were and are counted — repeatable and resumable;
  - **Apply cost-saving rules**: writes a lifecycle configuration to the bucket — documents move to Standard-IA after N days (min. 30) and, on S3, to Glacier Instant Retrieval after M days (still opens instantly); backups expire after the retention period; unfinished multipart uploads are aborted after 7 days;
  - a **monthly cost estimate** comparing S3 Standard, S3 with the rules, R2 Standard and R2 with the rules, from the real amount stored, the share older than the tier-down age, and download/upload volumes (editable). List prices live in `config/storage_pricing.php` with their "as of" date and links — an estimate, not a quote.

## How documents behave
- **Upload:** validated and re-encoded as before, then pushed to the active bucket and the server copy removed; the row's `storage_disk` records where it lives. **If the bucket is unreachable the document stays on the server disk** (logged) — a storage outage never loses a document or fails the upload. Old documents keep being served from wherever they are, so switching provider never strands files.
- **Download:** access is authorised and **logged first**; then a **302 to a signed link that lives ~2 minutes** (the bytes never touch this server — bandwidth saving; R2 has no egress fee either) — or, for previews and when "through this server" is chosen, streamed through PHP from the bucket so it stays in the page's origin. A bucket that cannot deliver gives a clear 503, not a broken file. Nobody who may not open a document ever gets a link.
- **Delete** removes the object from the bucket (or the file from disk).
- **Off-site backups:** the nightly backup files are copied to `backups/` in the bucket, verified, and old remote backups pruned (see docs/BACKUP-RESTORE.md).

## Honest limits
Verified with request-shape/signature tests and fakes, **not against a live bucket** — do the first run with a test bucket: *Test connection*, upload one document, download it, then *Move the next 25*. Documents inside the bucket are not in the local documents backup; enable bucket versioning for point-in-time recovery.

## Tests (+37; 1191 total)
`SigV4Test` (5, AWS vectors) · `S3ClientTest` (13: request shape and signature on the wire, addressing styles, HEAD/GET/DELETE, streaming download, presigned URL contents and signature, expiry clamp, listing, bucket-check messages, failures, lifecycle XML and its Content-MD5) · `ObjectStorageDocumentsTest` (19: upload placement and the outage fail-safe, delete, signed-link vs proxy delivery, 503, authorisation before any link, migrator success / failure / verification mismatch / dry run / missing files, cost arithmetic checked by hand, admin screens and actions, off-site backups incl. verification, unsafe names, pruning).
