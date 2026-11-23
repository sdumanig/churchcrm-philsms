<?php

declare(strict_types=1);

namespace ChurchCRM\Plugins\PhilSMS;

use ChurchCRM\Authentication\AuthenticationManager;
use Propel\Runtime\Propel;

/**
 * Read-only candidates: recorded approval events, independent of SMS status.
 * Assignment creation must separately revalidate the event and contact.
 */
final class AssignmentEligibility
{
    public static function listUnassigned(int $limit = 50): array
    {
        $user = AuthenticationManager::getCurrentUser();
        if ($user === null || !$user->isAdmin()) {
            throw new \RuntimeException('Administrator access required.');
        }

        $limit = max(1, min($limit, 100));
        $statement = Propel::getReadConnection('default')->prepare(
            "SELECT q.id AS approval_queue_id, q.event_type, q.event_entity_id,
                    q.person_id, q.family_id, q.created_at AS approved_event_at
             FROM philsms_notification_queue q
             LEFT JOIN philsms_leader_assignments a
               ON a.approval_queue_id = q.id
             WHERE q.event_type IN ('individual_approved', 'family_approved')
               AND a.id IS NULL
               AND (
                    (q.event_type = 'individual_approved'
                     AND q.person_id = q.event_entity_id)
                    OR
                    (q.event_type = 'family_approved'
                     AND q.family_id = q.event_entity_id)
               )
             ORDER BY q.created_at DESC, q.id DESC
             LIMIT " . $limit
        );
        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
