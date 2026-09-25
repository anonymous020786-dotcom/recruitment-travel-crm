# Step 14.6 — Per-user permission overrides (Admin → Users → Individual permissions)

Allow or deny **single permissions for one person** on top of their role — for cover, a trial, or a restriction — with an optional last day and a note. Super admin only (`roles.manage`: the same power as editing the role matrix). Migration **0022** adds `expires_at`, `note`, `granted_by`, `created_at` to `user_permissions` (run `php scripts/migrate.php`).

`PermissionService` already resolved overrides (**deny → allow → role**, super admin implicit); nothing else in the permission checks changed except that `PermissionRepository::overridesForUser` now ignores an expired override. The rules are in `App\Services\UserPermissionService`.

## The page
`/admin/users/{user}/permissions` (button "Individual permissions" on the user's page): role, how many permissions the role gives and how many are **in effect now**; the override table (permission, allow/deny, until, note, set by/when, Remove; ended ones dimmed); **Remove all overrides**; and the *Add or change an override* form (grouped permission list that marks what the role already holds, effect, last day, note). Every write asks for a fresh password confirmation and is audited (`permission_override_set|removed`, `permission_overrides_cleared` in module *users*, with old → new).

## Rules
- Only a super admin can change them, and only for someone who is **not** a super admin (they hold everything — so you can never change your own).
- `roles.manage` can never be **granted** to one person (it stays with the super admin alone, as for roles).
- An override must change something: *allow* something the role already grants, or *deny* something it does not, is refused.
- The last day is a date from today to two years ahead and ends at 23:59:59 UTC that day. An expired override stops applying immediately; it stays listed as "ended" and is removed by the nightly cleanup after 30 days.
- At most 100 overrides per person. Saving the same permission again replaces the earlier override.
- Takes effect at the person's next click (the per-request permission memo is cleared).

## Tests
`UserPermissionOverridesTest` (10): allow/deny/restore against the real permission resolution, replace-on-resave, expiry at the boundary and pruning, every refusal (unknown/reserved permission, meaningless allow/deny, bad effect, long note, 7 bad dates), who may be changed and by whom (super admin target incl. yourself, non-super actor, unknown user), the 100 cap, audit old/new, 403 for other roles and password confirmation on writes, the full page flow, and the "no form for a super admin" case.
