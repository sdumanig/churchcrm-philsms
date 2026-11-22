<?php
use ChurchCRM\Plugins\PhilSMS\PhilSMSPlugin;
use ChurchCRM\Plugins\PhilSMS\PhilSMSClient;
use ChurchCRM\model\ChurchCRM\PersonQuery;
use ChurchCRM\Slim\Middleware\Request\Auth\AdminRoleAuthMiddleware;
use ChurchCRM\dto\SystemURLs;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;
$plugin=PhilSMSPlugin::getInstance(); if (!$plugin) return;
$app->get('/philsms/manage', function(
    Request $request,
    Response $response
): Response {
    $people = [];

    foreach (PersonQuery::create()->orderByLastName()->orderByFirstName()->find() as $person) {
        $phone = PhilSMSClient::normalize(
            (string) $person->getCellPhone()
        );

        if ($phone === null) {
            continue;
        }

        $people[] = [
            'id' => (int) $person->getId(),
            'name' => trim(
                (string) $person->getFirstName() . ' ' .
                (string) $person->getLastName()
            ),
            'phone' => $phone,
        ];
    }

     // Retrieve the current PhilSMS balance
    $credits = null;
    $creditsError = null;

    try {
        $client = new PhilSMSClient(
            (string) (getenv('PHILSMS_API_TOKEN') ?: '')
        );

        $balance = $client->balance();

        if (($balance['status'] ?? '') !== 'success') {
            throw new \RuntimeException(
                (string) ($balance['message'] ?? 'Balance lookup failed')
            );
        }

        $credits = $balance['data']['remaining_balance'] ?? null;

        if (!is_string($credits) || $credits === '') {
            throw new \RuntimeException('Balance unavailable');
        }

    } catch (\Throwable $e) {
        error_log('PhilSMS balance lookup failed: ' . $e->getMessage());
        $creditsError = 'Balance temporarily unavailable';
    }

    $renderer = new PhpRenderer(__DIR__ . '/../views/');

    return $renderer->render($response, 'manage.php', [
        'sRootPath' => SystemURLs::getRootPath(),
        'sPageTitle' => 'PhilSMS Messaging',
        'configured' => (bool) getenv('PHILSMS_API_TOKEN'),
        'people' => $people,
        'credits' => $credits,
        'creditsError' => $creditsError,
    ]);

})->add(AdminRoleAuthMiddleware::class);

$app->post('/philsms/send', function (Request $request, Response $response): Response {
    $data = (array) $request->getParsedBody();

    $json = function (int $status, array $payload) use ($response): Response {
        $response->getBody()->write(json_encode(
            $payload,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        ));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store');
    };

    if (session_status() !== PHP_SESSION_ACTIVE ||
        !isset($_SESSION['philsms_csrf']) ||
        !hash_equals(
            $_SESSION['philsms_csrf'],
            (string) ($data['csrf'] ?? '')
        )) {
        return $json(403, [
            'success' => false,
            'message' => 'Your session has expired or the security token is invalid.'
        ]);
    }

    $message = trim((string) ($data['message'] ?? ''));
    $ids = array_values(array_unique(array_filter(
        array_map(
            'intval',
            preg_split('/[\s,]+/', (string) ($data['person_ids'] ?? ''))
        ),
        static fn (int $id): bool => $id > 0
    )));

    if (!$ids || count($ids) > 25 || $message === '' ||
        mb_strlen($message) > 1000) {
        return $json(422, [
            'success' => false,
            'message' => 'Select 1–25 recipients and enter a message of up to 1000 characters.'
        ]);
    }

    $dry = getenv('PHILSMS_LIVE_SEND') !== '1';
    $results = [];
    $processed = 0;
    $failed = 0;

    try {
        $client = $dry
            ? null
            : new PhilSMSClient((string) (getenv('PHILSMS_API_TOKEN') ?: ''));

        foreach ($ids as $id) {
            $person = PersonQuery::create()->findPk($id);

            if (!$person) {
                $failed++;
                $results[] = "Member #{$id}: not found";
                continue;
            }

            $phone = PhilSMSClient::normalize((string) $person->getCellPhone());

            if (!$phone) {
                $failed++;
                $results[] = "Member #{$id}: no valid mobile number";
                continue;
            }

            if ($dry) {
                $processed++;
                $results[] = "Member #{$id}: validated (dry run)";
                continue;
            }

            try {
                $client->send($phone, $message);
                $processed++;
                $results[] = "Member #{$id}: submitted to PhilSMS";
            } catch (\Throwable $e) {
                $failed++;
                error_log("PhilSMS send failed for Person ID {$id}: " . get_class($e));
                $results[] = "Member #{$id}: sending failed";
            }
        }
    } catch (\Throwable $e) {
        error_log('PhilSMS processing error: ' . get_class($e));

        return $json(500, [
            'success' => false,
            'message' => 'Unable to process the SMS request.'
        ]);
    }

    return $json(200, [
        'success' => $failed === 0 && $processed > 0,
        'dry_run' => $dry,
        'processed' => $processed,
        'failed' => $failed,
        'message' => $dry
            ? 'SMS dry run completed. No messages were sent.'
            : 'SMS submission completed. Check the individual results.',
        'results' => $results
    ]);
})->add(AdminRoleAuthMiddleware::class);
$app->get('/philsms/balance', function(Request $request,Response $response):Response {
 try { $result=(new PhilSMSClient((string)(getenv('PHILSMS_API_TOKEN')?:'')))->balance(); $response->getBody()->write('<pre>'.htmlspecialchars(json_encode($result,JSON_PRETTY_PRINT),ENT_QUOTES).'</pre>'); }
 catch (\Throwable $e) { $response->getBody()->write('Balance lookup failed: '.htmlspecialchars($e->getMessage(),ENT_QUOTES)); }
 return $response->withHeader('Content-Type','text/html; charset=UTF-8')->withHeader('Cache-Control','no-store');
})->add(AdminRoleAuthMiddleware::class);
