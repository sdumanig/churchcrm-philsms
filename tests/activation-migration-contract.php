<?php
declare(strict_types=1);

// Offline lifecycle contract: activation must be schema-neutral and must not
// automatically initialize RC2 or apply RC3 migrations. This is a source-level check, not a
// real ChurchCRM lifecycle test.
$source = file_get_contents(__DIR__ . '/../src/PhilSMSPlugin.php');
if ($source === false) {
    throw new RuntimeException('Cannot read PhilSMS plugin.');
}
if (!preg_match('/function\\s+activate\\s*\\(\\s*\\)\\s*:\\s*void\\s*\\{(?<body>[^}]*)\\}/s', $source, $matches)) {
    throw new RuntimeException('Activation method not found.');
}
$body = $matches['body'];
if (preg_match('/\\b(?:NotificationQueue::initialize|MigrationRunner::run)\\s*\\(/', $body)
    || preg_match('/\\b(?:exec|query|prepare|enqueue)\\s*\\(/', $body)) {
    throw new RuntimeException('Plugin activation must not initialize schema or enqueue messages.');
}
if (!str_contains($source, 'public function deactivate(): void {}') ||
    !str_contains($source, 'public function uninstall(): void {')) {
    throw new RuntimeException('Plugin lifecycle unexpectedly changed.');
}
$runner = file_get_contents(__DIR__ . '/../src/MigrationRunner.php');
if ($runner === false) {
    throw new RuntimeException('Cannot read RC3 migration runner.');
}
foreach (['004_leader_registry', '005_leader_assignments', '006_assignment_activity'] as $version) {
    if (!str_contains($runner, "'" . $version . "' =>")) {
        throw new RuntimeException('RC3 migration missing from allowlist: ' . $version);
    }
}
$isolated = file_get_contents(__DIR__ . '/../tools/rc32-apply-isolated.php');
if ($isolated === false || !str_contains($isolated, 'MigrationRunner::run()')) {
    throw new RuntimeException('Explicit isolated RC3 migration entry point is missing.');
}
echo "PASS: RC3 activation does not automatically apply schema migrations\\n";
