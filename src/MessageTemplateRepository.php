<?php

namespace ChurchCRM\Plugins\PhilSMS;

use Propel\Runtime\Propel;

final class MessageTemplateRepository
{
    public const TEMPLATE_KEYS = [
        'guest_individual_welcome',
        'guest_family_welcome',
        'guest_general_welcome',
        'guest_followup',
        'member_followup',
    ];

    public static function findByKey(string $key): ?array
    {
        self::validateKey($key);

        $connection = Propel::getReadConnection('default');

        $statement = $connection->prepare(
            'SELECT id, template_key, template_name, message_body,
                    is_enabled, created_at, updated_at
             FROM philsms_message_templates
             WHERE template_key = ?'
        );

        $statement->execute([$key]);

        $template = $statement->fetch(\PDO::FETCH_ASSOC);

        return $template === false ? null : $template;
    }

    public static function listAll(): array
    {
        $connection = Propel::getReadConnection('default');

        $statement = $connection->prepare(
            'SELECT id, template_key, template_name, message_body,
                    is_enabled, created_at, updated_at
             FROM philsms_message_templates
             ORDER BY template_name ASC'
        );

        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function save(
        string $key,
        string $name,
        string $body,
        bool $enabled = true
    ): void {
        self::validateKey($key);

        $name = trim($name);
        $body = trim($body);

        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException(
                'Template name must contain 1–120 characters.'
            );
        }

        if ($body === '' || mb_strlen($body) > 1000) {
            throw new \InvalidArgumentException(
                'Template message must contain 1–1000 characters.'
            );
        }

        MessageTemplateRenderer::validate($body);

        $connection = Propel::getWriteConnection('default');

        $statement = $connection->prepare(
            'INSERT INTO philsms_message_templates
                (template_key, template_name, message_body, is_enabled)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                template_name = VALUES(template_name),
                message_body = VALUES(message_body),
                is_enabled = VALUES(is_enabled)'
        );

        $statement->execute([
            $key,
            $name,
            $body,
            $enabled ? 1 : 0,
        ]);
    }

    private static function validateKey(string $key): void
    {
        if (!in_array($key, self::TEMPLATE_KEYS, true)) {
            throw new \InvalidArgumentException(
                'Unsupported PhilSMS template type.'
            );
        }
    }
}
