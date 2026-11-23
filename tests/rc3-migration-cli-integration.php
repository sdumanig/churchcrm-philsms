<?php
declare(strict_types=1);

/** Disposable MySQL exercise of the real gated CLI entry point. */
$dsn = getenv('PHILSMS_TEST_DSN') ?: '';
if (getenv('PHILSMS_TEST_ALLOW_DDL') !== 'YES' ||
    getenv('PHILSMS_LIVE_SEND') !== '0' ||
    !preg_match('/(?:^|;)dbname=(philsms_test_staging_[a-zA-Z0-9_]+)(?:;|$)/', $dsn, $match)) {
    fwrite(STDERR, "REFUSED: scratch staging MySQL DSN and explicit DDL guard required.\n");
    exit(2);
}
$dbname = $match[1];
$db = new PDO($dsn, getenv('PHILSMS_TEST_USER') ?: '', getenv('PHILSMS_TEST_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
if ($db->query('SELECT DATABASE()')->fetchColumn() !== $dbname) {
    throw new RuntimeException('Scratch database mismatch.');
}
$db->exec('CREATE TABLE IF NOT EXISTS philsms_notification_queue (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$dir = sys_get_temp_dir() . '/philsms_rc3_cli_' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) {
    throw new RuntimeException('Cannot create scratch bootstrap directory.');
}
$bootstrap = $dir . '/bootstrap.php';
$stub = <<<'PHP'
<?php
namespace Propel\Runtime;
final class Propel {
    private static ?\PDO $db = null;
    public static function getWriteConnection(string $name): \PDO {
        if ($name !== 'default') { throw new \RuntimeException('Unexpected connection'); }
        if (self::$db === null) {
            self::$db = new \PDO(
                getenv('PHILSMS_TEST_DSN'),
                getenv('PHILSMS_TEST_USER'),
                getenv('PHILSMS_TEST_PASSWORD'),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        }
        return self::$db;
    }
}
PHP;
file_put_contents($bootstrap, $stub);
$script = realpath(__DIR__ . '/../tools/rc3-apply-migrations.php');
if ($script === false) { throw new RuntimeException('Missing migration command.'); }
function invoke(string $script, string $bootstrap, string $database, array $flags): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
        . ' --apply ' . escapeshellarg($bootstrap) . ' ' . escapeshellarg($database);
    $env = array_merge($_ENV, [
        'PHILSMS_TEST_DSN' => getenv('PHILSMS_TEST_DSN'),
        'PHILSMS_TEST_USER' => getenv('PHILSMS_TEST_USER'),
        'PHILSMS_TEST_PASSWORD' => getenv('PHILSMS_TEST_PASSWORD'),
        'PHILSMS_LIVE_SEND' => '0',
        'PHILSMS_RC3_STAGING' => 'YES',
        'PHILSMS_RC3_MIGRATE' => 'YES',
    ], $flags);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start CLI process.'); }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}
try {
    foreach ([
        ['PHILSMS_RC3_MIGRATE' => 'NO'],
        ['PHILSMS_LIVE_SEND' => '1'],
    ] as $flags) {
        [$exit, $output] = invoke($script, $bootstrap, $dbname, $flags);
        if ($exit === 0 || !str_contains($output, 'REFUSED:')) {
            throw new RuntimeException('Safety gate did not refuse an unauthorized invocation.');
        }
    }
    [$exit, $output] = invoke($script, $bootstrap, 'other_staging_db', []);
    if ($exit === 0 || !str_contains($output, 'Connected database does not match')) {
        throw new RuntimeException('Wrong database name was not refused.');
    }
    foreach ([1, 2] as $run) {
        [$exit, $output] = invoke($script, $bootstrap, $dbname, []);
        if ($exit !== 0 || !str_contains($output, 'PASS: RC3 migrations')) {
            throw new RuntimeException('CLI migration run ' . $run . ' failed: ' . $output);
        }
    }
    $versions = (int) $db->query('SELECT COUNT(*) FROM philsms_schema_migrations')->fetchColumn();
    if ($versions !== 3) {
        throw new RuntimeException('Expected exactly three migration versions.');
    }
    echo "PASS: CLI safety refusals, first application, idempotent rerun, and history\n";
} finally {
    @unlink($bootstrap);
    @rmdir($dir);
}
