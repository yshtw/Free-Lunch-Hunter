<?php
declare(strict_types=1);
// Test-only CLI bridge; never exposed by the web document root.
if (PHP_SAPI !== 'cli' || getenv('FLH_TEST_RUN') !== '1'
    || !preg_match('/dbname=[A-Za-z0-9_]+_test(?:;|$)/', getenv('DB_DSN') ?: '')) exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$statement = query($input['sql'], $input['parameters'] ?? []);
echo json_encode($statement->columnCount() ? $statement->fetchAll() : [], JSON_THROW_ON_ERROR);
