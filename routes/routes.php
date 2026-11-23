<?php
use ChurchCRM\Plugins\PhilSMS\PhilSMSPlugin;
use ChurchCRM\Plugins\PhilSMS\PhilSMSClient;
use ChurchCRM\Plugins\PhilSMS\NotificationQueue;
use ChurchCRM\Plugins\PhilSMS\MessageTemplateRepository;
use ChurchCRM\Plugins\PhilSMS\PendingApprovalCounter;
use ChurchCRM\Plugins\PhilSMS\NotificationDeliveryService;
use ChurchCRM\Plugins\PhilSMS\NotificationConfirmation;
use ChurchCRM\Plugins\PhilSMS\LiveDeliveryAuthorization;
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
) use ($plugin): Response {
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
            $plugin->getApiToken()
        );

        $balance = $client->balance();

        if (($balance['status'] ?? '') !== 'success') {
            $providerMessage = strtolower(
                (string) ($balance['message'] ?? '')
            );

            if (str_contains($providerMessage, 'unauthenticated')
                || str_contains($providerMessage, 'unauthorized')
                || str_contains($providerMessage, 'invalid token')) {
                $creditsError = 'PhilSMS API authentication failed. '
                    . 'The saved API key may be invalid or expired. '
                    . 'Please verify and update your API key in Plugin Management. '
                    . 'Tip: Copying and pasting API keys can introduce extra '
                    . 'spaces, tabs, or line breaks. Make sure the complete '
                    . 'key is copied without extra characters.';
            } else {
                $creditsError = 'PhilSMS balance lookup failed. '
                    . 'Please try again later.';
            }
        }

        if ($creditsError === null) {
            $credits = $balance['data']['remaining_balance'] ?? null;

            if (!is_scalar($credits) || (string) $credits === '') {
                throw new \RuntimeException('Balance unavailable');
            }

            $credits = (string) $credits;
        }

    } catch (\Throwable $e) {
        error_log(
            '[PhilSMS] Balance lookup failed: ' . get_class($e)
        );
        $creditsError = 'PhilSMS balance temporarily unavailable. '
            . 'Please check your connection or try again later.';
    }

    // Read-only approval notification queue.
    $notifications = [];
    $notificationsError = null;

    try {
        $notifications = NotificationQueue::listRecent(50);
    } catch (\Throwable $e) {
        error_log(
            '[PhilSMS] Notification queue lookup failed: ' .
            get_class($e)
        );
        $notificationsError = 'Approval notifications temporarily unavailable.';
    }

    // Resolve recipient candidates for pending approval notifications.
    // Read-only: no queue updates or SMS sending.
    $recipientCandidates = [];

    foreach ($notifications as $notification) {
        if ($notification['status'] !== 'pending') {
            continue;
        }

        $queueId = (int) $notification['id'];

        try {
            $recipientCandidates[$queueId] =
                NotificationQueue::getRecipientCandidates(
                    (string) $notification['event_type'],
                    (int) $notification['event_entity_id']
                );
        } catch (\Throwable $e) {
            error_log(
                '[PhilSMS] Recipient lookup failed for queue ID ' .
                $queueId . ': ' . get_class($e)
            );

            $recipientCandidates[$queueId] = [];
        }
    }

    // Load configured message templates for the management page.
    // Read-only: no template changes or SMS sending.
    $messageTemplates = [];
    $messageTemplatesError = null;

    try {
        $messageTemplates = MessageTemplateRepository::listAll();
    } catch (\Throwable $e) {
        error_log(
            '[PhilSMS] Message template lookup failed: ' . get_class($e)
        );
        $messageTemplatesError = 'Message templates temporarily unavailable.';
    }

    // Read-only plugin metadata and non-sensitive configuration.
    $pluginMetadata = [];
    $pluginMetadataError = null;

    try {
        $metadataPath = __DIR__ . '/../plugin.json';
        $metadataJson = file_get_contents($metadataPath);

        if ($metadataJson === false) {
            throw new \RuntimeException('Plugin metadata unavailable');
        }

        $metadata = json_decode(
            $metadataJson,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($metadata)) {
            throw new \RuntimeException('Invalid plugin metadata');
        }

        foreach ([
            'name',
            'version',
            'author',
            'description',
            'type',
            'minimumCRMVersion',
        ] as $field) {
            $value = $metadata[$field] ?? '';

            $pluginMetadata[$field] = is_string($value)
                ? $value
                : '';
        }
    } catch (\Throwable $e) {
        error_log(
            '[PhilSMS] Plugin metadata lookup failed: ' .
            get_class($e)
        );
        $pluginMetadataError = 'Plugin information unavailable.';
    }

    $safeSettings = $plugin->getSafeSettingsSummary();

    // Count ChurchCRM self-registrations awaiting approval.
    // Read-only: does not change approvals or SMS notifications.
    $pendingApprovalCount = 0;
    $pendingApprovalCountError = null;

    try {
        $pendingApprovalCount = PendingApprovalCounter::count();
    } catch (\Throwable $e) {
        error_log(
            '[PhilSMS] Pending approval count failed: ' .
            get_class($e)
        );
        $pendingApprovalCountError =
            'Pending approval count temporarily unavailable.';
    }

    $renderer = new PhpRenderer(__DIR__ . '/../views/');

    return $renderer->render($response, 'manage.php', [
        'sRootPath' => SystemURLs::getRootPath(),
        'sPageTitle' => 'PhilSMS Messaging',
        'configured' => $plugin->isConfigured(),
        'liveSendingEnabled' => $plugin->isLiveSendingEnabled(),
        'people' => $people,
        'credits' => $credits,
        'creditsError' => $creditsError,
        'notifications' => $notifications,
        'notificationsError' => $notificationsError,
        'recipientCandidates' => $recipientCandidates,
        'messageTemplates' => $messageTemplates,
        'messageTemplatesError' => $messageTemplatesError,
        'pluginMetadata' => $pluginMetadata,
        'pluginMetadataError' => $pluginMetadataError,
        'safeSettings' => $safeSettings,
        'pendingApprovalCount' => $pendingApprovalCount,
        'pendingApprovalCountError' => $pendingApprovalCountError,
    ]);

})->add(AdminRoleAuthMiddleware::class);

