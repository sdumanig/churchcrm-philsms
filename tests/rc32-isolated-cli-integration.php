<?php
declare(strict_types=1);

/** Complete isolated migration CLI exercised only against disposable CI MySQL. */
$dsn = getenv('PHILSMS_TEST_DSN') ?: '';
if (getenv('PHILSMS_TEST_ALLOW_DDL') !== 'YES'
    || getenv('PHILSMS_LIVE_SEND') !== '0'
    || !preg_match('/(?:^|;)dbname=(philsms_test_staging_[a-zA-Z0-9_]+)(?:;|$)/', $dsn, $m)
    || !preg_match('/\Amysql:host=([^;]+)(?:;|$)/', $dsn, $h)) {
    throw new RuntimeException('Disposable staging database required.');
}
$database = $m[1];
$port = preg_match('/(?:^|;)port=(\d+)(?:;|$)/', $dsn, $p) ? $p[1] : '3306';
$autoload = realpath(__DIR__ . '/fixtures/rc32-real-perpl/vendor/autoload.php');
$script = realpath(__DIR__ . '/../tools/rc32-apply-isolated.php');
if ($autoload === false || $script === false) throw new RuntimeException('Missing fixture or CLI.');
$db = new PDO($dsn, getenv('PHILSMS_TEST_USER'), getenv('PHILSMS_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
if ($db->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('Disposable DB mismatch.');
}
$sql = file_get_contents(__DIR__ . '/../migrations/001_notification_queue.sql');
if ($sql === false) throw new RuntimeException('RC2 queue fixture missing.');
$db->exec($sql);
function invokeIsolated(string $script, string $autoload, string $database, array $overrides): array
{
    $env = array_merge($_ENV, [
        'PHILSMS_RC3_MIGRATE' => 'YES',
        'PHILSMS_RC3_STAGING' => 'YES',
        'PHILSMS_LIVE_SEND' => '0',
        'PHILSMS_RC3_DB_HOST' => '127.0.0.1',
        'PHILSMS_RC3_DB_PORT' => '3306',
        'PHILSMS_RC3_DB_NAME' => $database,
        'PHILSMS_RC3_DB_USER' => getenv('PHILSMS_TEST_USER'),
        'PHILSMS_RC3_DB_PASSWORD' => getenv('PHILSMS_TEST_PASSWORD'),
    ], $overrides);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
        . ' --apply ' . escapeshellarg($autoload) . ' ' . escapeshellarg($database);
    $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) throw new RuntimeException('Could not start migration subprocess.');
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $output];
}
foreach ([
    ['PHILSMS_RC3_MIGRATE' => 'NO'],
    ['PHILSMS_RC3_STAGING' => 'NO'],
    ['PHILSMS_LIVE_SEND' => '1'],
    ['PHILSMS_RC3_DB_NAME' => 'wrong_staging_database'],
] as $overrides) {
    [$status, $output] = invokeIsolated($script, $autoload, $database, $overrides);
    if ($status === 0 || !str_contains($output, 'REFUSED:')) {
        throw new RuntimeException('Unsafe invocation was not refused.');
    }
}
foreach ([1, 2] as $attempt) {
    [$status, $output] = invokeIsolated($script, $autoload, $database, []);
    if ($status !== 0 || !str_contains($output, 'PASS: isolated RC3 migrations')) {
        throw new RuntimeException('Isolated CLI attempt ' . $attempt . ' failed: ' . $output);
    }
}
if ((int) $db->query('SELECT COUNT(*) FROM philsms_schema_migrations')->fetchColumn() !== 3) {
    throw new RuntimeException('Expected three recorded RC3 migrations.');
}
echo "PASS: real-Perpl isolated migration CLI, refusals, idempotency and history\n";
