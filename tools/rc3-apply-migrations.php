<?php
declare(strict_types=1);

/**
 * Explicit, CLI-only RC3 schema migration entry point for an already-active
 * ChurchCRM plugin. This file is never registered as an HTTP route.
 *
 * A trusted operator must supply a reviewed ChurchCRM bootstrap file that
 * initializes Propel. This tool does not guess installation paths or load
 * database credentials itself.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
function refuse(string $reason): never
{
    fwrite(STDERR, "REFUSED: {$reason}\n");
    exit(2);
}
if ($argc !== 4 || $argv[1] !== '--apply') {
    refuse('Usage: php tools/rc3-apply-migrations.php --apply /absolute/ChurchCRM/bootstrap.php expected_staging_db');
}
if (getenv('PHILSMS_RC3_MIGRATE') !== 'YES' ||
    getenv('PHILSMS_RC3_STAGING') !== 'YES') {
    refuse('PHILSMS_RC3_MIGRATE=YES and PHILSMS_RC3_STAGING=YES are both required.');
}
if (getenv('PHILSMS_LIVE_SEND') !== '0') {
    refuse('Explicit PHILSMS_LIVE_SEND=0 is required.');
}
$bootstrap = $argv[2];
$expectedDatabase = $argv[3];
if (!str_starts_with($bootstrap, '/') || !is_file($bootstrap) ||
    !is_readable($bootstrap) || is_link($bootstrap)) {
    refuse('Bootstrap must be a readable, regular, absolute non-symlink file.');
}
if (!preg_match('/\A[a-zA-Z0-9_]+\z/D', $expectedDatabase) ||
    !str_contains(strtolower($expectedDatabase), 'staging') ||
    str_contains(strtolower($expectedDatabase), 'prod')) {
    refuse('Expected database must be a named staging database, not production.');
}
// Do not let the caller use a path under the plugin as a fake bootstrap.
$pluginRoot = realpath(__DIR__ . '/..');
$bootstrapReal = realpath($bootstrap);
if ($pluginRoot === false || $bootstrapReal === false ||
    str_starts_with($bootstrapReal, $pluginRoot . DIRECTORY_SEPARATOR)) {
    refuse('Bootstrap must be outside the plugin directory.');
}
require_once $bootstrapReal;
if (!class_exists('Propel\\Runtime\\Propel')) {
    refuse('ChurchCRM bootstrap did not initialize Propel.');
}
$connection = \Propel\Runtime\Propel::getWriteConnection('default');
$database = (string) $connection->query('SELECT DATABASE()')->fetchColumn();
if ($database !== $expectedDatabase) {
    refuse('Connected database does not match the explicitly expected staging database.');
}
$queueExists = $connection->prepare(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'philsms_notification_queue'"
);
$queueExists->execute();
if ((int) $queueExists->fetchColumn() !== 1) {
    refuse('RC2 notification queue is absent; do not migrate an unverified installation.');
}
require_once __DIR__ . '/../src/MigrationRunner.php';
\ChurchCRM\Plugins\PhilSMS\MigrationRunner::run();
echo "PASS: RC3 migrations verified/applied to the explicitly named staging database.\n";