/**
 * Save a message template. Administrator-only; never sends SMS.
 */
$app->post('/philsms/templates/save', function (
    Request $request,
    Response $response
): Response {
    $data = (array) $request->getParsedBody();

    $json = static function (
        int $status,
        array $payload
    ) use ($response): Response {
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
        !is_string($data['csrf'] ?? null) ||
        !hash_equals(
            $_SESSION['philsms_csrf'],
            $data['csrf']
        )) {
        return $json(403, [
            'success' => false,
            'message' => 'Your session has expired or the security token is invalid.'
        ]);
    }

    $key = $data['template_key'] ?? null;
    $name = $data['template_name'] ?? null;
    $body = $data['message_body'] ?? null;
    $enabled = $data['is_enabled'] ?? null;

    if (!is_string($key) ||
        !is_string($name) ||
        !is_string($body) ||
        !in_array($enabled, ['0', '1'], true)) {
        return $json(422, [
            'success' => false,
            'message' => 'Invalid template form data.'
        ]);
    }

    try {
        MessageTemplateRepository::save(
            $key,
            $name,
            $body,
            $enabled === '1'
        );

        return $json(200, [
            'success' => true,
            'message' => 'Message template saved. No SMS was sent.'
        ]);
    } catch (\InvalidArgumentException $e) {
        return $json(422, [
            'success' => false,
            'message' => $e->getMessage()
        ]);
    } catch (\Throwable $e) {
        error_log(
            '[PhilSMS] Template save failed: ' . get_class($e)
        );

        return $json(500, [
            'success' => false,
            'message' => 'Unable to save the message template.'
        ]);
    }
})->add(AdminRoleAuthMiddleware::class);

