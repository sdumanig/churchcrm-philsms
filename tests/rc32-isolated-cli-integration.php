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
$autoload = realpath(getenv('PHILSMS_TEST_PERPL_AUTOLOAD') ?: __DIR__ . '/fixtures/rc32-real-perpl/vendor/autoload.php');
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
        'PHILSMS_TEST_ALLOW_DDL' => getenv('PHILSMS_TEST_ALLOW_DDL') ?: '',
        'PHILSMS_TEST_DIAGNOSTICS' => getenv('PHILSMS_TEST_DIAGNOSTICS') ?: '',
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
// RC2 history (001-003) is expected after the initializer test. RC3
// history (004-006) must be absent before the first authorized RC3 run.
$historyExists = (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'philsms_schema_migrations'")->fetchColumn();
if ($historyExists === 1) {
    $rows = $db->query("SELECT version, checksum FROM philsms_schema_migrations")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $version = (string) $row['version'];
        if (preg_match('/\\A00[456]_/', $version)) {
            throw new RuntimeException('Unexpected preexisting RC3 migration history.');
        }
        if (preg_match('/\\A00[123]_/', $version)) {
            $path = __DIR__ . '/../migrations/' . $version . '.sql';
            if (!is_file($path) || !hash_equals(hash('sha256', (string) file_get_contents($path)), (string) $row['checksum'])) {
                throw new RuntimeException('Invalid RC2 migration history fixture.');
            }
        }
    }
}
// Reject incomplete RC2 history before applying any RC3 migration.
$db->exec("DELETE FROM philsms_schema_migrations WHERE version = '002_message_templates'");
[$status, $output] = invokeIsolated($script, $autoload, $database, []);
if ($status === 0 || !str_contains($output, 'REFUSED:')) {
    throw new RuntimeException('RC3 accepted incomplete RC2 history.');
}
if ((int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'philsms_leaders'")->fetchColumn() !== 0) {
    throw new RuntimeException('RC3 DDL executed despite missing RC2 prerequisite.');
}
$rc2Sql = file_get_contents(__DIR__ . '/../migrations/002_message_templates.sql');
if ($rc2Sql === false) throw new RuntimeException('RC2 checksum fixture missing.');
$restore = $db->prepare('INSERT INTO philsms_schema_migrations (version, checksum) VALUES (?, ?)');
$restore->execute(['002_message_templates', hash('sha256', $rc2Sql)]);
foreach ([1, 2] as $attempt) {
    [$status, $output] = invokeIsolated($script, $autoload, $database, []);
    if ($status !== 0 || !str_contains($output, 'PASS: isolated RC3 migrations')) {
        throw new RuntimeException('Isolated CLI attempt ' . $attempt . ' failed: ' . $output);
    }
}
if ((int) $db->query('SELECT COUNT(*) FROM philsms_schema_migrations WHERE version IN (\'004_leader_registry\', \'005_leader_assignments\', \'006_assignment_activity\')')->fetchColumn() !== 3) {
    throw new RuntimeException('Expected three recorded RC3 migrations.');
}
echo "PASS: real-Perpl isolated migration CLI, refusals, idempotency and history\n";
