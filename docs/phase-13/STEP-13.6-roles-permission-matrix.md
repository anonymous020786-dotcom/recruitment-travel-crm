# Step 13.6 — Roles & permission matrix editor

**Admin → Roles** (`/admin/roles`, super admin only — the `roles.manage` permission) lets an agency tune what each role can do without a deploy.

## What it does
- `/admin/roles` — every role with its permission count and headcount (links to the users list filtered by role).
- `/admin/roles/{role}` — the full catalogue, grouped by module, as checkboxes. Anything that differs from the shipped default
  (`config/permissions.php`) is flagged "differs from default". **Save permissions** replaces the role's set; **Reset to default** restores the shipped one.
- Both writes need a fresh password confirmation (`confirm` step-up), are throttled, CSRF-protected and audited (`role_permissions_changed` /
  `role_permissions_reset`, with exactly which permissions were added and removed; saving an unchanged set writes nothing).
- Permissions are read from the database per request, so a change applies at each person's next click — nobody has to sign out.

## Rules (in `RoleAdminService`, not in the screen)
1. Only a super admin edits the matrix; the `super_admin` role is implicit-everything and read-only.
2. `roles.manage` can never be granted to another role (privilege-escalation guard). The shipped defaults never include it either.
3. Only catalogue permissions can be granted (unknown names are a validation error).
4. A role that can act in a module must be able to view it (`<module>.view`, where the module has one).
5. The Admin role always keeps `dashboard.view`, `users.view`, `users.manage` — administrators can never lock themselves out.

## Code
`PermissionMatrix::resolve()` (new — the `module.*` / `*` / `!revoke` token expansion, extracted from `RolePermissionsSeeder`, which now uses it),
`RoleAdminRepository`, `RoleAdminService`, `RoleAdminController`, views `crm/admin/roles/{index,show}.php`, routes `admin.roles.*`, sidebar entry "Roles".

## Tests
`tests/Feature/RoleAdminTest.php` (13): token expansion; save + audit + immediate effect on a real user's permissions; unchanged save is a no-op;
reset; **every shipped default satisfies rules 1–5** (guards the config against drifting out of step with the editor); each rule; screens are
403 for admins; saving through the screen; saving needs a fresh confirmation (redirects to `/confirm-password`, nothing changes); refused change
leaves the role untouched. The tests snapshot and restore the real `role_permissions` table.
Live smoke (php -S): login → list → edit → flagged as custom → `roles.manage` refused with explanation → reset restores 42 permissions.
Full suite: **1032 tests, 3 536 assertions** (3 skipped: GD-only image tests).

## Note
Re-running `php scripts/seed.php` re-applies the shipped matrix and will discard custom grants made here — the seeder header says so. Compiled CSS rebuilt.
