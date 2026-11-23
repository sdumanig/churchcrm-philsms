<?php
declare(strict_types=1);

// Offline lifecycle contract: RC2 bootstrap must precede RC3 migrations.
// This is a source-level check, not an actual ChurchCRM activation test.
$source = file_get_contents(__DIR__ . '/../src/PhilSMSPlugin.php');
if ($source === false) {
    throw new RuntimeException('Cannot read PhilSMS plugin.');
}
if (!preg_match('/function\s+activate\s*\(\s*\)\s*:\s*void\s*\{(?<body>[^}]+)\}/s', $source, $matches)) {
    throw new RuntimeException('Activation method not found.');
}
$body = $matches['body'];
$bootstrap = strpos($body, 'NotificationQueue::initialize()');
$migrate = strpos($body, 'MigrationRunner::run()');
if ($bootstrap === false || $migrate === false || $bootstrap >= $migrate) {
    throw new RuntimeException('RC2 initialization must precede RC3 migrations.');
}
if (!str_contains($source, 'public function deactivate(): void {}') ||
    !str_contains($source, 'public function uninstall(): void {')) {
    throw new RuntimeException('Plugin lifecycle unexpectedly changed.');
}
$runner = file_get_contents(__DIR__ . '/../src/MigrationRunner.php');
foreach (['004_leader_registry', '005_leader_assignments', '006_assignment_activity'] as $version) {
    if (!str_contains($runner, "'" . $version . "' =>")) {
        throw new RuntimeException('RC3 migration missing from allowlist: ' . $version);
    }
}
echo "PASS: RC3 activation migration lifecycle contract\n";
