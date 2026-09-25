# Step 13.14 — Admin → Branches and Admin → Lead sources

Until now the two lists everything hangs off — the organisation's **branches** and the **lead sources** on the lead form — could only be changed with SQL. Both now have screens.

## Branches (`/admin/branches`; `branches.view` to see, `branches.manage` to change — super admin and admin by default)
- List with code, location, contact, active people, live leads and status; **Add branch** / **Edit**: name, code, address, city, state, country code, phone, email.
- Rules (`BranchAdminService`): name and code unique (the code is upper-cased; letters, digits, single hyphens, ≤ 20); phone/email/country validated; nothing is ever deleted — a branch is **deactivated** (hidden from every "choose a branch" list and the user form, records kept) and can be reactivated.
- **Lock-out guards:** the last active branch cannot be deactivated, and neither can a branch that would leave an active, non-organisation-wide person with no active branch ("3 active people are still assigned only to this branch — move them in Admin → Users first"). Organisation-wide and inactive people, and people who also belong to another active branch, do not block it.
- Audited: `branch_created / updated / deactivated / reactivated` (updates log only the fields that changed; saving without changes writes nothing).
- **Migration 0018** adds `branches.view/manage` and grants them to super_admin/admin (so an installed database needs `php scripts/migrate.php`, not a re-seed).

## Lead sources (`/admin/lead-sources`; view = `settings.view`, change = `settings.manage`)
- Add (appended at the end), rename, switch on/off, move up/down (the order is the dropdown's order). A switched-off source stops being offered on new leads; old leads and reports keep it. Names unique (case-insensitive), one line, ≤ 80 characters; whitespace tidied.
- **"Website" is protected:** the public enquiry inbox stamps it on the leads it converts, so it cannot be renamed or switched off. At least one source must stay on. Moving tidies the numbering to 1…n (a seeded list where every source had order 0 becomes properly ordered). Audited (`lead_source_*`). Each source links to its leads.

## Tests (+14; 1138 total, 4 299 assertions)
`OrganisationAdminTest`: branch creation/normalisation/audit; 12 invalid inputs incl. duplicates; edits log only changes and a no-op writes nothing; permission; last-active-branch guard; the stranded-people rules in all four cases (primary, two branches, organisation-wide, inactive) and unblocking after a move; a deactivated branch leaves the user form's choices; sources: append + tidy names + validation; Website/last-source protection; rename/deactivate change what the lead form offers (`LeadRepository::sourceOptions`); moving swaps neighbours and renumbers; permissions; and who reaches which screen plus the full screen flows (escaping, validation messages, unknown ids give a message not a crash). The minifier page test now covers the three new screens.
