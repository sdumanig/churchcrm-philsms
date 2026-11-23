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
