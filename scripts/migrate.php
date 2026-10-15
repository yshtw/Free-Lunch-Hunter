<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit(1);

$sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
$checksum = hash('sha256', $sql);
$lock = query('SELECT GET_LOCK(CONCAT(DATABASE(),\':schema\'),10)')->fetchColumn();
if ((int) $lock !== 1) throw new RuntimeException('Could not acquire migration lock');
try {
    $known = query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()
        AND table_name=\'schema_migrations\'')->fetchColumn();
    if (!$known && (int) query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() > 0) {
        throw new RuntimeException('Unversioned database detected. Preserve it and use a new empty database; see docs/architecture.md.');
    }
    db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations(version INT PRIMARY KEY,checksum CHAR(64) NOT NULL,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $existing = query('SELECT checksum FROM schema_migrations WHERE version=1')->fetchColumn();
    if ($existing && !hash_equals($existing, $checksum)) {
        throw new RuntimeException('Applied migration checksum differs. Add a new migration instead of editing applied SQL.');
    }
    if (!$existing) {
        // MySQL/MariaDB DDL commits implicitly. All statements are repeatable after interruption.
        db()->exec($sql);
        query('INSERT INTO schema_migrations(version,checksum) VALUES(1,?)', [$checksum]);
    }
    echo "Schema v1 ready (nine application tables).\n";
} finally {
    query('SELECT RELEASE_LOCK(CONCAT(DATABASE(),\':schema\'))');
}
