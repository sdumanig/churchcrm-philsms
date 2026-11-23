<?php
declare(strict_types=1);

namespace ChurchCRM\Plugins\PhilSMS;

use Propel\Runtime\Connection\ConnectionManagerSingle;
use Propel\Runtime\Connection\ConnectionWrapper;
use Propel\Runtime\Propel;

/**
 * Minimal Propel setup for an explicitly identified, isolated staging database.
 *
 * This class intentionally does not load ChurchCRM Config.php, LoadConfigs.php,
 * or Bootstrapper::init(), and does not run migrations. Its caller must provide
 * independently reviewed credentials and a ChurchCRM 7.8.7 vendor autoloader.
 *
 * Not wired into rc3-apply-migrations.php until isolated compatibility tests pass.
 */
final class CliPropelAdapter
{
    public static function initialize(
        string $autoloadPath,
        string $host,
        int $port,
        string $database,
        string $username,
        string $password,
        string $expectedDatabase
    ): void {
        if (PHP_SAPI !== 'cli' || getenv('PHILSMS_LIVE_SEND') !== '0') {
            throw new \RuntimeException('CLI execution with live sending disabled is required.');
        }
        if (getenv('PHILSMS_RC3_STAGING') !== 'YES') {
            throw new \RuntimeException('Explicit staging authorization is required.');
        }
        if (!preg_match('/\A[a-zA-Z0-9_]+\z/D', $database)
            || !str_contains(strtolower($database), 'staging')
            || str_contains(strtolower($database), 'prod')
            || $database !== $expectedDatabase) {
            throw new \RuntimeException('Explicit staging database identity mismatch.');
        }
        if ($host === '' || $port < 1 || $port > 65535
            || $username === '' || $password === '') {
            throw new \RuntimeException('Invalid database connection configuration.');
        }
        if (!str_starts_with($autoloadPath, '/')
            || !is_file($autoloadPath) || !is_readable($autoloadPath)
            || is_link($autoloadPath)
            || basename($autoloadPath) !== 'autoload.php'
            || basename(dirname($autoloadPath)) !== 'vendor') {
            throw new \RuntimeException('An absolute ChurchCRM vendor autoloader is required.');
        }

        // Only an operator-reviewed installation path may be supplied.
        // Do not execute ChurchCRM's normal web/application bootstrap.
        require_once $autoloadPath;

        $service = Propel::getServiceContainer();
        $service->checkVersion(2);
        $service->setAdapterClass('default', 'mysql');
        $manager = new ConnectionManagerSingle('default');
        $manager->setConfiguration([
            'dsn' => 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database
                . ';charset=utf8mb4',
            'user' => $username,
            'password' => $password,
            'settings' => ['charset' => 'utf8mb4'],
            'classname' => '\\' . ConnectionWrapper::class,
            'model_paths' => [0 => 'src', 1 => 'vendor'],
        ]);
        $service->setConnectionManager($manager);
        $service->setDefaultDatasource('default');

        // Read-only identity verification; no schema installation or upgrade.
        $connected = (string) Propel::getWriteConnection('default')
            ->query('SELECT DATABASE()')->fetchColumn();
        if ($connected !== $expectedDatabase) {
            throw new \RuntimeException('Connected database does not match staging target.');
        }
    }
}
