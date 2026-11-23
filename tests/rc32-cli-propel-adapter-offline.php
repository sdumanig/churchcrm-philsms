<?php
declare(strict_types=1);

/**
 * Offline contract test for the UNWIRED RC3.2 adapter.
 * Uses fake Propel classes: NOT evidence of compatibility with real ChurchCRM.
 */
$adapter = realpath(__DIR__ . '/../src/CliPropelAdapter.php');
if ($adapter === false) {
    throw new RuntimeException('Adapter missing.');
}
$source = file_get_contents($adapter);
foreach (['Bootstrapper::init(', 'LoadConfigs.php', 'Config.php'] as $forbidden) {
    // Comments may name forbidden functions/files; executable calls are checked below.
}
if (preg_match('/(?:require|include)(?:_once)?\s*\(?\s*[^;]*(?:Config\.php|LoadConfigs\.php)/i', $source)
    || preg_match('/(?<![a-zA-Z])Bootstrapper::init\s*\(/', preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source))) {
    throw new RuntimeException('Web bootstrap dependency found.');
}

$root = sys_get_temp_dir() . '/philsms_adapter_' . bin2hex(random_bytes(8));
$vendor = $root . '/vendor';
if (!mkdir($vendor, 0700, true)) {
    throw new RuntimeException('Cannot create isolated fixture.');
}
$autoload = $vendor . '/autoload.php';
file_put_contents($autoload, <<<'PHP'
<?php
namespace Propel\Runtime\Connection;
class ConnectionWrapper {}
class ConnectionManagerSingle {
    public function __construct(string $name) {}
    public function setConfiguration(array $config): void {
        if (($config['dsn'] ?? '') !== 'mysql:host=127.0.0.1;port=3306;dbname=philsms_test_staging_fixture;charset=utf8mb4') {
            throw new \RuntimeException('Unexpected DSN');
        }
    }
}
namespace Propel\Runtime;
class Service {
    public function checkVersion(int $version): void { if ($version !== 2) throw new \RuntimeException('Version mismatch'); }
    public function setAdapterClass(string $name, string $adapter): void {}
    public function setConnectionManager(object $manager): void {}
    public function setDefaultDatasource(string $name): void {}
}
class FakeStatement {
    public function fetchColumn(): string { return getenv('PHILSMS_FAKE_CONNECTED_DB') ?: 'philsms_test_staging_fixture'; }
}
class FakeConnection {
    public function query(string $sql): FakeStatement {
        if ($sql !== 'SELECT DATABASE()') throw new \RuntimeException('Unexpected query');
        return new FakeStatement();
    }
}
class Propel {
    public static function getServiceContainer(): Service { return new Service(); }
    public static function getWriteConnection(string $name): FakeConnection { return new FakeConnection(); }
}
PHP);
$runner = $root . '/runner.php';
file_put_contents($runner, <<<'PHP'
<?php
require $argv[1];
\ChurchCRM\Plugins\PhilSMS\CliPropelAdapter::initialize(
    $argv[2], '127.0.0.1', 3306, 'philsms_test_staging_fixture',
    'test_user', 'test_password', $argv[3]
);
echo "PASS\n";
PHP);
function runAdapterCase(string $runner, string $adapter, string $autoload, string $expected, array $overrides): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' '
        . escapeshellarg($adapter) . ' ' . escapeshellarg($autoload) . ' '
        . escapeshellarg($expected);
    $env = array_merge($_ENV, [
        'PHILSMS_LIVE_SEND' => '0',
        'PHILSMS_RC3_STAGING' => 'YES',
        'PHILSMS_FAKE_CONNECTED_DB' => 'philsms_test_staging_fixture',
    ], $overrides);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) throw new RuntimeException('Cannot spawn test.');
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $output];
}
try {
    [$code, $output] = runAdapterCase($runner, $adapter, $autoload, 'philsms_test_staging_fixture', []);
    if ($code !== 0 || !str_contains($output, 'PASS')) {
        throw new RuntimeException('Fake Propel initialization failed: ' . $output);
    }
    foreach ([
        ['philsms_test_staging_fixture', ['PHILSMS_LIVE_SEND' => '1']],
        ['philsms_test_staging_fixture', ['PHILSMS_RC3_STAGING' => 'NO']],
        ['production', []],
        ['philsms_test_staging_fixture', ['PHILSMS_FAKE_CONNECTED_DB' => 'wrong_staging_db']],
    ] as [$expected, $overrides]) {
        [$code] = runAdapterCase($runner, $adapter, $autoload, $expected, $overrides);
        if ($code === 0) throw new RuntimeException('Adapter accepted an invalid scenario.');
    }
    echo "PASS: offline adapter contract and refusals (fake Propel only)\n";
} finally {
    @unlink($runner);
    @unlink($autoload);
    @rmdir($vendor);
    @rmdir($root);
}
