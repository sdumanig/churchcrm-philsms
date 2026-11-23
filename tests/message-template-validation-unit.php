<?php

require_once __DIR__ . '/../src/MessageTemplateRenderer.php';
require_once __DIR__ . '/../src/MessageTemplateRepository.php';

use ChurchCRM\Plugins\PhilSMS\MessageTemplateRepository;

$passed = 0;
$failed = 0;

function check(string $description, callable $test): void
{
    global $passed, $failed;

    try {
        $test();
        echo "PASS: {$description}\n";
        $passed++;
    } catch (Throwable $e) {
        echo "FAIL: {$description}: {$e->getMessage()}\n";
        $failed++;
    }
}

function expectInvalid(callable $callback): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        return;
    }

    throw new RuntimeException('Expected InvalidArgumentException.');
}

check('Five supported template keys', function () {
    if (count(MessageTemplateRepository::TEMPLATE_KEYS) !== 5) {
        throw new RuntimeException('Unexpected template key count.');
    }
});

check('Unsupported template key rejected', function () {
    expectInvalid(fn() =>
        MessageTemplateRepository::findByKey('unknown_template')
    );
});

check('Empty template name rejected', function () {
    expectInvalid(fn() =>
        MessageTemplateRepository::save(
            'guest_individual_welcome', '', 'Welcome'
        )
    );
});

check('Template name over 120 characters rejected', function () {
    expectInvalid(fn() =>
        MessageTemplateRepository::save(
            'guest_individual_welcome',
            str_repeat('A', 121),
            'Welcome'
        )
    );
});

check('Empty message rejected', function () {
    expectInvalid(fn() =>
        MessageTemplateRepository::save(
            'guest_individual_welcome', 'Welcome', ''
        )
    );
});

check('Message over 1000 characters rejected', function () {
    expectInvalid(fn() =>
        MessageTemplateRepository::save(
            'guest_individual_welcome',
            'Welcome',
            str_repeat('A', 1001)
        )
    );
});


check('Unsupported placeholder rejected before database access', function () {
    expectInvalid(fn() =>
        MessageTemplateRepository::save(
            'guest_individual_welcome',
            'Welcome',
            'Hello {password}'
        )
    );
});

check('Malformed placeholder rejected before database access', function () {
    expectInvalid(fn() =>
        MessageTemplateRepository::save(
            'guest_individual_welcome',
            'Welcome',
            'Hello {first_name'
        )
    );
});

check('Invalid UTF-8 rejected before database access', function () {
    expectInvalid(fn() =>
        MessageTemplateRepository::save(
            'guest_individual_welcome',
            'Welcome',
            "\xC3\x28"
        )
    );
});

echo "\nResults: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