$app->post('/philsms/notifications/save-draft', function (
    Request $request,
    Response $response
): Response {
    $data = (array) $request->getParsedBody();

    $json = static function (int $status, array $payload) use ($response): Response {
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

    $queueId = filter_var(
        $data['queue_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $recipientId = filter_var(
        $data['recipient_person_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $message = $data['message'] ?? '';

    if (!is_string($message) ||
        $queueId === false ||
        $recipientId === false ||
        trim($message) === '' ||
        mb_strlen($message) > 1000) {
        return $json(422, [
            'success' => false,
            'message' => 'Select a valid notification and recipient, and enter a message of up to 1000 characters.'
        ]);
    }

    try {
        $saved = NotificationQueue::saveDraft(
            $queueId,
            $recipientId,
            $message
        );

        if (!$saved) {
            return $json(409, [
                'success' => false,
                'message' => 'This notification is no longer pending or does not exist.'
            ]);
        }

        NotificationConfirmation::invalidate();

        return $json(200, [
            'success' => true,
            'message' => 'Notification draft saved. No SMS was sent.'
        ]);
    } catch (\InvalidArgumentException $e) {
        return $json(422, [
            'success' => false,
            'message' => 'The selected recipient is not eligible for this notification.'
        ]);
    } catch (\Throwable $e) {
        error_log(
            '[PhilSMS] Save Draft failed: ' . get_class($e)
        );

        return $json(500, [
            'success' => false,
            'message' => 'Unable to save the notification draft.'
        ]);
    }
})->add(AdminRoleAuthMiddleware::class);

/**
 * Validate a saved approval notification without sending SMS.
 * This endpoint never claims or modifies the queue record.
 */
$app->post('/philsms/notifications/dry-run', function (
    Request $request,
    Response $response
): Response {
    $data = (array) $request->getParsedBody();

    $json = static function (
        int $status,
        array $payload
    ) use ($response): Response {
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
            (string) $_SESSION['philsms_csrf'],
            (string) ($data['csrf'] ?? '')
        )) {
        return $json(403, [
            'success' => false,
            'message' => 'Invalid or expired security token.'
        ]);
    }

    $queueId = filter_var(
        $data['queue_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($queueId === false) {
        return $json(422, [
            'success' => false,
            'message' => 'Invalid notification queue ID.'
        ]);
    }

    try {
        NotificationConfirmation::invalidate();

        $preview = NotificationQueue::getDeliveryPreview($queueId);

        if ($preview === null) {
            return $json(409, [
                'success' => false,
                'message' => 'Notification is not pending or does not exist.'
            ]);
        }

        return $json(200, [
            'success' => true,
            'dry_run' => true,
            'queue_id' => $queueId,
            'preview' => [
                'recipient_name' => $preview['recipient_name'],
                'recipient_phone' => $preview['recipient_phone'],
                'message' => $preview['message'],
            ],
            'message' => 'Notification validated successfully. No SMS was sent.'
        ]);
    } catch (\InvalidArgumentException $e) {
        return $json(422, [
            'success' => false,
            'message' => 'Notification recipient or message requires review.'
        ]);
    } catch (\Throwable $e) {
        error_log(
            '[PhilSMS] Notification dry-run failed: ' . get_class($e)
        );

        return $json(500, [
            'success' => false,
            'message' => 'Unable to validate notification.'
        ]);
    }
})->add(AdminRoleAuthMiddleware::class);

/**
 * Review the exact saved SMS and issue a session-bound delivery confirmation.
 *
 * This endpoint never claims a notification or contacts the SMS provider.
 */
$app->post('/philsms/notifications/review-delivery', function (
    Request $request,
    Response $response
): Response {
    $data = (array) $request->getParsedBody();

    $json = static function (
        int $status,
        array $payload
    ) use ($response): Response {
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
            (string) $_SESSION['philsms_csrf'],
            (string) ($data['csrf'] ?? '')
        )) {
        return $json(403, [
            'success' => false,
            'message' => 'Invalid or expired security token.'
        ]);
    }

    $queueId = filter_var(
        $data['queue_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($queueId === false) {
        return $json(422, [
            'success' => false,
            'message' => 'Invalid notification queue ID.'
        ]);
    }

    try {
        NotificationConfirmation::invalidate();

        $preview = NotificationQueue::getDeliveryPreview($queueId);

        if ($preview === null) {
            return $json(409, [
                'success' => false,
                'message' => 'Notification is not pending or does not exist.'
            ]);
        }

        $fingerprint = NotificationQueue::deliveryFingerprint(
            $queueId,
            $preview['recipient_name'],
            $preview['recipient_phone'],
            $preview['message']
        );

        $confirmationToken = NotificationConfirmation::issue(
            $queueId,
            $fingerprint
        );

        return $json(200, [
            'success' => true,
            'review_only' => true,
            'queue_id' => $queueId,
            'confirmation_token' => $confirmationToken,
            'preview' => [
                'recipient_name' => $preview['recipient_name'],
                'recipient_phone' => $preview['recipient_phone'],
                'message' => $preview['message'],
            ],
            'message' => 'Notification ready for administrator confirmation. No SMS was sent.'
        ]);
    } catch (\InvalidArgumentException $e) {
        return $json(422, [
            'success' => false,
            'message' => 'Notification recipient or message requires review.'
        ]);
    } catch (\Throwable $e) {
        error_log(
            '[PhilSMS] Delivery review failed: ' . get_class($e)
        );

        return $json(500, [
            'success' => false,
            'message' => 'Unable to validate notification.'
        ]);
    }
})->add(AdminRoleAuthMiddleware::class);

/**
 * Approval notification delivery authorization.
 *
 * Requires a single-use confirmation bound to the reviewed SMS payload.
 * Live delivery remains subject to both server and plugin safeguards.
 */
$app->post('/philsms/notifications/deliver', function (
    Request $request,
    Response $response
) use ($plugin): Response {
    $data = (array) $request->getParsedBody();

    $json = static function (
        int $status,
        array $payload
    ) use ($response): Response {
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
            (string) $_SESSION['philsms_csrf'],
            (string) ($data['csrf'] ?? '')
        )) {
        return $json(403, [
            'success' => false,
            'message' => 'Invalid or expired security token.'
        ]);
    }

    $queueId = filter_var(
        $data['queue_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($queueId === false || $queueId === null) {
        return $json(422, [
            'success' => false,
            'message' => 'Invalid notification queue ID.'
        ]);
    }

    $confirmationToken = $data['confirmation_token'] ?? null;

    if (!is_string($confirmationToken)) {
        NotificationConfirmation::invalidate();

        return $json(403, [
            'success' => false,
            'message' => 'Notification confirmation is required. Review again.'
        ]);
    }

    $expectedFingerprint = NotificationConfirmation::consume(
        $queueId,
        $confirmationToken
    );

    if ($expectedFingerprint === null) {
        return $json(403, [
            'success' => false,
            'message' => 'Notification confirmation expired or is invalid. Review again.'
        ]);
    }

    try {
        if (!$plugin->isLiveSendingEnabled()) {
            return $json(200, [
                'success' => true,
                'dry_run' => true,
                'queue_id' => $queueId,
                'message' => 'Delivery authorization validated. No SMS was sent.'
            ]);
        }

        // Require explicit live-delivery authorization before
        // initializing the provider client.
        if (
            !LiveDeliveryAuthorization::isAuthorized(
                $plugin->isLiveSendingEnabled(),
                $data['live_authorization'] ?? null
            )
        ) {
            NotificationConfirmation::invalidate();

            return $json(403, [
                'success' => false,
                'message' => 'Explicit live delivery authorization is required.'
            ]);
        }

        $client = new PhilSMSClient(
            $plugin->getApiToken(),
            $plugin->getSenderId()
        );

        $result = NotificationDeliveryService::deliver(
            $queueId,
            $client,
            $plugin,
            true,
            $expectedFingerprint
        );

        $status = $result['status'];

        if ($status === 'not_pending') {
            return $json(409, [
                'success' => false,
                'dry_run' => false,
                'queue_id' => $queueId,
                'status' => $status,
                'message' => 'Notification is no longer pending.'
            ]);
        }

        if ($status === 'uncertain') {
            return $json(202, [
                'success' => false,
                'dry_run' => false,
                'queue_id' => $queueId,
                'status' => $status,
                'message' => 'SMS submission outcome is uncertain. '
                    . 'Review the notification before taking further action.'
            ]);
        }

        if ($status === 'failed') {
            return $json(200, [
                'success' => false,
                'dry_run' => false,
                'queue_id' => $queueId,
                'status' => $status,
                'message' => 'SMS submission failed. '
                    . 'Review the notification before taking further action.'
            ]);
        }

        if ($status !== 'sent') {
            throw new \RuntimeException(
                'Unexpected notification delivery status.'
            );
        }

        return $json(200, [
            'success' => true,
            'dry_run' => false,
            'queue_id' => $queueId,
            'status' => 'sent',
            'message' => 'PhilSMS accepted the SMS submission. '
                . 'Handset delivery is not confirmed.'
        ]);
    } catch (\InvalidArgumentException $e) {
        return $json(422, [
            'success' => false,
            'message' => 'Notification recipient or message requires review.'
        ]);
    } catch (\Throwable $e) {
        error_log(
            '[PhilSMS] Notification delivery validation failed: ' .
            get_class($e)
        );

        return $json(500, [
            'success' => false,
            'message' => 'Unable to validate notification delivery.'
        ]);
    }
})->add(AdminRoleAuthMiddleware::class);

$app->post('/philsms/send', function (Request $request, Response $response) use ($plugin): Response {
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

    $dry = !$plugin->isLiveSendingEnabled();
    $results = [];
    $processed = 0;
    $failed = 0;

    try {
        $client = $dry
            ? null
            : new PhilSMSClient(
                $plugin->getApiToken(),
                $plugin->getSenderId()
            );

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
$app->get('/philsms/balance', function(Request $request,Response $response) use ($plugin):Response {
 try { $result=(new PhilSMSClient($plugin->getApiToken()))->balance(); $response->getBody()->write('<pre>'.htmlspecialchars(json_encode($result,JSON_PRETTY_PRINT),ENT_QUOTES).'</pre>'); }
 catch (\Throwable $e) {
  error_log('PhilSMS balance endpoint failed: ' . get_class($e));
  $response->getBody()->write('Balance temporarily unavailable.');
 }
 return $response->withHeader('Content-Type','text/html; charset=UTF-8')->withHeader('Cache-Control','no-store');
})->add(AdminRoleAuthMiddleware::class);
