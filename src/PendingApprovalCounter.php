<?php

namespace ChurchCRM\Plugins\PhilSMS;

use ChurchCRM\model\ChurchCRM\FamilyQuery;
use ChurchCRM\model\ChurchCRM\Person;
use ChurchCRM\Service\PersonService;

/**
 * Count ChurchCRM self-registrations awaiting approval.
 *
 * Uses the same counting logic as ChurchCRM's Self Registrations page.
 * Members of self-registered families are counted under their family.
 *
 * Read-only: does not approve registrations or process SMS.
 */
final class PendingApprovalCounter
{
    public static function count(): int
    {
        $pendingFamilies = FamilyQuery::create()
            ->filterByEnteredBy(Person::SELF_REGISTER)
            ->filterByNeedsReview(true)
            ->count();

        $pendingIndividuals = PersonService::selfRegisteredPersonQuery()
            ->filterByNeedsReview(true)
            ->count();

        return (int) ($pendingFamilies + $pendingIndividuals);
    }
}
