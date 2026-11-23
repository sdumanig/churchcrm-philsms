<?php
declare(strict_types=1);

/** Disposable MySQL + real Perpl RC2 initializer integration; never real staging. */
$dsn = getenv('PHILSMS_TEST_DSN') ?: '';
if (getenv('PHILSMS_TEST_ALLOW_DDL') !== 'YES'
    || getenv('PHILSMS_LIVE_SEND') !== '0'
    || !preg_match('/(?:^|;)dbname=(philsms_test_staging_[a-zA-Z0-9_]+)(?:;|$)/', $dsn, $m)
    || !str_starts_with($dsn, 'mysql:host=127.0.0.1;')) {
    throw new RuntimeException('Disposable local staging database required.');
}
$database = $m[1];
$autoload = realpath(getenv('PHILSMS_TEST_PERPL_AUTOLOAD') ?: '');
$script = realpath(__DIR__ . '/../tools/rc33-initialize-rc2-isolated.php');
if ($autoload === false || $script === false) throw new RuntimeException('Fixture or CLI missing.');
$db = new PDO($dsn, getenv('PHILSMS_TEST_USER'), getenv('PHILSMS_TEST_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$env = [
    'PHILSMS_RC2_INITIALIZE' => 'YES',
    'PHILSMS_RC3_STAGING' => 'YES',
    'PHILSMS_LIVE_SEND' => '0',
    'PHILSMS_RC3_DB_HOST' => '127.0.0.1',
    'PHILSMS_RC3_DB_PORT' => '3306',
    'PHILSMS_RC3_DB_NAME' => $database,
    'PHILSMS_RC3_DB_USER' => getenv('PHILSMS_TEST_USER'),
    'PHILSMS_RC3_DB_PASSWORD' => getenv('PHILSMS_TEST_PASSWORD'),
];
function runRc2(string $script, string $autoload, string $database, array $env, array $override = []): array
{
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
        . ' --apply ' . escapeshellarg($autoload) . ' ' . escapeshellarg($database);
    $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes, null, array_merge($_ENV, $env, $override));
    if (!is_resource($proc)) throw new RuntimeException('Could not start subprocess.');
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out];
}
foreach ([
    ['PHILSMS_RC2_INITIALIZE' => 'NO'],
    ['PHILSMS_RC3_STAGING' => 'NO'],
    ['PHILSMS_LIVE_SEND' => '1'],
    ['PHILSMS_RC3_DB_NAME' => 'wrong_staging_database'],
] as $override) {
    [$status, $out] = runRc2($script, $autoload, $database, $env, $override);
    if ($status === 0 || !str_contains($out, 'REFUSED:')) {
        throw new RuntimeException('Unsafe RC2 initialization was accepted.');
    }
}
// A separate connection holds the named lock: the CLI must refuse before DDL.
$held = (int) $db->query("SELECT GET_LOCK('philsms_rc2_initialize', 0)")->fetchColumn();
if ($held !== 1) throw new RuntimeException('Could not hold RC2 concurrency test lock.');
try {
    [$status, $out] = runRc2($script, $autoload, $database, $env);
    if ($status === 0 || !str_contains($out, 'REFUSED:')) {
        throw new RuntimeException('Concurrent RC2 initializer did not refuse.');
    }
    $existing = (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'philsms_message_templates'")->fetchColumn();
    if ($existing !== 0) throw new RuntimeException('Concurrent initializer performed DDL before acquiring lock.');
} finally {
    $db->query("SELECT RELEASE_LOCK('philsms_rc2_initialize')");
}
[$status, $out] = runRc2($script, $autoload, $database, $env);
if ($status !== 0) throw new RuntimeException('Fresh RC2 initialization failed: ' . $out);
if ((int) $db->query('SELECT COUNT(*) FROM philsms_message_templates')->fetchColumn() !== 5) {
    throw new RuntimeException('Expected five default templates.');
}
$db->exec("UPDATE philsms_message_templates SET message_body = 'Custom preserved' WHERE template_key = 'guest_general_welcome'");
$db->exec("INSERT INTO philsms_notification_queue (event_type, event_entity_id, status) VALUES ('individual_approved', 987654, 'pending')");
[$status, $out] = runRc2($script, $autoload, $database, $env);
if ($status !== 0) throw new RuntimeException('Idempotent RC2 initialization failed: ' . $out);
if ($db->query("SELECT message_body FROM philsms_message_templates WHERE template_key = 'guest_general_welcome'")->fetchColumn() !== 'Custom preserved'
    || (int) $db->query('SELECT COUNT(*) FROM philsms_notification_queue WHERE event_entity_id = 987654')->fetchColumn() !== 1) {
    throw new RuntimeException('RC2 reinitialization changed existing records.');
}
$history = $db->query("SELECT version, checksum FROM philsms_schema_migrations WHERE version LIKE '00%' ORDER BY version")->fetchAll(PDO::FETCH_ASSOC);
if (count($history) !== 3) throw new RuntimeException('Expected three RC2 history entries.');
foreach ($history as $row) {
    $file = __DIR__ . '/../migrations/' . $row['version'] . '.sql';
    if (!is_file($file) || !hash_equals(hash('sha256', (string) file_get_contents($file)), $row['checksum'])) {
        throw new RuntimeException('RC2 migration history checksum invalid.');
    }
}
$db->exec("UPDATE philsms_schema_migrations SET checksum = REPEAT('0', 64) WHERE version = '002_message_templates'");
[$status, $out] = runRc2($script, $autoload, $database, $env);
if ($status === 0 || !str_contains($out, 'REFUSED:')) {
    throw new RuntimeException('Altered RC2 history was not rejected.');
}
if ($db->query("SELECT message_body FROM philsms_message_templates WHERE template_key = 'guest_general_welcome'")->fetchColumn() !== 'Custom preserved') {
    throw new RuntimeException('Checksum refusal changed a customized template.');
}
// Restore the disposable fixture so subsequent RC3 tests see valid RC2 history.
$restore = $db->prepare('UPDATE philsms_schema_migrations SET checksum = ? WHERE version = ?');
$restore->execute([hash('sha256', (string) file_get_contents(__DIR__ . '/../migrations/002_message_templates.sql')), '002_message_templates']);
// Simulate a legacy RC2 installation whose compatible tables and edited
// templates predate checksum history. Adoption must not replace user content.
$db->exec("DELETE FROM philsms_schema_migrations WHERE version IN ('001_notification_queue', '002_message_templates', '003_default_message_templates')");
if ((int) $db->query("SELECT COUNT(*) FROM philsms_schema_migrations WHERE version LIKE '00%'")->fetchColumn() !== 0) {
    throw new RuntimeException('Could not prepare legacy RC2 fixture.');
}
[$status, $out] = runRc2($script, $autoload, $database, $env);
if ($status !== 0) throw new RuntimeException('Legacy RC2 adoption failed: ' . $out);
if ((int) $db->query("SELECT COUNT(*) FROM philsms_schema_migrations WHERE version IN ('001_notification_queue', '002_message_templates', '003_default_message_templates')")->fetchColumn() !== 3) {
    throw new RuntimeException('Legacy RC2 adoption did not record all three migrations.');
}
if ($db->query("SELECT message_body FROM philsms_message_templates WHERE template_key = 'guest_general_welcome'")->fetchColumn() !== 'Custom preserved'
    || (int) $db->query("SELECT COUNT(*) FROM philsms_notification_queue WHERE event_entity_id = 987654")->fetchColumn() !== 1) {
    throw new RuntimeException('Legacy RC2 adoption changed existing data.');
}
foreach ($db->query("SELECT version, checksum FROM philsms_schema_migrations WHERE version LIKE '00%'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $sqlFile = __DIR__ . '/../migrations/' . $row['version'] . '.sql';
    if (!hash_equals(hash('sha256', (string) file_get_contents($sqlFile)), (string) $row['checksum'])) {
        throw new RuntimeException('Legacy RC2 adoption recorded incorrect checksum.');
    }
}
echo "PASS: isolated RC2 initialization, refusals, repeatability and preservation\n";
