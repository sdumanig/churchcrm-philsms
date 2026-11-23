<?php

namespace ChurchCRM\Plugins\PhilSMS;

use ChurchCRM\dto\ChurchMetaData;
use ChurchCRM\model\ChurchCRM\PersonQuery;

final class WelcomeMessagePreparer
{
    public static function prepare(
        string $eventType,
        int $personId
    ): ?string {
        $templateKey = match ($eventType) {
            'individual_approved' => 'guest_individual_welcome',
            'family_approved' => 'guest_family_welcome',
            default => throw new \InvalidArgumentException(
                'Unsupported notification type.'
            ),
        };

        if ($personId <= 0) {
            throw new \InvalidArgumentException(
                'Invalid recipient.'
            );
        }

        $person = PersonQuery::create()->findPk($personId);

        if ($person === null) {
            throw new \InvalidArgumentException(
                'Recipient not found.'
            );
        }

        $template = MessageTemplateRepository::findByKey($templateKey);

        if ($template === null || !(bool) $template['is_enabled']) {
            return null;
        }

        $firstName = trim((string) $person->getFirstName());
        $lastName = trim((string) $person->getLastName());
        $fullName = trim($firstName . ' ' . $lastName);
        $familyName = trim((string) $person->getFamilyName());
        $churchName = trim((string) ChurchMetaData::getChurchName());

        return MessageTemplateRenderer::render(
            (string) $template['message_body'],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $fullName,
                'family_name' => $familyName,
                'guest_name' => $fullName,
                'church_name' => $churchName,
            ]
        );
    }
}
