<?php
declare(strict_types=1);

/**
 * RC3.2 isolated migration entry point. No ChurchCRM application bootstrap.
 *
 * Usage: php tools/rc32-apply-isolated.php --apply /absolute/path/to/vendor/autoload.php expected_staging_db
 * Credentials: PHILSMS_RC3_DB_HOST, _PORT, _NAME, _USER, _PASSWORD
 * Operator must also set PHILSMS_RC3_MIGRATE=YES, PHILSMS_RC3_STAGING=YES,
 * PHILSMS_LIVE_SEND=0. Never commit credentials.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
function isolatedRefuse(string $message): never
{
    fwrite(STDERR, 'REFUSED: ' . $message . "\n");
    exit(2);
}
if ($argc !== 4 || $argv[1] !== '--apply') {
    isolatedRefuse('Usage: --apply /absolute/ChurchCRM/vendor/autoload.php expected_staging_db');
}
if (getenv('PHILSMS_RC3_MIGRATE') !== 'YES'
    || getenv('PHILSMS_RC3_STAGING') !== 'YES'
    || getenv('PHILSMS_LIVE_SEND') !== '0') {
    isolatedRefuse('Explicit migration/staging authorization and disabled live sending required.');
}
$autoload = $argv[2];
$expected = $argv[3];
$database = getenv('PHILSMS_RC3_DB_NAME') ?: '';
$host = getenv('PHILSMS_RC3_DB_HOST') ?: '';
$portRaw = getenv('PHILSMS_RC3_DB_PORT') ?: '';
$user = getenv('PHILSMS_RC3_DB_USER') ?: '';
$password = getenv('PHILSMS_RC3_DB_PASSWORD') ?: '';
if (!preg_match('/\A[a-zA-Z0-9_]+\z/D', $expected)
    || !str_contains(strtolower($expected), 'staging')
    || str_contains(strtolower($expected), 'prod')
    || $expected !== $database
    || !preg_match('/\A[0-9]{1,5}\z/D', $portRaw)) {
    isolatedRefuse('Explicit staging database identity or port invalid.');
}
$pluginRoot = realpath(__DIR__ . '/..');
$autoloadReal = realpath($autoload);
if ($pluginRoot === false || $autoloadReal === false
    || str_starts_with($autoloadReal, $pluginRoot . DIRECTORY_SEPARATOR)
    || $autoloadReal !== $autoload || is_link($autoload)) {
    isolatedRefuse('Use a reviewed absolute non-symlink autoloader outside the plugin.');
}
require_once __DIR__ . '/../src/CliPropelAdapter.php';
try {
    \ChurchCRM\Plugins\PhilSMS\CliPropelAdapter::initialize(
        $autoload, $host, (int) $portRaw, $database, $user, $password, $expected
    );
    $connection = \Propel\Runtime\Propel::getWriteConnection('default');
    $queue = $connection->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'philsms_notification_queue'"
    );
    $queue->execute();
    if ((int) $queue->fetchColumn() !== 1) {
        isolatedRefuse('RC2 queue absent; unverified installation.');
    }
    require_once __DIR__ . '/../src/MigrationRunner.php';
    \ChurchCRM\Plugins\PhilSMS\MigrationRunner::run();
} catch (\Throwable $e) {
    // CI-only diagnostics: never expose credentials, DSNs or arbitrary exception text.
    if (getenv('PHILSMS_TEST_DIAGNOSTICS') === 'YES'
        && getenv('PHILSMS_TEST_ALLOW_DDL') === 'YES'
        && $host === '127.0.0.1'
        && str_starts_with($database, 'philsms_test_staging_')) {
        fwrite(STDERR, 'CI DIAGNOSTIC: ' . get_class($e) . ' (code ' . (int) $e->getCode() . ')\\n');
    }
    isolatedRefuse('Isolated migration aborted; consult protected server logs.');
}
echo "PASS: isolated RC3 migrations applied/verified on explicitly named staging database.\n";
