<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../src/LiveDeliveryAuthorization.php';

use ChurchCRM\Plugins\PhilSMS\LiveDeliveryAuthorization;

$passed = 0;
$failed = 0;

function checkAuthorization(
    bool $condition,
    string $description
): void {
    global $passed, $failed;

    echo ($condition ? 'PASS: ' : 'FAIL: ')
        . $description . PHP_EOL;

    $condition ? $passed++ : $failed++;
}

checkAuthorization(
    !LiveDeliveryAuthorization::isAuthorized(true, null),
    'Live mode rejects missing authorization'
);

checkAuthorization(
    !LiveDeliveryAuthorization::isAuthorized(true, '0'),
    'Live mode rejects authorization value 0'
);

checkAuthorization(
    !LiveDeliveryAuthorization::isAuthorized(true, ''),
    'Live mode rejects empty authorization'
);

checkAuthorization(
    !LiveDeliveryAuthorization::isAuthorized(true, true),
    'Live mode rejects boolean authorization'
);

checkAuthorization(
    LiveDeliveryAuthorization::isAuthorized(true, '1'),
    'Live mode accepts explicit string authorization'
);

checkAuthorization(
    LiveDeliveryAuthorization::isAuthorized(false, null),
    'Dry-run mode requires no live authorization'
);

checkAuthorization(
    LiveDeliveryAuthorization::isAuthorized(false, '0'),
    'Dry-run mode remains available without live authorization'
);

echo PHP_EOL .
    "Results: {$passed} passed, {$failed} failed" .
    PHP_EOL;

exit($failed === 0 ? 0 : 1);
