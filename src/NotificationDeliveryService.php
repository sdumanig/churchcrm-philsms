<?php

namespace ChurchCRM\Plugins\PhilSMS;

require_once __DIR__ . '/PhilSMSClient.php';
require_once __DIR__ . '/NotificationQueue.php';

/**
 * Coordinates approval-notification SMS submissions.
 *
 * No automatic retries are permitted.
 * A processing or uncertain notification requires administrator review.
 */
final class NotificationDeliveryService
{
    /**
     * Validate a notification without claiming or submitting it.
     */
    public static function preview(int $queueId): ?array
    {
        return NotificationQueue::getDeliveryPreview($queueId);
    }

    /**
     * Submit a pending notification.
     *
     * The caller must independently enforce administrator authorization,
     * CSRF protection, and the application's live-sending safeguards.
     *
     * @return array{status:string,queue_id:int}
     */
    public static function deliver(
        int $queueId,
        PhilSMSClient $client,
        PhilSMSPlugin $plugin,
        bool $authorized = false
    ): array {
        if (!$authorized) {
            throw new \LogicException(
                'Notification delivery has not been authorized.'
            );
        }

        /*
         * Defense in depth: live sending must be enabled by both
         * the server administrator and ChurchCRM configuration.
         *
         * Check before claiming to preserve pending notifications.
         */
        if (getenv('PHILSMS_LIVE_SEND') !== '1') {
            throw new \LogicException(
                'Live SMS sending is disabled at the server level.'
            );
        }

        if (!$plugin->isLiveSendingEnabled()) {
            throw new \LogicException(
                'Live SMS sending is disabled in plugin configuration.'
            );
        }

        return self::processClaimedDelivery(
            $queueId,
            static fn(string $phone, string $message): array =>
                $client->send($phone, $message)
        );

    }

    /**
     * Internal delivery orchestration.
     *
     * Call only after the public delivery authorization checks.
     * The provider callback permits isolated, network-free testing.
     */
    private static function processClaimedDelivery(
        int $queueId,
        callable $submit
    ): array {
        /*
         * Claim and retrieve the validated payload atomically.
         * Never submit a previously fetched preview.
         */
        $claimed = NotificationQueue::claimForDeliveryPayload($queueId);

        if ($claimed === null) {
            return [
                'status' => 'not_pending',
                'queue_id' => $queueId
            ];
        }

        try {
            $submit(
                $claimed['recipient_phone'],
                $claimed['message']
            );

            $status = 'sent';
            $error = null;
        } catch (PhilSMSDeliveryException $e) {
            $status = $e->isUncertain()
                ? 'uncertain'
                : 'failed';

            $error = $status === 'uncertain'
                ? 'SMS submission outcome is uncertain. Manual review required.'
                : 'PhilSMS rejected the SMS submission.';
        } catch (\InvalidArgumentException $e) {
            $status = 'failed';
            $error = 'SMS submission validation failed.';
        } catch (\Throwable $e) {
            /*
             * Unknown exceptions are conservatively classified as uncertain.
             * Never retry automatically.
             */
            $status = 'uncertain';
            $error = 'Unexpected SMS submission error. Manual review required.';

            error_log(
                '[PhilSMS] Notification delivery exception: ' .
                get_class($e)
            );
        }

        if (!NotificationQueue::finalizeDelivery(
            $queueId,
            $status,
            $error
        )) {
            throw new \RuntimeException(
                'Unable to finalize notification delivery. '
                . 'Administrator review required.'
            );
        }

        return [
            'status' => $status,
            'queue_id' => $queueId
        ];
    }
}
