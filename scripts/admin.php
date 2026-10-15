<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit(1);
$email = $argv[1] ?? '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/admin.php registered-email@example.com\n");
    exit(1);
}
$user = query('SELECT user_id FROM user_account WHERE email=?', [strtolower($email)])->fetch();
if (!$user) throw new RuntimeException('Register the account before granting administrator access.');
query('UPDATE premium SET premium=2 WHERE user_id=?', [$user['user_id']]);
echo "Administrator access granted to existing account.\n";
