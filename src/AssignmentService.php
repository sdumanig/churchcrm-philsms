<?php

declare(strict_types=1);

namespace ChurchCRM\Plugins\PhilSMS;

use ChurchCRM\Authentication\AuthenticationManager;
use ChurchCRM\model\ChurchCRM\FamilyQuery;
use ChurchCRM\model\ChurchCRM\PersonQuery;
use Propel\Runtime\Propel;

/**
 * Assign a recorded approval to one designated active leader.
 * Route callers must enforce CSRF; this service never sends SMS.
 */
final class AssignmentService
{
    public static function assign(int $approvalQueueId, int $leaderId, int $contactPersonId): int
    {
        $user = AuthenticationManager::getCurrentUser();
        if ($user === null || !$user->isAdmin()) {
            throw new \RuntimeException('Administrator access required.');
        }
        $actor = (int) $user->getId();
        if ($actor <= 0 || $approvalQueueId <= 0 || $leaderId <= 0 || $contactPersonId <= 0) {
            throw new \InvalidArgumentException('Invalid assignment identifiers.');
        }

        $db = Propel::getWriteConnection('default');
        $db->beginTransaction();
        try {
            // Lock the event before checking duplicates or resolving its contact.
            $query = $db->prepare(
                'SELECT event_type, event_entity_id, person_id, family_id
                 FROM philsms_notification_queue WHERE id = ? FOR UPDATE'
            );
            $query->execute([$approvalQueueId]);
            $event = $query->fetch(\PDO::FETCH_ASSOC);
            if (!$event || !in_array($event['event_type'],
                ['individual_approved', 'family_approved'], true)) {
                throw new \InvalidArgumentException('No recorded approval event.');
            }

            $existing = $db->prepare(
                'SELECT COUNT(*) FROM philsms_leader_assignments WHERE approval_queue_id = ?'
            );
            $existing->execute([$approvalQueueId]);

            if ((int) $existing->fetchColumn() > 0) {
                throw new \RuntimeException('Approval event already assigned.');
            }

            $leader = $db->prepare(
                'SELECT person_id FROM philsms_leaders
                 WHERE id = ? AND is_active = 1 FOR UPDATE'
            );
            $leader->execute([$leaderId]);
            $leaderPersonId = $leader->fetchColumn();
            if ($leaderPersonId === false ||
                PersonQuery::create()->findPk((int) $leaderPersonId) === null) {
                throw new \InvalidArgumentException('Leader is inactive or missing.');
            }

            $entityId = (int) $event['event_entity_id'];
            if ($entityId <= 0) {
                throw new \InvalidArgumentException('Invalid approval entity.');
            }
            if ($event['event_type'] === 'individual_approved') {
                if ((int) $event['person_id'] !== $entityId ||
                    $contactPersonId !== $entityId ||
                    PersonQuery::create()->findPk($contactPersonId) === null) {
                    throw new \InvalidArgumentException('Invalid individual approval contact.');
                }
            } else {
                if ((int) $event['family_id'] !== $entityId) {
                    throw new \InvalidArgumentException('Invalid family approval event.');
                }
                $family = FamilyQuery::create()->findPk($entityId);
                if ($family === null) {
                    throw new \InvalidArgumentException('Approved family no longer exists.');
                }
                $eligible = false;
                foreach ($family->getAdults() as $adult) {
                    if ((int) $adult->getId() === $contactPersonId) {
                        $eligible = true;
                        break;
                    }
                }
                if (!$eligible) {
                    throw new \InvalidArgumentException(
                        'Contact must be an eligible family head or spouse.'
                    );
                }
            }

            $insert = $db->prepare(
                "INSERT INTO philsms_leader_assignments
                 (approval_queue_id, leader_id, contact_person_id, status, assigned_by)
                 VALUES (?, ?, ?, 'pending', ?)"
            );
            $insert->execute([$approvalQueueId, $leaderId, $contactPersonId, $actor]);
            $assignmentId = (int) $db->lastInsertId();

            $activity = $db->prepare(
                'INSERT INTO philsms_assignment_activity
                 (assignment_id, actor_person_id, action, previous_status, new_status)
                 VALUES (?, ?, ?, NULL, ?)'
            );
            $activity->execute([$assignmentId, $actor, 'assigned', 'pending']);
            $db->commit();
            return $assignmentId;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
