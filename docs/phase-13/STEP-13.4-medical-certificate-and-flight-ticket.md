# Step 13.4 — Attach a medical certificate and a flight ticket

The schema already had `medical_records.certificate_document_id` and `flight_bookings.ticket_document_id`, and the document types
`medical_certificate` and `flight_ticket`, but nothing let anyone attach the file. Now a record can carry its paperwork.

- **Medical** — on the candidate's Medical card, once the candidate has attended: *Attach certificate* / *Replace certificate* (PDF, JPEG or PNG).
  The certificate's expiry date is copied from the medical result, so the document expiry job also watches it.
- **Flight** — on the application's Travel card, for a booked / issued / changed flight: *Attach ticket* / *Replace ticket*.
  A planned, cancelled or flown flight refuses a ticket.
- **One pipeline** — the file goes through the ordinary candidate-document upload (`DocumentService::upload`): type check by content, size
  limit, image re-encoding, active-content PDF scan, private storage, verification workflow, access log. So it also appears in the
  candidate's **Documents** tab, and the record just points at it. **Replacing keeps the old file** in Documents and moves the pointer.
- **Access** — attaching needs `medical.edit` / `travel.tickets.manage` **and** `documents.upload` (routes and service both enforce it);
  the download link shows only with `documents.view` and goes through the normal permission + branch check + access log.
- Audited (`certificate_attached`, `ticket_attached`); rate-limited with the `upload` bucket.

Code: `AttachmentService`; `MedicalRepository::setCertificate` / `FlightRepository::setTicket`; the models now expose the attached document's
public id and file name; `POST /medical/{medical}/certificate`, `POST /flights/{flight}/ticket`; the two card views (multipart forms with labelled file inputs).

Tests — `AttachmentTest` (7): certificate becomes a `medical_certificate` candidate document and the medical points at it (with audit);
replacing keeps both files and moves the pointer; refused before the medical was attended, an executable is refused and never becomes the
certificate (no orphan document); a read-only user is refused; a ticket attaches to a booked flight; planned / cancelled / flown flights refuse;
end to end through the router with a real file entry — 302 back to the candidate's medical section, the candidate page then shows the download link,
"Replace certificate" and the multipart form, and a post with no file changes nothing. Full suite: **1017 tests, 3 461 assertions**.

Not done: itinerary line edit-in-place (package itinerary lines can be added and removed, not edited).
