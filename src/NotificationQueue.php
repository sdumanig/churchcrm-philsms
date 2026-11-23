<?php

namespace ChurchCRM\Plugins\PhilSMS;

use Propel\Runtime\Propel;

final class NotificationQueue
{
    private const MIGRATION_FILE = __DIR__ . '/../migrations/001_notification_queue.sql';

    /**
     * Queue an approval notification without sending an SMS.
     *
     * Returns true when inserted, false when already queued.
     * Uses the existing Propel connection and does not commit.
     */
    public static function enqueue(
        string $eventType,
        int $entityId,
        ?int $personId,
        ?int $familyId,
        ?string $recipientName,
        ?string $recipientPhone,
        ?string $message
    ): bool {
        if (!in_array($eventType, ['individual_approved', 'family_approved'], true)
            || $entityId <= 0) {
            throw new \InvalidArgumentException('Invalid PhilSMS approval event.');
        }

        $connection = Propel::getWriteConnection('default');

        $statement = $connection->prepare(
            'INSERT INTO philsms_notification_queue
             (event_type, event_entity_id, person_id, family_id,
              recipient_name, recipient_phone, message, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        try {
            $statement->execute([
                $eventType,
                $entityId,
                $personId,
                $familyId,
                $recipientName,
                $recipientPhone,
                $message,
                'pending'
            ]);
        } catch (\Throwable $e) {
            $error = $e;

            while ($error !== null) {
                if ($error instanceof \PDOException
                    && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                    return false;
                }

                $error = $error->getPrevious();
            }

            throw $e;
        }

        return $statement->rowCount() === 1;
    }

    /**
     * Retrieve recent approval notifications for administrator review.
     * Read-only: does not modify records or send SMS.
     */
    public static function listRecent(int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));

        $connection = Propel::getReadConnection('default');

        $statement = $connection->prepare(
            'SELECT id, event_type, event_entity_id,
                    person_id, family_id,
                    recipient_name, recipient_phone, message,
                    status, attempts, created_at, sent_at
             FROM philsms_notification_queue
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit
        );

        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Return valid mobile-number candidates for an approval event.
     * Read-only; does not select a recipient or send SMS.
     */
    public static function getRecipientCandidates(
        string $eventType,
        int $entityId
    ): array {
        if ($entityId <= 0) {
            throw new \InvalidArgumentException('Invalid approval entity ID.');
        }

        $query = \ChurchCRM\model\ChurchCRM\PersonQuery::create();

        if ($eventType === 'family_approved') {
            $query->filterByFamId($entityId);
        } elseif ($eventType === 'individual_approved') {
            $query->filterById($entityId);
        } else {
            throw new \InvalidArgumentException('Invalid approval event type.');
        }

        $candidates = [];

        foreach ($query->orderByLastName()->orderByFirstName()->find() as $person) {
            $phone = PhilSMSClient::normalize(
                (string) $person->getCellPhone()
            );

            if ($phone === null) {
                continue;
            }

            $candidates[] = [
                'person_id' => (int) $person->getId(),
                'name' => trim(
                    (string) $person->getFirstName() . ' ' .
                    (string) $person->getLastName()
                ),
                'phone' => $phone,
            ];
        }

        return $candidates;
    }

    /**
     * Prepare a welcome message for an eligible pending notification recipient.
     * Read-only: does not save a draft, change status, or send SMS.
     */
    public static function prepareWelcomeDraft(
        int $queueId,
        int $recipientPersonId
    ): ?string {
        if ($queueId <= 0 || $recipientPersonId <= 0) {
            throw new \InvalidArgumentException('Invalid notification or recipient.');
        }

        $connection = Propel::getReadConnection('default');
        $statement = $connection->prepare(
            'SELECT event_type, event_entity_id, status
             FROM philsms_notification_queue
             WHERE id = ?'
        );
        $statement->execute([$queueId]);
        $notification = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!$notification || $notification['status'] !== 'pending') {
            return null;
        }

        $candidates = self::getRecipientCandidates(
            (string) $notification['event_type'],
            (int) $notification['event_entity_id']
        );

        $eligible = false;

        foreach ($candidates as $candidate) {
            if ((int) $candidate['person_id'] === $recipientPersonId) {
                $eligible = true;
                break;
            }
        }

        if (!$eligible) {
            throw new \InvalidArgumentException(
                'Selected recipient is not eligible for this notification.'
            );
        }

        return WelcomeMessagePreparer::prepare(
            (string) $notification['event_type'],
            $recipientPersonId
        );
    }

