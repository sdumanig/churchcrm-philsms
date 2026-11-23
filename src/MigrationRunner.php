<?php

namespace ChurchCRM\Plugins\PhilSMS;

use Propel\Runtime\Propel;

/**
 * Runs reviewed, single-statement, idempotent PhilSMS schema migrations.
 *
 * MySQL DDL implicitly commits. A migration must be safe to execute again
 * if its DDL succeeded but recording its version was interrupted.
 */
final class MigrationRunner
{
    private const HISTORY_TABLE = 'philsms_schema_migrations';
    private const LOCK_NAME = 'philsms_schema_migrations';
    private const LOCK_TIMEOUT_SECONDS = 10;

    /**
     * Explicit allowlist; existing RC2 bootstrap SQL is not replayed here.
     *
     * @var array<string, string>
     */
    private const MIGRATIONS = [
        '004_leader_registry' => '004_leader_registry.sql',
        '005_leader_assignments' => '005_leader_assignments.sql',
        '006_assignment_activity' => '006_assignment_activity.sql',
    ];

    public static function run(): void
    {
        $connection = Propel::getWriteConnection('default');
        $lock = $connection->prepare('SELECT GET_LOCK(?, ?)');
        $lock->execute([self::LOCK_NAME, self::LOCK_TIMEOUT_SECONDS]);

        if ((int) $lock->fetchColumn() !== 1) {
            throw new \RuntimeException('Unable to acquire PhilSMS migration lock.');
        }

        try {
            // Prerequisites are checked while holding the same advisory lock
            // as RC2 initialization, before any RC3 DDL is attempted.
            foreach (['philsms_notification_queue', 'philsms_message_templates',
                      self::HISTORY_TABLE] as $requiredTable) {
                $check = $connection->prepare(
                    "SELECT COUNT(*) FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = ?
                       AND table_type = 'BASE TABLE' AND engine = 'InnoDB'"
                );
                $check->execute([$requiredTable]);
                if ((int) $check->fetchColumn() !== 1) {
                    throw new \RuntimeException('RC2 prerequisite table missing or incompatible.');
                }
            }
            // Verify operational RC2 column signatures, defaults, and indexes
            // under the lock. History checksums alone cannot prove schema integrity.
            $expected = [
                'philsms_notification_queue' => [
                    'columns' => [
                        'id' => ['bigint unsigned', 'NO'],
                        'event_type' => ['varchar(50)', 'NO'],
                        'event_entity_id' => ['int unsigned', 'NO'],
                        'person_id' => ['int unsigned', 'YES'],
                        'family_id' => ['int unsigned', 'YES'],
                        'recipient_phone' => ['varchar(30)', 'YES'],
                        'status' => ['varchar(20)', 'NO'],
                        'attempts' => ['int unsigned', 'NO'],
                        'created_at' => ['datetime', 'NO'],
                        'updated_at' => ['datetime', 'NO'],
                        'sent_at' => ['datetime', 'YES'],
                    ],
                    'defaults' => [
                        'status' => 'pending', 'attempts' => '0',
                        'created_at' => 'CURRENT_TIMESTAMP',
                        'updated_at' => 'CURRENT_TIMESTAMP',
                    ],
                    'indexes' => [
                        'PRIMARY' => ['id'],
                        'uq_philsms_event' => ['event_type', 'event_entity_id'],
                        'idx_philsms_status_created' => ['status', 'created_at'],
                        'idx_philsms_person' => ['person_id'],
                        'idx_philsms_family' => ['family_id'],
                    ],
                    'unique' => ['PRIMARY', 'uq_philsms_event'],
                ],
                'philsms_message_templates' => [
                    'columns' => [
                        'id' => ['int unsigned', 'NO'],
                        'template_key' => ['varchar(60)', 'NO'],
                        'template_name' => ['varchar(120)', 'NO'],
                        'message_body' => ['text', 'NO'],
                        'is_enabled' => ['tinyint(1)', 'NO'],
                        'created_at' => ['datetime', 'NO'],
                        'updated_at' => ['datetime', 'NO'],
                    ],
                    'defaults' => [
                        'is_enabled' => '1',
                        'created_at' => 'CURRENT_TIMESTAMP',
                        'updated_at' => 'CURRENT_TIMESTAMP',
                    ],
                    'indexes' => [
                        'PRIMARY' => ['id'],
                        'uq_philsms_template_key' => ['template_key'],
                    ],
                    'unique' => ['PRIMARY', 'uq_philsms_template_key'],
                ],
            ];
            foreach ($expected as $table => $definition) {
                $query = $connection->prepare(
                    'SELECT column_name, column_type, is_nullable, column_default, extra
                     FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = ?'
                );
                $query->execute([$table]);
                $columns = [];
                foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $row = array_change_key_case($row, CASE_LOWER);
                    $columns[strtolower((string) $row['column_name'])] = $row;
                }
                foreach ($definition['columns'] as $name => $signature) {
                    $field = $columns[$name] ?? null;
                    if ($field === null || [
                        strtolower((string) $field['column_type']),
                        strtoupper((string) $field['is_nullable']),
                    ] !== $signature) {
                        throw new \RuntimeException('RC2 prerequisite column incompatible.');
                    }
                }
                foreach ($definition['defaults'] as $name => $default) {
                    $actual = strtoupper((string) ($columns[$name]['column_default'] ?? ''));
                    $actual = preg_replace('/\(\)$/', '', $actual);
                    if ($actual !== strtoupper($default)) {
                        throw new \RuntimeException('RC2 prerequisite default incompatible.');
                    }
                }
                if (!str_contains(
                    strtolower((string) ($columns['updated_at']['extra'] ?? '')),
                    'on update current_timestamp'
                )) {
                    throw new \RuntimeException('RC2 prerequisite timestamp incompatible.');
                }
                $query = $connection->prepare(
                    'SELECT index_name, column_name, non_unique
                     FROM information_schema.statistics
                     WHERE table_schema = DATABASE() AND table_name = ?
                     ORDER BY index_name, seq_in_index'
                );
                $query->execute([$table]);
                $indexes = [];
                $nonUnique = [];
                foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $row = array_change_key_case($row, CASE_LOWER);
                    $name = (string) $row['index_name'];
                    $indexes[$name][] = (string) $row['column_name'];
                    $nonUnique[$name] = (int) $row['non_unique'];
                }
                foreach ($definition['indexes'] as $name => $indexColumns) {
                    if (($indexes[$name] ?? null) !== $indexColumns
                        || (in_array($name, $definition['unique'], true)
                            && ($nonUnique[$name] ?? 1) !== 0)) {
                        throw new \RuntimeException('RC2 prerequisite index incompatible.');
                    }
                }
            }

