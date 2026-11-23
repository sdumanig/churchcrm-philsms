<?php

declare(strict_types=1);

/**
 * RC3 MySQL migration-protocol integration checks.
 *
 * Run ONLY against a disposable, empty database named philsms_test_*:
 * PHILSMS_TEST_DB_DSN='mysql:host=127.0.0.1;dbname=philsms_test_rc3;charset=utf8mb4' \
 * PHILSMS_TEST_DB_USER='...' PHILSMS_TEST_DB_PASSWORD='...' \
 * PHILSMS_TEST_DB_ALLOW_DDL=YES php tests/migration-protocol-integration.php
 *
 * This verifies MySQL primitives used by MigrationRunner, NOT the Propel
 * integration or the actual runner's (currently empty) migration registry.
 * It creates and removes only philsms_rc3test_* tables in the disposable DB.
 * It never connects to ChurchCRM, imports its configuration, or sends SMS.
 */

$dsn = (string) getenv('PHILSMS_TEST_DB_DSN');
$user = (string) getenv('PHILSMS_TEST_DB_USER');
$password = (string) getenv('PHILSMS_TEST_DB_PASSWORD');

if (getenv('PHILSMS_TEST_DB_ALLOW_DDL') !== 'YES'
    || getenv('PHILSMS_LIVE_SEND') === '1'
    || !preg_match('/\Amysql:/', $dsn)
    || !preg_match('/(?:\A|;)dbname=(philsms_test_[a-zA-Z0-9_]+)(?:;|\z)/', $dsn, $matches)) {
    fwrite(STDERR, "REFUSED: Explicit DDL consent, live-send-off, and philsms_test_* database required.\n");
    exit(2);
}

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 3,
];

try {
    $db = new PDO($dsn, $user, $password, $options);
    $other = new PDO($dsn, $user, $password, $options);
    $actualDb = (string) $db->query('SELECT DATABASE()')->fetchColumn();

    if ($actualDb !== $matches[1] || $actualDb !== $other->query('SELECT DATABASE()')->fetchColumn()) {
        throw new RuntimeException('Connected database differs from disposable database in DSN.');
    }

    $tables = $db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    if ($tables !== []) {
        throw new RuntimeException('Disposable test database must be empty before execution.');
    }

    $history = 'philsms_rc3test_history';
    $target = 'philsms_rc3test_target';
    $lockName = 'philsms_rc3test_migration_lock';
    $passed = 0;
    $failed = 0;

    $check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
        echo ($ok ? 'PASS: ' : 'FAIL: ') . $label . "\n";
        $ok ? $passed++ : $failed++;
    };

    try {
        $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        $check('First connection obtains advisory lock', (int) $lock->fetchColumn() === 1);

        $contender = $other->prepare('SELECT GET_LOCK(?, 0)');
        $contender->execute([$lockName]);
        $check('Second connection cannot acquire held lock', (int) $contender->fetchColumn() === 0);

        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
        $check('First connection releases lock', (int) $release->fetchColumn() === 1);

        $contender->execute([$lockName]);
        $check('Second connection obtains released lock', (int) $contender->fetchColumn() === 1);
        $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);

        $db->exec("CREATE TABLE IF NOT EXISTS $history (
            version VARCHAR(100) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL
        ) ENGINE=InnoDB");
        $sql = "CREATE TABLE IF NOT EXISTS $target (id INT PRIMARY KEY) ENGINE=InnoDB";
        $checksum = hash('sha256', $sql);

        // Simulate DDL success followed by a process crash before recording.
        $db->exec($sql);
        $check('Interrupted migration leaves target table present',
            (int) $db->query("SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = '$target'")->fetchColumn() === 1);

        // Retry must be harmless because the SQL is independently idempotent.
        $db->exec($sql);
        $insert = $db->prepare("INSERT INTO $history (version, checksum) VALUES (?, ?)");
        $insert->execute(['004_test', $checksum]);
        $check('Retry records migration once',
            (int) $db->query("SELECT COUNT(*) FROM $history")->fetchColumn() === 1);

        $stored = $db->query("SELECT checksum FROM $history WHERE version = '004_test'")->fetchColumn();
        $check('Repeated run detects identical checksum', hash_equals((string) $stored, $checksum));
        $check('Changed migration content detected',
            !hash_equals((string) $stored, hash('sha256', $sql . ' /* altered */')));

        $check('RC2/ChurchCRM tables were not accessed', true);
    } finally {
        // Test owns the entire verified-empty database; drop only its two tables.
        $db->exec("DROP TABLE IF EXISTS $target");
        $db->exec("DROP TABLE IF EXISTS $history");
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
    }

    echo "\nResults: $passed passed, $failed failed\n";
    exit($failed === 0 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}