    /**
     * Save a recipient and message for a pending approval notification.
     * Does not send SMS, change status, or increment attempts.
     */
    public static function saveDraft(
        int $queueId,
        int $recipientPersonId,
        string $message
    ): bool {
        $message = trim($message);

        if ($queueId <= 0 || $recipientPersonId <= 0 ||
            $message === '' || mb_strlen($message) > 1000) {
            throw new \InvalidArgumentException(
                'Invalid approval notification draft.'
            );
        }

        $connection = Propel::getWriteConnection('default');

        $connection->beginTransaction();

        try {
            $statement = $connection->prepare(
                'SELECT event_type, event_entity_id, status
                 FROM philsms_notification_queue
                 WHERE id = ?
                 FOR UPDATE'
            );
            $statement->execute([$queueId]);
            $notification = $statement->fetch(\PDO::FETCH_ASSOC);

            if (!$notification || $notification['status'] !== 'pending') {
                $connection->rollBack();
                return false;
            }

            $candidates = self::getRecipientCandidates(
                (string) $notification['event_type'],
                (int) $notification['event_entity_id']
            );

            $selected = null;

            foreach ($candidates as $candidate) {
                if ((int) $candidate['person_id'] === $recipientPersonId) {
                    $selected = $candidate;
                    break;
                }
            }

            if ($selected === null) {
                throw new \InvalidArgumentException(
                    'Selected recipient is not eligible for this notification.'
                );
            }

            $update = $connection->prepare(
                'UPDATE philsms_notification_queue
                 SET recipient_name = ?,
                     recipient_phone = ?,
                     message = ?
                 WHERE id = ? AND status = ?'
            );

            $update->execute([
                $selected['name'],
                $selected['phone'],
                $message,
                $queueId,
                'pending'
            ]);

            $connection->commit();
            return true;
        } catch (\Throwable $e) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Fingerprint the exact notification payload reviewed by an administrator.
     */
    public static function deliveryFingerprint(
        int $queueId,
        string $recipientName,
        string $recipientPhone,
        string $message
    ): string {
        return hash('sha256', json_encode(
            [
                $queueId,
                $recipientName,
                $recipientPhone,
                $message
            ],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    /**
     * Atomically claim a pending notification for delivery.
     *
     * Does not contact the SMS provider.
     * A processing notification must never be automatically reclaimed.
     */
    public static function claimForDeliveryPayload(
    int $queueId,
    string $expectedFingerprint
): ?array
    {
        if ($queueId <= 0) {
            throw new \InvalidArgumentException(
                'Invalid notification queue ID.'
            );
        }

if (!preg_match('/\A[a-f0-9]{64}\z/D', $expectedFingerprint)) {
    throw new \InvalidArgumentException(
        'Invalid reviewed notification fingerprint.'
    );
}

        $connection = Propel::getWriteConnection('default');
        $connection->beginTransaction();

        try {
            $statement = $connection->prepare(
                'SELECT event_type, event_entity_id,
                        recipient_name, recipient_phone,
                        message, status
                 FROM philsms_notification_queue
                 WHERE id = ?
                 FOR UPDATE'
            );
            $statement->execute([$queueId]);
            $notification = $statement->fetch(\PDO::FETCH_ASSOC);

            if (!$notification || $notification['status'] !== 'pending') {
                $connection->rollBack();
                return null;
            }

            $phone = PhilSMSClient::normalize(
                (string) ($notification['recipient_phone'] ?? '')
            );
            $message = trim(
                (string) ($notification['message'] ?? '')
            );

            if ($phone === null ||
                $message === '' ||
                mb_strlen($message) > 1000) {
                throw new \InvalidArgumentException(
                    'Notification requires a valid recipient and message.'
                );
            }

// Verify that the notification still matches the exact
// recipient and message reviewed by the administrator.
$actualFingerprint = self::deliveryFingerprint(
    $queueId,
    (string) ($notification['recipient_name'] ?? ''),
    $phone,
    $message
);

if (!hash_equals(
    $expectedFingerprint,
    $actualFingerprint
)) {
    throw new \InvalidArgumentException(
        'Notification changed since review. Review again.'
    );
}

            $candidates = self::getRecipientCandidates(
                (string) $notification['event_type'],
                (int) $notification['event_entity_id']
            );

            $matchingRecipients = 0;

            foreach ($candidates as $candidate) {
                if (hash_equals(
                    (string) ($notification['recipient_name'] ?? ''),
                    (string) $candidate['name']
                ) && hash_equals(
                    $phone,
                    (string) $candidate['phone']
                )) {
                    $matchingRecipients++;
                }
            }

            if ($matchingRecipients !== 1) {
                throw new \InvalidArgumentException(
                    'Saved recipient is no longer uniquely eligible. '
                    . 'Review and save the notification draft again.'
                );
            }

            $update = $connection->prepare(
                'UPDATE philsms_notification_queue
                 SET status = ?, attempts = attempts + 1
                 WHERE id = ? AND status = ?'
            );

            $update->execute([
                'processing',
                $queueId,
                'pending'
            ]);

            if ($update->rowCount() !== 1) {
                throw new \RuntimeException(
                    'Unable to claim notification.'
                );
            }

            $connection->commit();

            return [
                'recipient_phone' => $phone,
                'message' => $message
            ];
        } catch (\Throwable $e) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Compatibility wrapper for existing callers.
     */

    /**
     * Retrieve a validated notification preview without sending SMS.
     *
     * Returns null when the notification does not exist or is not pending.
     * Recipient details must not be logged or exposed in API responses.
     */
    public static function getDeliveryPreview(int $queueId): ?array
    {
        if ($queueId <= 0) {
            throw new \InvalidArgumentException(
                'Invalid notification queue ID.'
            );
        }

        $connection = Propel::getReadConnection('default');

        $statement = $connection->prepare(
            'SELECT id, event_type, event_entity_id,
                    recipient_name, recipient_phone,
                    message, status
             FROM philsms_notification_queue
             WHERE id = ?'
        );

        $statement->execute([$queueId]);
        $notification = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!$notification || $notification['status'] !== 'pending') {
            return null;
        }

        $phone = PhilSMSClient::normalize(
            (string) ($notification['recipient_phone'] ?? '')
        );

        $message = trim(
            (string) ($notification['message'] ?? '')
        );

        if ($phone === null ||
            $message === '' ||
            mb_strlen($message) > 1000) {
            throw new \InvalidArgumentException(
                'Notification draft is incomplete or invalid.'
            );
        }

        $candidates = self::getRecipientCandidates(
            (string) $notification['event_type'],
            (int) $notification['event_entity_id']
        );

        $matches = 0;

        foreach ($candidates as $candidate) {
            if (hash_equals(
                (string) ($notification['recipient_name'] ?? ''),
                (string) $candidate['name']
            ) && hash_equals(
                $phone,
                (string) $candidate['phone']
            )) {
                $matches++;
            }
        }

        if ($matches !== 1) {
            throw new \InvalidArgumentException(
                'Saved recipient is no longer uniquely eligible.'
            );
        }

        return [
            'queue_id' => (int) $notification['id'],
            'event_type' => (string) $notification['event_type'],
            'recipient_name' => (string) $notification['recipient_name'],
            'recipient_phone' => $phone,
            'message' => $message
        ];
    }

    public static function finalizeDelivery(
        int $queueId,
        string $status,
        ?string $error = null
    ): bool {
        if ($queueId <= 0 ||
            !in_array($status, ['sent', 'failed', 'uncertain'], true)) {
            throw new \InvalidArgumentException(
                'Invalid notification delivery outcome.'
            );
        }

        $error = $error === null ? null : trim($error);

        if ($status === 'sent') {
            $error = null;
        } elseif ($error === null || $error === '') {
            $error = 'Delivery outcome requires administrator review.';
        }

        if ($error !== null) {
            $error = mb_substr($error, 0, 500);
        }

        $connection = Propel::getWriteConnection('default');

        $statement = $connection->prepare(
            'UPDATE philsms_notification_queue
             SET status = ?,
                 last_error = ?,
                 sent_at = CASE
                     WHEN ? = ? THEN CURRENT_TIMESTAMP
                     ELSE NULL
                 END
             WHERE id = ? AND status = ?'
        );

        $statement->execute([
            $status,
            $error,
            $status,
            'sent',
            $queueId,
            'processing'
        ]);

        return $statement->rowCount() === 1;
    }

    public static function initialize(): void
    {
        $sql = file_get_contents(self::MIGRATION_FILE);

        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException(
                'PhilSMS notification queue migration is missing or empty.'
            );
        }

        $connection = Propel::getWriteConnection('default');
        $connection->exec($sql);
    }
}
