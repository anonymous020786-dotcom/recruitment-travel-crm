# Step 13.10 — Recruitment funnel report

**Reports → Recruitment → Recruitment funnel** (`applications.view`; branch-scoped like every report; date range, screen / print / CSV like the others).

For the applications **made in the period**, it shows how many reached each stage — Applied → Shortlisted → Interviewed → Selected by employer → Offer accepted → Medical completed → Visa approved → Departed → Placed — with two percentages: of all applied, and of the previous stage (where the drop-off is).

- "Reached" means the application ever had that status (its append-only history) **or** has it now, so an application that has moved on, or was rejected after the interview, still counts for the stages it passed.
- One pass over `applications` with a per-application flag from `application_status_history` (uses `idx_ash_application`); the period filter is index-friendly (`applied_at >= from AND < to + 1 day`); a period with no applications returns nine zero rows.
- Cohort view: it answers "of what came in this quarter, how far did it get", which is stable, unlike counting status changes that happened this quarter.

## Tests
`ReportServiceTest`: exact funnel (4 applications — one placed, one at interview_scheduled, one rejected after interview, one still applied — plus one outside the period and one in another branch that must not count; counts and both percentage columns asserted), and the empty period. Catalogue test updated. Live smoke (php -S): catalogue entry, page, CSV, bad range handled.
Full suite: **1077 tests, 3 863 assertions** (3 skipped: GD-only image tests).
