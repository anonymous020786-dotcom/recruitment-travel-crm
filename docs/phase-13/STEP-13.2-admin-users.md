# Step 13.2 — Admin → Users

The Admin menu pointed at `/admin/users`, which did not exist: accounts could only be created from the command line
(`scripts/create-admin.php`). Now administrators manage staff accounts in the app.

## Screens (`/admin/users`, permissions `users.view` to look, `users.manage` to change)

- **List** — counts (all / active / deactivated / locked out), search (name, email, phone), filters (role, status), sort, paging;
  badges for status, locked and 2FA.
- **Add / edit** — name, phone, role, branches (or organisation-wide), primary branch. The email is the sign-in name and is fixed
  once created. A new account gets a generated 14-character temporary password (no look-alike characters, always upper + lower +
  digit) **shown once** and `must_change_password` set, so the person chooses their own at first sign-in.
- **Detail** — account facts, recent sign-ins, live session count, and the actions: deactivate / reactivate (deactivating also
  ends every session, remember-me token and trusted device), unlock, email a password-reset link, issue a temporary password
  (step-up password confirmation + signs them out), sign out everywhere, remove two-factor (step-up).

## The rules (in `UserAdminService`, not in the screens)

| Rule | Why |
|---|---|
| Only a super admin can create, edit, deactivate, reset or otherwise act on a super admin, or grant that role | an admin must not be able to escalate to, or take over, the top role |
| Nobody changes their own role, branch reach or active state, or issues themselves a temporary password | no self-lock-out or self-promotion; someone else must do it |
| The last active super admin can never be deactivated or demoted | the organisation can always be administered |
| Only an admin / super admin can make someone organisation-wide | branch isolation is not delegable to managers |
| A user who is not organisation-wide needs ≥ 1 branch; the primary branch is always one of their branches | every user can see some work, scoping stays consistent |
| A role change ends the person's sessions | an old session must not keep the old privileges |
| Every change is audited; the audit log never contains password material | |

## Also

- The accessibility audit (`scripts/html-audit.php`) found an `aria-describedby` pointing at a missing id on the role select — fixed.
- `RouteAuditTest` now records `UserAdminRepository` as an audited exception to "public ids are branch-scoped" (staff accounts are
  organisation-wide and protected by permission + the rules above).

## Tests — `UserAdminTest` (14); full suite **1002 tests, 3 404 assertions**

Creation (one-time password verifies against the stored hash and has the right shape, branches synced, primary branch always
included, audit row present and free of secrets, validation of every field, duplicate email case-insensitively); the privilege
rules (super-admin grant/edit/deactivate/reset refused for an admin, allowed for a super admin; org-wide refused for a manager);
self-protection; **last super admin** (real accounts are paused inside the test and restored in `finally`); deactivate ends
sessions and the account no longer resolves as signed in, reactivate restores it; unlock, temporary password, 2FA reset; the
screens (403 for a counselor, 200 for an admin, 302 anonymous, 404 unknown; list filters and escaping; create shows the
temporary password exactly once; a super admin's page is read-only for a plain admin).

Live check with a real server and a super admin: the four pages audit with 0 errors after the fix.
