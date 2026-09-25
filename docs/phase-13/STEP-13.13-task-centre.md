# Step 13.13 — The task centre (`/tasks`)

**A bug found on the way:** the sidebar's **Tasks** entry (`tasks.view`) pointed at `/tasks`, which had no route — a dead link (found when the HTML-minifier test tried to render every sidebar page). Tasks existed in the database (a candidate's follow-ups, the "Collect payment" tasks the cron creates) but there was nowhere to *see*, complete or reassign them, except one candidate at a time. This step builds the page.

## What it does
- **List with tabs and counts:** Open · Overdue · Due today · Completed · Cancelled · All. Filters: search title, priority, "about" (lead, candidate, application, invoice, payment, visa, travel, employer, tour booking, or standalone) and — for people who may see everyone's — assignee. Open tasks sort by due date (undated last), then urgency. Each row links to the record it is about (`/candidates/…`, `/invoices/…`, …; a record that no longer exists simply has no link). Automatic (cron-created) tasks are badged.
- **New task** (`tasks.create`): title, details, priority, due date/time (not in the past), assignee — a standalone to-do. The assignee is notified (in-app; not when you assign to yourself).
- **Complete** (`tasks.complete`), **Cancel** (`tasks.edit`), **Reassign** (`tasks.assign`, inline). Only pending tasks change; closed ones are history. Every action is audited (`task_created/completed/cancelled/reassigned`). After an action you return to the same filtered list (the return address can only ever be a `/tasks` URL).
- **Dashboard:** two new tiles for the signed-in person — *My tasks overdue* and *My tasks due today* — linking to the matching tab (computed live per user, outside the shared cached snapshot).
- Notifications gained a `task` link type that opens `/tasks`.

## Who sees what
Tasks inside your branches; and unless you hold `tasks.view_all` (managers, admins), only those **assigned to or created by you**. A task you may not see is answered exactly like one that does not exist (404). A task can only be given to an **active person in your branches**, and it then belongs to that person's branch. Permissions are checked in the service as well as on the routes.

## Code
`TaskRepository` (+ `page`, `tabCounts`, `linksFor`, `reassign`, `assignable`, `countsForAssignee`, branch-scoped `findByPublicId`), `TaskService`, `TaskController`, views `crm/tasks/{index,form}.php`, routes `tasks.*`, `Task` model gained `createdBy`. Demo data (`scripts/demo/tasks.php`) adds 15 tasks so the page has content locally.

## Tests (+13; 1124 total, 4 223 assertions)
`TaskCentreTest`: filter normalisation; visibility (own/created/colleague/other branch/admin, person filter only for those who see everyone); the tabs split by state and date (a completed task is never overdue); ordering; record links (employer, travel list, a vanished record); creating (row, branch, audit, notification, none to self); validation and branch limits (past date, bad time, other branch, deactivated, nobody — nothing saved); complete/cancel with hidden = 404 and closed = message; reassign (needs `tasks.assign`, out-of-branch refused, moves the task, notifies once, same person is a no-op); the screens end to end incl. escaping of hostile titles, the safe return address, a closed task giving a message not an error; scoped `findByPublicId`; dashboard tiles. The HTML-minifier page test now covers `/tasks` and `/tasks/create`.
