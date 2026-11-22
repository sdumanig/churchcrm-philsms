<?php

declare(strict_types=1);

namespace ChurchCRM\Plugins\PhilSMS;

/**
 * Session-bound, single-use authorization for a reviewed notification.
 *
 * This class does not claim queue records or contact the SMS provider.
 */
final class NotificationConfirmation
{
    private const SESSION_KEY = 'philsms_notification_confirmation';
    private const LIFETIME_SECONDS = 300;

    public static function issue(
        int $queueId,
        string $fingerprint
    ): string {
        self::requireSession();

        if ($queueId <= 0 || !self::validFingerprint($fingerprint)) {
            throw new \InvalidArgumentException(
                'Invalid notification confirmation details.'
            );
        }

        $token = bin2hex(random_bytes(32));

        $_SESSION[self::SESSION_KEY] = [
            'token_hash' => hash('sha256', $token),
            'queue_id' => $queueId,
            'fingerprint' => $fingerprint,
            'expires_at' => time() + self::LIFETIME_SECONDS
        ];

        return $token;
    }

    /**
     * Consume a confirmation token and return its reviewed fingerprint.
     *
     * Every consumption attempt invalidates the outstanding token.
     */
    public static function consume(
        int $queueId,
        string $token
    ): ?string {
        self::requireSession();

        $confirmation = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        if (!is_array($confirmation) ||
            $queueId <= 0 ||
            !preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            return null;
        }

        $storedHash = $confirmation['token_hash'] ?? null;
        $fingerprint = $confirmation['fingerprint'] ?? null;
        $expiresAt = $confirmation['expires_at'] ?? null;

        if (!is_string($storedHash) ||
            !self::validFingerprint($storedHash) ||
            !is_string($fingerprint) ||
            !self::validFingerprint($fingerprint) ||
            !is_int($expiresAt) ||
            time() >= $expiresAt ||
            ($confirmation['queue_id'] ?? null) !== $queueId) {
            return null;
        }

        if (!hash_equals($storedHash, hash('sha256', $token))) {
            return null;
        }

        return $fingerprint;
    }

    public static function invalidate(): void
    {
        self::requireSession();
        unset($_SESSION[self::SESSION_KEY]);
    }

    private static function validFingerprint(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function requireSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \LogicException(
                'An active administrator session is required.'
            );
        }
    }
}
