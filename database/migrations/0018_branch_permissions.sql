-- Migration 0018 — permissions for the Admin → Branches screens.
-- (New permissions reach an already-installed database here; the seeder only runs at install time.)
-- Branches are organisation-level: only super_admin and admin get them by default (adjustable in Admin → Roles).

INSERT IGNORE INTO permissions (name, module, label) VALUES
    ('branches.view',   'branches', 'View branches'),
    ('branches.manage', 'branches', 'Create, edit and deactivate branches');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN ('branches.view', 'branches.manage')
WHERE r.name IN ('super_admin', 'admin');