            foreach ([
                '001_notification_queue' => '001_notification_queue.sql',
                '002_message_templates' => '002_message_templates.sql',
                '003_default_message_templates' => '003_default_message_templates.sql',
            ] as $version => $filename) {
                $sql = file_get_contents(__DIR__ . '/../migrations/' . $filename);
                if ($sql === false || trim($sql) === '') {
                    throw new \RuntimeException('RC2 prerequisite migration file missing.');
                }
                $check = $connection->prepare(
                    'SELECT checksum FROM ' . self::HISTORY_TABLE . ' WHERE version = ?'
                );
                $check->execute([$version]);
                $row = $check->fetch(\PDO::FETCH_ASSOC);
                $row = is_array($row) ? array_change_key_case($row, CASE_LOWER) : [];
                if (!isset($row['checksum'])
                    || !hash_equals(hash('sha256', $sql), (string) $row['checksum'])) {
                    throw new \RuntimeException('RC2 prerequisite migration history invalid.');
                }
            }

            $connection->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::HISTORY_TABLE . ' (
                    version VARCHAR(100) NOT NULL,
                    checksum CHAR(64) NOT NULL,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (version)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            foreach (self::MIGRATIONS as $version => $filename) {
                if (!preg_match('/\A[a-zA-Z0-9_-]+\z/D', $version)
                    || !preg_match('/\A[0-9]{3}_[a-zA-Z0-9_-]+\.sql\z/D', $filename)) {
                    throw new \RuntimeException('Invalid PhilSMS migration registration.');
                }

                $path = __DIR__ . '/../migrations/' . $filename;
                $sql = file_get_contents($path);

                if ($sql === false || trim($sql) === '') {
                    throw new \RuntimeException(
                        'PhilSMS migration file is missing or empty: ' . $version
                    );
                }

                $checksum = hash('sha256', $sql);
                $statement = $connection->prepare(
                    'SELECT checksum FROM ' . self::HISTORY_TABLE .
                    ' WHERE version = ?'
                );
                $statement->execute([$version]);
                // Perpl's connection wrapper may normalize fetchColumn() on
                // an empty result differently from native PDO. Check row
                // existence explicitly before comparing the checksum.
                $existingRow = $statement->fetch(\PDO::FETCH_ASSOC);

                if (is_array($existingRow)) {
                    if (!hash_equals((string) $existingRow['checksum'], $checksum)) {
                        throw new \RuntimeException(
                            'Previously applied PhilSMS migration has changed: ' . $version
                        );
                    }
                    continue;
                }

                // The SQL must be one independently idempotent statement.
                // If DDL succeeds but the INSERT fails, a later run retries it.
                $connection->exec($sql);

                $insert = $connection->prepare(
                    'INSERT INTO ' . self::HISTORY_TABLE .
                    ' (version, checksum) VALUES (?, ?)'
                );
                $insert->execute([$version, $checksum]);
            }
        } finally {
            $release = $connection->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([self::LOCK_NAME]);
        }
    }
}
