<?php
// CLI: php manage-user.php <username> [password]
// Creates the user, or resets the password if the user exists. Generates a password if none given.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('BEAT', true);
require __DIR__ . '/bootstrap.php';

$username = $argv[1] ?? null;
if (!$username) {
    fwrite(STDERR, "Usage: php manage-user.php <username> [password]\n");
    exit(1);
}
$password = $argv[2] ?? bin2hex(random_bytes(8));
$hash = password_hash($password, PASSWORD_DEFAULT);

$exists = q('SELECT id FROM users WHERE username = ?', [$username])->fetchColumn();
if ($exists) {
    q('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $exists]);
    q('DELETE FROM sessions WHERE user_id = ?', [$exists]);
    echo "Password reset for '$username'.\n";
} else {
    q('INSERT INTO users (username, password_hash) VALUES (?, ?)', [$username, $hash]);
    echo "Created user '$username'.\n";
}
if (!isset($argv[2])) echo "Password: $password\n";
