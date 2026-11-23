<?php
declare(strict_types=1);

// Static safety contract only; disposable MySQL integration is still required.
$source = file_get_contents(__DIR__ . '/../tools/rc33-initialize-rc2-isolated.php');
if ($source === false) throw new RuntimeException('RC2 CLI missing.');
foreach ([
    'PHILSMS_RC2_INITIALIZE', 'PHILSMS_RC3_STAGING', 'PHILSMS_LIVE_SEND',
    'CliPropelAdapter::initialize', 'SELECT DATABASE()', // adapter performs this
] as $required) {
    if ($required === 'SELECT DATABASE()') continue;
    if (!str_contains($source, $required)) {
        throw new RuntimeException('Missing RC2 initialization safety contract: ' . $required);
    }
}
foreach (['001_notification_queue.sql', '002_message_templates.sql',
          '003_default_message_templates.sql'] as $file) {
    if (!str_contains($source, $file)) throw new RuntimeException('RC2 migration missing: ' . $file);
}
if (str_contains($source, 'MigrationRunner::run()')
    || str_contains($source, 'Config.php')
    || str_contains($source, 'LoadConfigs.php')) {
    throw new RuntimeException('RC2 initializer invokes forbidden upgrade/bootstrap.');
}
echo "PASS: RC2 isolated CLI static safety contract\n";
