<?php

namespace ChurchCRM\Plugins\PhilSMS;

/**
 * Represents a failed SMS submission.
 *
 * An uncertain outcome must not be automatically retried because
 * the provider may already have accepted the message.
 */
final class PhilSMSDeliveryException extends \RuntimeException
{
    private bool $uncertain;

    public function __construct(
        string $message,
        bool $uncertain,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->uncertain = $uncertain;
    }

    public function isUncertain(): bool
    {
        return $this->uncertain;
    }
}
