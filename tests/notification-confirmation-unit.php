<?php

declare(strict_types=1);

require __DIR__ . '/../src/NotificationConfirmation.php';

use ChurchCRM\Plugins\PhilSMS\NotificationConfirmation as Confirmation;

session_start();

$passed = 0;
$failed = 0;

function check(bool $condition, string $description): void
{
    global $passed, $failed;

    if ($condition) {
        echo "PASS: {$description}\n";
        $passed++;
    } else {
        echo "FAIL: {$description}\n";
        $failed++;
    }
}

$fingerprint = hash('sha256', 'test-notification-payload');

try {
    $token = Confirmation::issue(101, $fingerprint);

    check(
        Confirmation::consume(101, $token) === $fingerprint,
        'Valid confirmation accepted'
    );

    check(
        Confirmation::consume(101, $token) === null,
        'Consumed token cannot be reused'
    );

    $token = Confirmation::issue(102, $fingerprint);

    check(
        Confirmation::consume(103, $token) === null,
        'Incorrect queue ID rejected'
    );

    check(
        Confirmation::consume(102, $token) === null,
        'Incorrect queue ID attempt consumes token'
    );

    $token = Confirmation::issue(104, $fingerprint);

    check(
        Confirmation::consume(104, str_repeat('0', 64)) === null,
        'Incorrect token rejected'
    );

    check(
        Confirmation::consume(104, $token) === null,
        'Incorrect token attempt consumes confirmation'
    );

    $oldToken = Confirmation::issue(105, $fingerprint);
    $newToken = Confirmation::issue(106, $fingerprint);

    check(
        Confirmation::consume(105, $oldToken) === null,
        'Issuing a new token invalidates the previous token'
    );

    check(
        Confirmation::consume(106, $newToken) === null,
        'Failed old-token attempt consumes the new token'
    );

    $token = Confirmation::issue(107, $fingerprint);

    $_SESSION['philsms_notification_confirmation']['expires_at'] =
        time() - 1;

    check(
        Confirmation::consume(107, $token) === null,
        'Expired token rejected'
    );

    $token = Confirmation::issue(108, $fingerprint);

    Confirmation::invalidate();

    check(
        Confirmation::consume(108, $token) === null,
        'Explicit invalidation works'
    );

    $token = Confirmation::issue(109, $fingerprint);

    check(
        Confirmation::consume(109, $token) === $fingerprint,
        'Fresh confirmation remains usable'
    );

    check(
        !isset($_SESSION['philsms_notification_confirmation']),
        'Consumed confirmation removed from session'
    );

} catch (Throwable $e) {
    echo 'ERROR: ' . get_class($e) . ': '
        . $e->getMessage() . "\n";
    $failed++;
}

echo "\nResults: {$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
