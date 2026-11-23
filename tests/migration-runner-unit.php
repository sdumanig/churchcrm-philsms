<?php

declare(strict_types=1);

/**
 * Offline RC3 migration-runner safety contract tests.
 * Run: php tests/migration-runner-unit.php
 *
 * These checks do not connect to a database, run DDL, or send SMS.
 * Database-level concurrency and crash-recovery tests remain required.
 */

$source = file_get_contents(__DIR__ . '/../src/MigrationRunner.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: Cannot read MigrationRunner.php\n");
    exit(1);
}

$passed = 0;
$failed = 0;

function checkContract(string $description, bool $condition): void
{
    global $passed, $failed;
    echo ($condition ? 'PASS: ' : 'FAIL: ') . $description . "\n";
    $condition ? $passed++ : $failed++;
}

checkContract('Runner declares a versioned migration history table',
    str_contains($source, "philsms_schema_migrations"));

checkContract('Runner acquires an advisory migration lock',
    str_contains($source, 'GET_LOCK(?, ?)'));

checkContract('Runner rejects failure to acquire the lock',
    str_contains($source, 'Unable to acquire PhilSMS migration lock.'));

checkContract('Runner releases the lock in a finally block',
    (bool) preg_match('/finally\s*\{[\s\S]*?RELEASE_LOCK\(\?\)/', $source));

checkContract('Runner stores a SHA-256 checksum',
    str_contains($source, "hash('sha256', \$sql)") &&
    str_contains($source, 'checksum CHAR(64) NOT NULL'));

checkContract('Runner detects previously applied migration drift',
    str_contains($source, 'hash_equals(') &&
    str_contains($source, 'Previously applied PhilSMS migration has changed'));

checkContract('Runner reads migration history before applying DDL',
    strpos($source, 'SELECT checksum FROM') !== false &&
    strpos($source, 'SELECT checksum FROM') < strpos($source, '$connection->exec($sql)'));

checkContract('Runner records completion only after executing DDL',
    strpos($source, '$connection->exec($sql)') <
    strpos($source, '(version, checksum) VALUES (?, ?)'));

checkContract('Runner restricts migration filenames',
    str_contains($source, 'Invalid PhilSMS migration registration.'));

checkContract('Runner has no migrations registered yet',
    str_contains($source, 'private const MIGRATIONS = [];'));

checkContract('Runner contains no SMS provider invocation',
    !preg_match('/PhilSMSClient|->send\s*\(/', $source));

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
