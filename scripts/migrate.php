<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit(1);

// Version 1 is the canonical schema; later versions are numbered files in database/migrations.
$migrations = [1 => dirname(__DIR__) . '/database/schema.sql'];
foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $file) {
    if (!preg_match('/\A(\d{3})_[a-z0-9_]+\.sql\z/', basename($file), $match) || (int) $match[1] < 2
        || isset($migrations[(int) $match[1]])) {
        throw new RuntimeException('Invalid or duplicate migration file name: ' . basename($file));
    }
    $migrations[(int) $match[1]] = $file;
}
ksort($migrations);
if (array_keys($migrations) !== range(1, count($migrations))) {
    throw new RuntimeException('Migration versions must be contiguous.');
}

$lock = query('SELECT GET_LOCK(CONCAT(DATABASE(),\':schema\'),10)')->fetchColumn();
if ((int) $lock !== 1) throw new RuntimeException('Could not acquire migration lock');
try {
    $known = query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()
        AND table_name=\'schema_migrations\'')->fetchColumn();
    if (!$known && (int) query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() > 0) {
        throw new RuntimeException('Unversioned database detected. Preserve it and use a new empty database; see docs/architecture.md.');
    }
    db()->exec('CREATE TABLE IF NOT EXISTS schema_migrations(version INT PRIMARY KEY,checksum CHAR(64) NOT NULL,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $applied = query('SELECT version,checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (array_diff_key($applied, $migrations) as $version => $_) {
        throw new RuntimeException("Database has migration $version, which this checkout does not contain.");
    }
    foreach ($migrations as $version => $file) {
        $sql = file_get_contents($file);
        $checksum = hash('sha256', $sql);
        if (isset($applied[$version])) {
            if (!hash_equals($applied[$version], $checksum)) {
                throw new RuntimeException("Applied migration $version checksum differs. Add a new migration instead of editing applied SQL.");
            }
            continue;
        }
        // MySQL/MariaDB DDL commits implicitly. All statements are repeatable after interruption.
        db()->exec($sql);
        query('INSERT INTO schema_migrations(version,checksum) VALUES(?,?)', [$version, $checksum]);
    }
    echo 'Schema v' . array_key_last($migrations) . " ready.\n";
} finally {
    query('SELECT RELEASE_LOCK(CONCAT(DATABASE(),\':schema\'))');
}
