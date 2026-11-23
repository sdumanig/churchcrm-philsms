<?php

namespace ChurchCRM\Plugins\PhilSMS;

final class MessageTemplateRenderer
{
    public const PLACEHOLDERS = [
        'first_name',
        'last_name',
        'full_name',
        'family_name',
        'guest_name',
        'church_name',
        'leader_name',
        'service_schedule',
    ];

    public static function validate(string $template): void
    {
        if (!mb_check_encoding($template, 'UTF-8')) {
            throw new \InvalidArgumentException(
                'Template contains invalid UTF-8.'
            );
        }

        if (trim($template) === '' || mb_strlen($template, 'UTF-8') > 1000) {
            throw new \InvalidArgumentException(
                'Template must contain 1–1000 characters.'
            );
        }

        preg_match_all('/\{([^{}]*)\}/u', $template, $matches);

        foreach ($matches[1] as $placeholder) {
            if (!in_array($placeholder, self::PLACEHOLDERS, true)) {
                throw new \InvalidArgumentException(
                    'Unsupported template placeholder: {' .
                    $placeholder . '}'
                );
            }
        }

        // Reject unmatched braces and malformed placeholder expressions.
        $withoutValidPlaceholders = preg_replace(
            '/\{(?:' . implode('|', self::PLACEHOLDERS) . ')\}/u',
            '',
            $template
        );

        if (str_contains($withoutValidPlaceholders, '{')
            || str_contains($withoutValidPlaceholders, '}')) {
            throw new \InvalidArgumentException(
                'Malformed template placeholder.'
            );
        }
    }

    public static function render(string $template, array $values): string
    {
        self::validate($template);

        preg_match_all('/\{([^{}]+)\}/u', $template, $matches);

        $replacements = [];

        foreach (array_unique($matches[1]) as $key) {
            if (!array_key_exists($key, $values)
                || !is_scalar($values[$key])
                || trim((string) $values[$key]) === '') {
                throw new \InvalidArgumentException(
                    'Missing value for placeholder: {' . $key . '}'
                );
            }

            $replacements['{' . $key . '}'] = (string) $values[$key];
        }

        $message = strtr($template, $replacements);

        if (mb_strlen($message, 'UTF-8') > 1000) {
            throw new \InvalidArgumentException(
                'Rendered SMS exceeds 1000 characters.'
            );
        }

        return $message;
    }

    public static function smsLength(string $message): array
    {
        $gsmBasic = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ"
            . "ÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
            . "¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿"
            . "abcdefghijklmnopqrstuvwxyzäöñüà";

        $gsmExtended = "^{}\\[~]|€\f";

        $characters = preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY);

        if ($characters === false) {
            throw new \InvalidArgumentException('Invalid UTF-8 message.');
        }

        $units = 0;
        $isGsm7 = true;

        foreach ($characters as $character) {
            if (mb_strpos($gsmBasic, $character, 0, 'UTF-8') !== false) {
                $units++;
            } elseif (mb_strpos($gsmExtended, $character, 0, 'UTF-8') !== false) {
                $units += 2;
            } else {
                $isGsm7 = false;
                break;
            }
        }

        if ($isGsm7) {
            $segments = $units <= 160
                ? ($units === 0 ? 0 : 1)
                : (int) ceil($units / 153);

            return [
                'encoding' => 'GSM-7',
                'characters' => count($characters),
                'units' => $units,
                'segments' => $segments,
            ];
        }

        // UTF-16 code units determine Unicode SMS segment capacity.
        $utf16 = mb_convert_encoding($message, 'UTF-16BE', 'UTF-8');
        $units = intdiv(strlen($utf16), 2);

        $segments = $units <= 70
            ? ($units === 0 ? 0 : 1)
            : (int) ceil($units / 67);

        return [
            'encoding' => 'UCS-2/Unicode',
            'characters' => count($characters),
            'units' => $units,
            'segments' => $segments,
        ];
    }
}
