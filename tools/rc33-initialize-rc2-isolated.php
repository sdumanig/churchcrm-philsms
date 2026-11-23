<?php
declare(strict_types=1);

/**
 * Explicit RC2 schema initializer for an independently verified staging DB.
 * Never invokes ChurchCRM application bootstrap or sends SMS.
 * Usage: php tools/rc33-initialize-rc2-isolated.php --apply /absolute/vendor/autoload.php expected_staging_db
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
function rc2Refuse(string $reason): never
{
    fwrite(STDERR, 'REFUSED: ' . $reason . "\n");
    exit(2);
}
if ($argc !== 4 || $argv[1] !== '--apply'
    || getenv('PHILSMS_RC2_INITIALIZE') !== 'YES'
    || getenv('PHILSMS_RC3_STAGING') !== 'YES'
    || getenv('PHILSMS_LIVE_SEND') !== '0') {
    rc2Refuse('Explicit RC2 initialization, staging authorization and disabled live sending required.');
}
$autoload = $argv[2];
$expected = $argv[3];
$database = getenv('PHILSMS_RC3_DB_NAME') ?: '';
$host = getenv('PHILSMS_RC3_DB_HOST') ?: '';
$port = getenv('PHILSMS_RC3_DB_PORT') ?: '';
$user = getenv('PHILSMS_RC3_DB_USER') ?: '';
$password = getenv('PHILSMS_RC3_DB_PASSWORD') ?: '';
$root = realpath(__DIR__ . '/..');
$real = realpath($autoload);
if (!preg_match('/\A[a-zA-Z0-9_]+\z/D', $expected)
    || !str_contains(strtolower($expected), 'staging')
    || str_contains(strtolower($expected), 'prod')
    || $database !== $expected
    || !preg_match('/\A[0-9]{1,5}\z/D', $port)
    || $root === false || $real === false || $real !== $autoload
    || str_starts_with($real, $root . DIRECTORY_SEPARATOR)
    || is_link($autoload)) {
    rc2Refuse('Unverified staging target or external vendor autoloader.');
}
require_once __DIR__ . '/../src/CliPropelAdapter.php';
try {
    \ChurchCRM\Plugins\PhilSMS\CliPropelAdapter::initialize(
        $autoload, $host, (int) $port, $database, $user, $password, $expected
    );
    $db = \Propel\Runtime\Propel::getWriteConnection('default');

    // Hold the advisory lock throughout preflight and DDL so concurrent
    // initializer processes cannot both inspect an absent table.
    $lock = $db->prepare('SELECT GET_LOCK(?, 10)');
    $lock->execute(['philsms_rc2_initialize']);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('RC2 initialization lock unavailable.');
    }
    try {
    // Preflight existing objects before any DDL. A missing table is permitted;
    // an existing table must contain the reviewed columns and unique keys.
    $definitions = [
        'philsms_notification_queue' => [
            'columns' => ['id', 'event_type', 'event_entity_id', 'person_id', 'family_id',
                'recipient_name', 'recipient_phone', 'message', 'status', 'attempts',
                'last_error', 'created_at', 'updated_at', 'sent_at'],
            'unique' => ['uq_philsms_event' => ['event_type', 'event_entity_id']],
        ],
        'philsms_message_templates' => [
            'columns' => ['id', 'template_key', 'template_name', 'message_body',
                'is_enabled', 'created_at', 'updated_at'],
            'unique' => ['uq_philsms_template_key' => ['template_key']],
        ],
    ];
    foreach ($definitions as $table => $definition) {
        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? AND table_type = ?');
        $q->execute([$table, 'BASE TABLE']);
        if ((int) $q->fetchColumn() === 0) {
            // Reject a conflicting view rather than executing CREATE TABLE IF NOT EXISTS.
            $view = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $view->execute([$table]);
            if ((int) $view->fetchColumn() !== 0) {
                throw new RuntimeException('Conflicting schema object.');
            }
            continue;
        }
        $q = $db->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
        $q->execute([$table]);
        $columns = array_map('strtolower', $q->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($definition['columns'] as $column) {
            if (!in_array($column, $columns, true)) {
                throw new RuntimeException('Existing RC2 schema is incompatible.');
            }
        }
        $q = $db->prepare('SELECT index_name, column_name, seq_in_index FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0 ORDER BY index_name, seq_in_index');
        $q->execute([$table]);
        $indices = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            // MySQL/PDO/Perpl may preserve INFORMATION_SCHEMA field names
            // in uppercase; normalize associative keys before inspection.
            $row = array_change_key_case($row, CASE_LOWER);
            if (!isset($row['index_name'], $row['column_name'])) {
                throw new RuntimeException('Unexpected RC2 index metadata.');
            }
            $indices[$row['index_name']][] = $row['column_name'];
        }
        foreach ($definition['unique'] as $name => $cols) {
            if (($indices[$name] ?? null) !== $cols) {
                throw new RuntimeException('Existing RC2 unique index is incompatible.');
            }
        }
    }

        foreach (['001_notification_queue.sql', '002_message_templates.sql',
                  '003_default_message_templates.sql'] as $filename) {
            $sql = file_get_contents(__DIR__ . '/../migrations/' . $filename);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException('Reviewed RC2 migration missing.');
            }
            $db->exec($sql);
        }
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute(['philsms_rc2_initialize']);
    }
} catch (\Throwable $e) {
    rc2Refuse('RC2 initialization aborted; review protected operator logs and schema.');
}
echo "PASS: RC2 schema initialized/verified on explicitly named staging database.\n";
