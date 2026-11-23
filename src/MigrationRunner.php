<?php

namespace ChurchCRM\Plugins\PhilSMS;

use Propel\Runtime\Propel;

/**
 * Applies explicitly registered PhilSMS schema migrations.
 *
 * Migration SQL must contain a single statement. MySQL DDL is not
 * transaction-safe; successful execution is recorded separately.
 * Do not use this runner for the existing RC2 bootstrap migrations.
 */
final class MigrationRunner
{
    private const HISTORY_TABLE = 'philsms_schema_migrations';

    /**
     * New migrations are registered here when their schema is reviewed.
     *
     * @var array<string, string> Version => relative SQL filename
     */
    private const MIGRATIONS = [];

    public static function run(): void
    {
        $connection = Propel::getWriteConnection('default');

        $connection->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::HISTORY_TABLE . ' (
                version VARCHAR(100) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        foreach (self::MIGRATIONS as $version => $filename) {
            $statement = $connection->prepare(
                'SELECT version FROM ' . self::HISTORY_TABLE .
                ' WHERE version = ?'
            );
            $statement->execute([$version]);

            if ($statement->fetchColumn() !== false) {
                continue;
            }

            $path = __DIR__ . '/../migrations/' . $filename;
            $sql = file_get_contents($path);

            if ($sql === false || trim($sql) === '') {
                throw new \RuntimeException(
                    'PhilSMS migration file is missing or empty: ' . $version
                );
            }

            // MySQL DDL implicitly commits. Each migration must be
            // independently idempotent to recover from interrupted runs.
            $connection->exec($sql);

            $insert = $connection->prepare(
                'INSERT INTO ' . self::HISTORY_TABLE .
                ' (version) VALUES (?)'
            );
            $insert->execute([$version]);
        }
    }
}
