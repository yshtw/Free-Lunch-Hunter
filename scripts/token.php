<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

// Operator CLI. Tokens are issued for an existing account; the plaintext is printed once to stdout.
$usage = <<<TXT
Usage:
  php scripts/token.php issue <email> [--scopes=read] [--label=mcp] [--days=30]
  php scripts/token.php list <email>
  php scripts/token.php revoke <token-id>
Scopes: read, schedule:write, membership:redeem (read is always included).

TXT;
$command = $argv[1] ?? '';
$target = $argv[2] ?? '';
$options = ['scopes' => 'read', 'label' => 'mcp', 'days' => '30'];
foreach (array_slice($argv, 3) as $argument) {
    if (!preg_match('/\A--(scopes|label|days)=(.*)\z/s', $argument, $match)) {
        fwrite(STDERR, $usage);
        exit(2);
    }
    $options[$match[1]] = $match[2];
}
if (!in_array($command, ['issue', 'list', 'revoke'], true) || $target === '') {
    fwrite(STDERR, $usage);
    exit(2);
}
require dirname(__DIR__) . '/app/bootstrap.php';
try {
    if ($command === 'revoke') {
        $revoked = preg_match('/\A[a-f0-9]{16}\z/', $target)
            ? query('UPDATE api_tokens SET revoked_at=NOW() WHERE token_id=? AND revoked_at IS NULL', [$target])->rowCount() : 0;
        echo $revoked ? "Token revoked.\n" : "No active token with that id.\n";
        exit($revoked ? 0 : 1);
    }
    $user = query('SELECT user_id FROM user_account WHERE email=?', [strtolower($target)])->fetch();
    if (!$user) {
        fwrite(STDERR, "Account not found.\n");
        exit(1);
    }
    if ($command === 'list') {
        $rows = query('SELECT token_id,label,scopes,created_at,expires_at,last_used_at,revoked_at FROM api_tokens
            WHERE user_id=? ORDER BY created_at DESC,token_id', [$user['user_id']])->fetchAll();
        echo json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    $days = filter_var($options['days'], FILTER_VALIDATE_INT);
    $issued = issue_token((int) $user['user_id'], token_scopes($options['scopes']), $options['label'], $days === false ? 0 : $days);
    echo json_encode($issued, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    fwrite(STDERR, "Store this token now; it is not shown again.\n");
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}
