<?php
declare(strict_types=1);

/**
 * RC3 migration manifest preflight.
 *
 * Read-only, CLI-only: no ChurchCRM bootstrap, database connection, DDL,
 * network request, plugin activation, or SMS. Output contains SHA-256 values
 * suitable for comparing with the migration history on a separately approved
 * staging database.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
$expected = [
    '004_leader_registry' => '004_leader_registry.sql',
    '005_leader_assignments' => '005_leader_assignments.sql',
    '006_assignment_activity' => '006_assignment_activity.sql',
];
$runner = file_get_contents(__DIR__ . '/../src/MigrationRunner.php');
if ($runner === false) {
    fwrite(STDERR, "ERROR: MigrationRunner source unavailable.\n");
    exit(1);
}
$manifest = [];
foreach ($expected as $version => $filename) {
    if (!str_contains($runner, "'" . $version . "' => '" . $filename . "'")) {
        fwrite(STDERR, "ERROR: Migration is not registered: {$version}\n");
        exit(1);
    }
    $path = __DIR__ . '/../migrations/' . $filename;
    if (!is_file($path) || !is_readable($path)) {
        fwrite(STDERR, "ERROR: Migration file missing: {$filename}\n");
        exit(1);
    }
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        fwrite(STDERR, "ERROR: Empty migration: {$filename}\n");
        exit(1);
    }
    // MigrationRunner::run() expects exactly one independently idempotent
    // CREATE TABLE statement for each of these three reviewed migrations.
    if (!preg_match('/\ACREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+philsms_[a-z_]+\s*\(/i', trim($sql)) ||
        substr_count($sql, ';') !== 1 ||
        !str_ends_with(trim($sql), ';')) {
        fwrite(STDERR, "ERROR: Unexpected migration SQL shape: {$filename}\n");
        exit(1);
    }
    $manifest[] = [
        'version' => $version,
        'filename' => $filename,
        'sha256' => hash('sha256', $sql),
    ];
}
if (in_array('--json', $argv, true)) {
    echo json_encode(['mode' => 'read-only', 'migrations' => $manifest],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} else {
    echo "PhilSMS RC3 migration manifest (read-only; no database access)\n";
    foreach ($manifest as $entry) {
        echo $entry['version'], "  ", $entry['sha256'], "\n";
    }
}
