<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';
require '/var/www/html/Include/Config.php';
require __DIR__ . '/../src/NotificationDeliveryService.php';

use ChurchCRM\Plugins\PhilSMS\NotificationDeliveryService;
use ChurchCRM\Plugins\PhilSMS\NotificationQueue;
use ChurchCRM\Plugins\PhilSMS\PhilSMSDeliveryException;
use Propel\Runtime\Propel;

$connection = Propel::getWriteConnection('default');
$createdIds = [];
$passed = 0;
$failed = 0;

function assertion(bool $condition, string $description): void
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

function queueRecord(int $id): ?array
{
    global $connection;

    $statement = $connection->prepare(
        'SELECT id, status, attempts, sent_at, last_error
         FROM philsms_notification_queue WHERE id = ?'
    );
    $statement->execute([$id]);

    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function protectedRecordIsSafe(): bool
{
    $record = queueRecord(4);

    return $record !== null
        && $record['status'] === 'pending'
        && (int) $record['attempts'] === 0
        && $record['sent_at'] === null;
}

function createFixture(int $personId, string $name, string $phone): int
{
    global $connection, $createdIds;

    $statement = $connection->prepare(
        "INSERT INTO philsms_notification_queue
         (event_type, event_entity_id, person_id,
          recipient_name, recipient_phone, message, status)
         VALUES ('individual_approved', ?, ?, ?, ?, ?, 'pending')"
    );

    $statement->execute([
        $personId,
        $personId,
        $name,
        $phone,
        'PhilSMS staging integration test. No actual SMS.'
    ]);

    $id = (int) $connection->lastInsertId();
    $createdIds[] = $id;

    return $id;
}

try {
    if (!protectedRecordIsSafe()) {
        throw new RuntimeException(
            'Protected notification ID 4 is not in its expected state.'
        );
    }

    if (getenv('PHILSMS_LIVE_SEND') === '1') {
        throw new RuntimeException(
            'Live sending must be disabled during integration testing.'
        );
    }

    /*
     * Find one eligible existing person.
     * No personal details are printed.
     */
    $person = null;

    foreach (
        \ChurchCRM\model\ChurchCRM\PersonQuery::create()
            ->orderById()
            ->find() as $candidate
    ) {
        $phone = \ChurchCRM\Plugins\PhilSMS\PhilSMSClient::normalize(
            (string) $candidate->getCellPhone()
        );

        if ($phone !== null) {
            $person = [
                'id' => (int) $candidate->getId(),
                'name' => trim(
                    $candidate->getFirstName() . ' ' .
                    $candidate->getLastName()
                ),
                'phone' => $phone
            ];
            break;
        }
    }

    if ($person === null) {
        throw new RuntimeException(
            'No eligible person with a valid mobile number was found.'
        );
    }

    /*
     * Invoke the private orchestration method with reflection.
     * This bypasses the public delivery entry point ONLY in tests.
     */
    $method = new ReflectionMethod(
        NotificationDeliveryService::class,
        'processClaimedDelivery'
    );

    /*
     * Fingerprint authorization tests.
     * These claim disposable queue fixtures without contacting a provider.
     */
    $fingerprintScenarios = [
        'Matching fingerprint' => [
            'change' => null,
            'accepted' => true
        ],
        'Changed message' => [
            'change' => 'message',
            'accepted' => false
        ],
        'Changed recipient name' => [
            'change' => 'recipient_name',
            'accepted' => false
        ],
        'Changed recipient phone' => [
            'change' => 'recipient_phone',
            'accepted' => false
        ]
    ];

    foreach ($fingerprintScenarios as $description => $scenario) {
        $id = createFixture(
            $person['id'],
            $person['name'],
            $person['phone']
        );

        $preview = NotificationQueue::getDeliveryPreview($id);

        if ($preview === null) {
            throw new RuntimeException(
                "{$description}: fixture preview unavailable."
            );
        }

        $fingerprint = NotificationQueue::deliveryFingerprint(
            $id,
            $preview['recipient_name'],
            $preview['recipient_phone'],
            $preview['message']
        );

        if ($scenario['change'] !== null) {
            $changes = [
                'message' => 'Changed message after review.',
                'recipient_name' => 'Changed recipient after review.',
                'recipient_phone' => '00000000000'
            ];

            $column = $scenario['change'];

            $statement = $connection->prepare(
                "UPDATE philsms_notification_queue
                 SET {$column} = ?
                 WHERE id = ? AND status = 'pending'"
            );

            $statement->execute([$changes[$column], $id]);
        }

        $claimed = false;
        $rejected = false;

        try {
            $payload = NotificationQueue::claimForDeliveryPayload(
                $id,
                $fingerprint
            );

            $claimed = $payload !== null;
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        }

        $record = queueRecord($id);

        if ($scenario['accepted']) {
            assertion(
                $claimed && !$rejected,
                "{$description}: matching payload accepted"
            );

            assertion(
                $record['status'] === 'processing'
                    && (int) $record['attempts'] === 1,
                "{$description}: exactly one claim"
            );
        } else {
            assertion(
                !$claimed && $rejected,
                "{$description}: stale payload rejected"
            );

            assertion(
                $record['status'] === 'pending'
                    && (int) $record['attempts'] === 0,
                "{$description}: queue unchanged after rejection"
            );
        }

        $delete = $connection->prepare(
            'DELETE FROM philsms_notification_queue WHERE id = ?'
        );
        $delete->execute([$id]);
    }

    $scenarios = [
        'Successful submission' => [
            'expected' => 'sent',
            'submit' => static fn(): array => ['status' => 'success']
        ],
        'Definitive failure' => [
            'expected' => 'failed',
            'submit' => static function (): array {
                throw new PhilSMSDeliveryException(
                    'Simulated definitive failure',
                    false
                );
            }
        ],
        'Uncertain outcome' => [
            'expected' => 'uncertain',
            'submit' => static function (): array {
                throw new PhilSMSDeliveryException(
                    'Simulated ambiguous outcome',
                    true
                );
            }
        ]
    ];

    foreach ($scenarios as $description => $scenario) {
        $id = createFixture(
            $person['id'],
            $person['name'],
            $person['phone']
        );

        $result = $method->invoke(
            null,
            $id,
            $scenario['submit']
        );

        $record = queueRecord($id);

        assertion(
            $result['status'] === $scenario['expected'],
            "{$description}: returned status"
        );

        assertion(
            $record['status'] === $scenario['expected'],
            "{$description}: persisted status"
        );

        assertion(
            (int) $record['attempts'] === 1,
            "{$description}: exactly one attempt"
        );

        assertion(
            ($record['sent_at'] !== null) ===
                ($scenario['expected'] === 'sent'),
            "{$description}: sent timestamp"
        );

        $duplicate = $method->invoke(
            null,
            $id,
            static function (): array {
                throw new RuntimeException(
                    'Duplicate submission must not execute.'
                );
            }
        );

        assertion(
            $duplicate['status'] === 'not_pending',
            "{$description}: duplicate claim prevented"
        );

        /*
         * Remove each fixture before the next scenario because
         * (event_type, event_entity_id) must remain unique.
         */
        $delete = $connection->prepare(
            'DELETE FROM philsms_notification_queue WHERE id = ?'
        );
        $delete->execute([$id]);
    }

    assertion(
        protectedRecordIsSafe(),
        'Notification ID 4 remains unchanged'
    );

} catch (Throwable $e) {
    echo 'ERROR: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    /*
     * Delete only IDs created by this test.
     */
    foreach ($createdIds as $id) {
        $delete = $connection->prepare(
            'DELETE FROM philsms_notification_queue WHERE id = ?'
        );
        $delete->execute([$id]);
    }
}

echo "\nResults: {$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
