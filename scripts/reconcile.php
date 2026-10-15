<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

// Deliberately CLI-only: reconciliation is an operator capability, not a public API.
$userId = null;
if (count($argv) === 2 && $argv[1] === '--help') {
    echo "Usage: php scripts/reconcile.php [--user-id=123]\nExit: 0 clean, 1 drift, 2 invalid input or unavailable check.\n";
    exit(0);
}
if (count($argv) > 1) {
    $valid = count($argv) === 2 && preg_match('/\A--user-id=([1-9][0-9]*)\z/', $argv[1], $match);
    $userId = $valid ? filter_var($match[1], FILTER_VALIDATE_INT) : false;
    if ($userId === false) {
        fwrite(STDERR, "Usage: php scripts/reconcile.php [--user-id=123]\n");
        exit(2);
    }
}
try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    require dirname(__DIR__) . '/app/reconciliation.php';
    $report = reconcile_wallets($userId);
    if ($userId !== null && $report['accounts_checked'] === 0) {
        fwrite(STDERR, "Account not found; no reconciliation performed.\n");
        exit(2);
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit($report['status'] === 'ok' ? 0 : 1);
} catch (Throwable $error) {
    // Failed queries must never look like a clean ledger. Do not emit SQL or credentials.
    fwrite(STDERR, 'Reconciliation unavailable (' . get_class($error) . ").\n");
    exit(2);
}
