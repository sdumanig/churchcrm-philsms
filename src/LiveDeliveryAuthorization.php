<?php

declare(strict_types=1);

namespace ChurchCRM\Plugins\PhilSMS;

/**
 * Determines whether a notification delivery request
 * satisfies the live-delivery authorization requirement.
 *
 * This class never initializes an SMS provider or sends messages.
 */
final class LiveDeliveryAuthorization
{
    public static function isAuthorized(
        bool $liveSendingEnabled,
        mixed $authorization
    ): bool {
        if (!$liveSendingEnabled) {
            return true;
        }

        return $authorization === '1';
    }
}
