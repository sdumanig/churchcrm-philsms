<?php

declare(strict_types=1);

namespace ChurchCRM\Plugins\PhilSMS;

use ChurchCRM\Authentication\AuthenticationManager;
use ChurchCRM\model\ChurchCRM\PersonQuery;
use Propel\Runtime\Propel;

/**
 * Administrator-maintained designated leaders, independent of ChurchCRM roles.
 *
 * The caller must also enforce route-level authorization and CSRF protection.
 * Writes derive actor identity from the authenticated administrator session.
 * No SMS is sent by this repository.
 */
final class LeaderRepository
{
    public static function listAll(): array
    {
        self::requireAdmin();

        $connection = Propel::getReadConnection('default');
        $statement = $connection->prepare(
            'SELECT id, person_id, is_active, created_by, updated_by,
                    created_at, updated_at
             FROM philsms_leaders
             ORDER BY is_active DESC, id ASC'
        );
        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function findByPersonId(int $personId): ?array
    {
        self::requireAdmin();
        self::validatePersonId($personId);

        $connection = Propel::getReadConnection('default');
        $statement = $connection->prepare(
            'SELECT id, person_id, is_active, created_by, updated_by,
                    created_at, updated_at
             FROM philsms_leaders
             WHERE person_id = ?'
        );
        $statement->execute([$personId]);
        $leader = $statement->fetch(\PDO::FETCH_ASSOC);

        return $leader === false ? null : $leader;
    }

    /**
     * Designate an existing ChurchCRM person or reactivate their designation.
     * The unique person_id key makes repeated designation idempotent.
     */
    public static function designate(int $personId): void
    {
        $actorPersonId = self::currentAdminPersonId();
        self::validatePersonId($personId);
        self::requireExistingPerson($personId);

        $connection = Propel::getWriteConnection('default');
        $statement = $connection->prepare(
            'INSERT INTO philsms_leaders
                (person_id, is_active, created_by, updated_by)
             VALUES (?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
                is_active = 1,
                updated_by = VALUES(updated_by)'
        );
        $statement->execute([$personId, $actorPersonId, $actorPersonId]);
    }

    /**
     * Deactivate an existing leader; preserve their record for assignment history.
     * Returns false when no leader exists or no state change was necessary.
     */
    public static function deactivate(int $personId): bool
    {
        $actorPersonId = self::currentAdminPersonId();
        self::validatePersonId($personId);

        $connection = Propel::getWriteConnection('default');
        $statement = $connection->prepare(
            'UPDATE philsms_leaders
             SET is_active = 0, updated_by = ?
             WHERE person_id = ? AND is_active = 1'
        );
        $statement->execute([$actorPersonId, $personId]);

        return $statement->rowCount() === 1;
    }

    private static function requireAdmin(): void
    {
        $user = AuthenticationManager::getCurrentUser();

        if ($user === null || !$user->isAdmin()) {
            throw new \RuntimeException('PhilSMS leader management requires administrator access.');
        }
    }

    private static function currentAdminPersonId(): int
    {
        self::requireAdmin();
        $personId = (int) AuthenticationManager::getCurrentUser()->getId();
        self::validatePersonId($personId);

        return $personId;
    }

    private static function validatePersonId(int $personId): void
    {
        if ($personId <= 0) {
            throw new \InvalidArgumentException('Invalid ChurchCRM person ID.');
        }
    }

    private static function requireExistingPerson(int $personId): void
    {
        if (PersonQuery::create()->findPk($personId) === null) {
            throw new \InvalidArgumentException('ChurchCRM person does not exist.');
        }
    }
}
