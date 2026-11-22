<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';
require __DIR__ . '/../src/PhilSMSPlugin.php';
require __DIR__ . '/../src/NotificationConfirmation.php';

use ChurchCRM\Plugins\PhilSMS\PhilSMSPlugin;
use ChurchCRM\Plugins\PhilSMS\NotificationConfirmation;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

putenv('PHILSMS_LIVE_SEND=0');

if (getenv('PHILSMS_LIVE_SEND') !== '0') {
    throw new RuntimeException('Live sending safeguard is not disabled.');
}

session_start();

$_SESSION = [
    'philsms_csrf' => bin2hex(random_bytes(24)),
];

new PhilSMSPlugin(dirname(__DIR__));

$app = AppFactory::create();

require __DIR__ . '/../routes/routes.php';

$handler = null;

foreach ($app->getRouteCollector()->getRoutes() as $route) {
    if ($route->getPattern() === '/philsms/notifications/deliver') {
        $handler = $route->getCallable();
        break;
    }
}

if (!is_callable($handler)) {
    throw new RuntimeException('Delivery route handler not found.');
}

$passed = 0;
$failed = 0;

function checkRoute(
    bool $condition,
    string $description
): void {
    global $passed, $failed;

    echo ($condition ? 'PASS: ' : 'FAIL: ') .
        $description . PHP_EOL;

    $condition ? $passed++ : $failed++;
}

function invokeDelivery(
    callable $handler,
    array $data
): array {
    $request = (new ServerRequestFactory())
        ->createServerRequest(
            'POST',
            '/philsms/notifications/deliver'
        )
        ->withParsedBody($data);

    $response = $handler(
        $request,
        (new ResponseFactory())->createResponse()
    );

    return [
        $response->getStatusCode(),
        json_decode(
            (string) $response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        ),
    ];
}

$csrf = $_SESSION['philsms_csrf'];
$fingerprint = hash('sha256', 'isolated-route-test');
$queueId = 900001;

try {
    [$status] = invokeDelivery($handler, [
        'queue_id' => $queueId,
    ]);

    checkRoute($status === 403, 'Missing CSRF rejected');

    [$status] = invokeDelivery($handler, [
        'csrf' => $csrf,
        'queue_id' => 0,
    ]);

    checkRoute($status === 422, 'Invalid queue ID rejected');

    [$status] = invokeDelivery($handler, [
        'csrf' => $csrf,
        'queue_id' => $queueId,
    ]);

    checkRoute($status === 403, 'Missing confirmation rejected');

    $token = NotificationConfirmation::issue(
        $queueId,
        $fingerprint
    );

    [$status] = invokeDelivery($handler, [
        'csrf' => $csrf,
        'queue_id' => $queueId,
        'confirmation_token' => str_repeat('0', 64),
    ]);

    checkRoute($status === 403, 'Incorrect confirmation rejected');

    [$status] = invokeDelivery($handler, [
        'csrf' => $csrf,
        'queue_id' => $queueId,
        'confirmation_token' => $token,
    ]);

    checkRoute(
        $status === 403,
        'Incorrect attempt consumed confirmation'
    );

    $token = NotificationConfirmation::issue(
        $queueId,
        $fingerprint
    );

    $_SESSION['philsms_notification_confirmation']['expires_at'] =
        time() - 1;

    [$status] = invokeDelivery($handler, [
        'csrf' => $csrf,
        'queue_id' => $queueId,
        'confirmation_token' => $token,
    ]);

    checkRoute($status === 403, 'Expired confirmation rejected');

    $token = NotificationConfirmation::issue(
        $queueId,
        $fingerprint
    );

    [$status, $payload] = invokeDelivery($handler, [
        'csrf' => $csrf,
        'queue_id' => $queueId,
        'confirmation_token' => $token,
    ]);

    checkRoute(
        $status === 200 &&
        ($payload['success'] ?? false) === true &&
        ($payload['dry_run'] ?? false) === true,
        'Valid confirmation accepted in disabled-live mode'
    );

    [$status] = invokeDelivery($handler, [
        'csrf' => $csrf,
        'queue_id' => $queueId,
        'confirmation_token' => $token,
    ]);

    checkRoute($status === 403, 'Confirmation replay rejected');

} catch (Throwable $e) {
    echo 'ERROR: ' . get_class($e) .
        ': ' . $e->getMessage() . PHP_EOL;
    $failed++;
} finally {
    NotificationConfirmation::invalidate();
    $_SESSION = [];
    session_write_close();
}

echo PHP_EOL .
    "Results: {$passed} passed, {$failed} failed" .
    PHP_EOL;

exit($failed === 0 ? 0 : 1);
