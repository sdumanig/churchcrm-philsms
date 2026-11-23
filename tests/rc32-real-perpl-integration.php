<?php
declare(strict_types=1);

/**
 * Read-only integration test with real Perpl 2.7.0 and disposable MySQL.
 * Never invokes the migration command or ChurchCRM's application bootstrap.
 */
$autoload = realpath(getenv('PHILSMS_TEST_PERPL_AUTOLOAD') ?: __DIR__ . '/fixtures/rc32-real-perpl/vendor/autoload.php');
$adapter = realpath(__DIR__ . '/../src/CliPropelAdapter.php');
if ($autoload === false || $adapter === false) {
    throw new RuntimeException('Missing test dependencies or adapter.');
}
if (PHP_VERSION_ID < 80400 || getenv('PHILSMS_TEST_ALLOW_DDL') !== 'YES') {
    throw new RuntimeException('PHP 8.4 and disposable database authorization required.');
}
$dsn = getenv('PHILSMS_TEST_DSN') ?: '';
if (!preg_match('/\\Amysql:host=([^;]+)(?:;|$)/', $dsn, $hostMatch)
    || !preg_match('/(?:^|;)dbname=(philsms_test_staging_[a-zA-Z0-9_]+)(?:;|$)/', $dsn, $dbMatch)) {
    throw new RuntimeException('Refusing non-disposable database configuration.');
}
$port = preg_match('/(?:^|;)port=(\d+)(?:;|$)/', $dsn, $portMatch) ? (int) $portMatch[1] : 3306;
$database = $dbMatch[1];
$user = getenv('PHILSMS_TEST_USER') ?: '';
$password = getenv('PHILSMS_TEST_PASSWORD') ?: '';
if ($user === '' || $password === '') {
    throw new RuntimeException('Missing disposable MySQL credentials.');
}
require_once $adapter;
$mode = $argv[1] ?? 'success';
if ($mode === 'wrong-expected') {
    $expected = 'other_staging_database';
} else {
    $expected = $database;
}
if ($mode === 'success') {
    \ChurchCRM\Plugins\PhilSMS\CliPropelAdapter::initialize(
        $autoload, $hostMatch[1], $port, $database, $user, $password, $expected
    );
    $actual = \Propel\Runtime\Propel::getWriteConnection('default')
        ->query('SELECT DATABASE()')->fetchColumn();
    if ($actual !== $database) {
        throw new RuntimeException('Real Perpl connected to an unexpected database.');
    }
    echo "PASS: real Perpl 2.7.0, PHP 8.4, disposable MySQL, read-only identity check\n";
} else {
    \ChurchCRM\Plugins\PhilSMS\CliPropelAdapter::initialize(
        $autoload, $hostMatch[1], $port, $database, $user, $password, $expected
    );
    throw new RuntimeException('Refusal test unexpectedly accepted.');
}
