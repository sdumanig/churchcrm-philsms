<?php

declare(strict_types=1);

/**
 * Opt-in MySQL integration smoke test for the RC3 migration runner.
 *
 * Run only against a disposable database named philsms_test_*:
 * PHILSMS_TEST_DSN='mysql:host=127.0.0.1;dbname=philsms_test_rc3;charset=utf8mb4' \
 * PHILSMS_TEST_USER='...' PHILSMS_TEST_PASSWORD='...' \
 * PHILSMS_TEST_ALLOW_DDL=YES php tests/migration-runner-integration.php
 *
 * Does not drop databases/tables, access ChurchCRM data, or send SMS.
 * Requires PDO MySQL. Do not point at a ChurchCRM database.
 */

$dsn = getenv('PHILSMS_TEST_DSN') ?: '';
$user = getenv('PHILSMS_TEST_USER') ?: '';
$password = getenv('PHILSMS_TEST_PASSWORD') ?: '';

if (getenv('PHILSMS_TEST_ALLOW_DDL') !== 'YES') {
    fwrite(STDERR, "SKIP: Explicit PHILSMS_TEST_ALLOW_DDL=YES required.\n");
    exit(2);
}

if (!preg_match('/\A mysql:/x', $dsn) ||
    !preg_match('/(?:^|;)dbname=(philsms_test_[a-zA-Z0-9_]+)(?:;|$)/', $dsn)) {
    fwrite(STDERR, "REFUSED: DSN must target a philsms_test_* MySQL database.\n");
    exit(2);
}

if (getenv('PHILSMS_LIVE_SEND') === '1') {
    fwrite(STDERR, "REFUSED: Live SMS must be disabled.\n");
    exit(2);
}

$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 3,
];
$connection = new PDO($dsn, $user, $password, $pdoOptions);
$second = new PDO($dsn, $user, $password, $pdoOptions);

// Ensure the actual connection is to the explicitly named scratch database.
$db = (string) $connection->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/\Aphilsms_test_[a-zA-Z0-9_]+\z/D', $db)) {
    throw new RuntimeException('Refusing to run outside disposable test database.');
}

// Standalone test adapter; no ChurchCRM bootstrap or production configuration.
if (!class_exists('Propel\\Runtime\\Propel', false)) {
    eval('namespace Propel\\Runtime; final class Propel {
        public static \\PDO $connection;
        public static function getWriteConnection(string $name): \\PDO {
            return self::$connection;
        }
    }');
}

\Propel\Runtime\Propel::$connection = $connection;
require_once __DIR__ . '/../src/MigrationRunner.php';

use ChurchCRM\Plugins\PhilSMS\MigrationRunner;

$passed = 0;
function verify(bool $ok, string $description): void
{
    global $passed;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    echo 'PASS: ' . $description . "\n";
    $passed++;
}

// Seed the complete RC2 prerequisite fixture before testing RC3.
foreach (['001_notification_queue.sql', '002_message_templates.sql',
          '003_default_message_templates.sql'] as $file) {
    $sql = file_get_contents(__DIR__ . '/../migrations/' . $file);
    if ($sql === false) throw new RuntimeException('Missing RC2 fixture.');
    $connection->exec($sql);
}
$connection->exec('CREATE TABLE IF NOT EXISTS philsms_schema_migrations (
    version VARCHAR(100) NOT NULL,
    checksum CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
foreach (['001_notification_queue', '002_message_templates',
          '003_default_message_templates'] as $version) {
    $sql = file_get_contents(__DIR__ . '/../migrations/' . $version . '.sql');
    $record = $connection->prepare(
        'INSERT INTO philsms_schema_migrations (version, checksum) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE checksum = VALUES(checksum)'
    );
    $record->execute([$version, hash('sha256', $sql)]);
}
// The runner must preserve RC2 history and add exactly three RC3 versions.
MigrationRunner::run();
verify((int) $connection->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name = 'philsms_schema_migrations'"
)->fetchColumn() === 1, 'First run creates the history table');

MigrationRunner::run();
verify((int) $connection->query(
    'SELECT COUNT(*) FROM philsms_schema_migrations'
)->fetchColumn() === 6, 'Second run retains three RC2 and three RC3 migrations');
verify((int) $connection->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'philsms_leaders'"
)->fetchColumn() === 1, 'Leader registry table exists');
verify((int) $connection->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'philsms_leader_assignments'"
)->fetchColumn() === 1, 'Leader assignment table exists');
verify((int) $connection->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'philsms_assignment_activity'"
)->fetchColumn() === 1, 'Assignment activity table exists');

$lockName = 'philsms_schema_migrations';
$lock = $second->prepare('SELECT GET_LOCK(?, 0)');
$lock->execute([$lockName]);
verify((int) $lock->fetchColumn() === 1, 'Second connection holds advisory lock');

try {
    $start = microtime(true);
    try {
        MigrationRunner::run();
        throw new RuntimeException('FAIL: Contended runner unexpectedly succeeded');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'Unable to acquire PhilSMS migration lock.') {
            throw $e;
        }
    }
    verify(microtime(true) - $start >= 9, 'Runner times out under lock contention');
} finally {
    $release = $second->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}

MigrationRunner::run();
verify(true, 'Runner succeeds after competing lock is released');

echo "\nResults: {$passed} passed\n";
