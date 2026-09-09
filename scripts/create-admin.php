<?php

declare(strict_types=1);

/**
 * Create (or update) an administrator account.
 *
 *   php scripts/create-admin.php --email=you@example.com --name="Jane Doe" [--role=super_admin] [--password=...]
 *
 * If --password is omitted a strong one is generated and printed once.
 * Requires the roles table to be seeded (php scripts/seed.php).
 */

use App\Support\Application;
use App\Support\Db;
use App\Support\Hash;
use App\Support\Ulid;

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$opts = getopt('', ['email:', 'name:', 'role::', 'password::']);
$email = strtolower(trim($opts['email'] ?? ''));
$name = trim($opts['name'] ?? '');
$role = trim($opts['role'] ?? 'super_admin');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
    fwrite(STDERR, "Usage: php scripts/create-admin.php --email=you@example.com --name=\"Your Name\" [--role=super_admin]\n");
    exit(1);
}

$db = $app->get(Db::class);
$hash = $app->get(Hash::class);

$roleId = $db->selectValue('SELECT id FROM roles WHERE name = :r', ['r' => $role]);
if ($roleId === null) {
    fwrite(STDERR, "Role '{$role}' not found. Run: php scripts/seed.php --class=RolesSeeder\n");
    exit(1);
}

$password = $opts['password'] ?? null;
$generated = false;
if ($password === null || $password === '') {
    $password = bin2hex(random_bytes(9)); // 18 hex chars
    $generated = true;
}

$existing = $db->selectValue('SELECT id FROM users WHERE email = :e', ['e' => $email]);

if ($existing !== null) {
    $db->affectingStatement(
        'UPDATE users SET name = :n, role_id = :rid, password_hash = :ph, is_active = 1,
         is_org_wide = 1, failed_login_count = 0, locked_until = NULL, must_change_password = 0,
         password_changed_at = UTC_TIMESTAMP() WHERE id = :id',
        ['n' => $name, 'rid' => $roleId, 'ph' => $hash->make($password), 'id' => $existing],
    );
    fwrite(STDOUT, "\n  Updated existing user #{$existing} ({$email}).\n");
} else {
    $id = $db->insertRow('users', [
        'public_id'     => Ulid::generate(),
        'name'          => $name,
        'email'         => $email,
        'password_hash' => $hash->make($password),
        'role_id'       => $roleId,
        'is_active'     => 1,
        'is_org_wide'   => 1,
        'password_changed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ]);
    fwrite(STDOUT, "\n  Created user #{$id} ({$email}) with role '{$role}'.\n");
}

if ($generated) {
    fwrite(STDOUT, "  Generated password: {$password}\n  (store it now — it is not shown again)\n");
}
fwrite(STDOUT, "\n");
