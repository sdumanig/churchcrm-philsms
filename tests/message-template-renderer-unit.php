<?php

require_once __DIR__ . '/../src/MessageTemplateRenderer.php';

use ChurchCRM\Plugins\PhilSMS\MessageTemplateRenderer as Renderer;

$passed = 0;
$failed = 0;

function check(string $name, callable $test): void
{
    global $passed, $failed;

    try {
        $test();
        echo "PASS: {$name}\n";
        $passed++;
    } catch (Throwable $e) {
        echo "FAIL: {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertSameValue(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Expected ' . var_export($expected, true) .
            ', got ' . var_export($actual, true)
        );
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

check('Valid placeholders accepted', function () {
    Renderer::validate(
        'Hello {first_name}, welcome to {church_name}!'
    );
});

check('Unsupported placeholder rejected', function () {
    expectInvalid(fn() =>
        Renderer::validate('Hello {unknown_field}')
    );
});

check('Unmatched opening brace rejected', function () {
    expectInvalid(fn() =>
        Renderer::validate('Hello {first_name')
    );
});

check('Unmatched closing brace rejected', function () {
    expectInvalid(fn() =>
        Renderer::validate('Hello first_name}')
    );
});

check('Empty template rejected', function () {
    expectInvalid(fn() => Renderer::validate('   '));
});

check('Personalized message rendered', function () {
    assertSameValue(
        'Hello Juan, welcome to SDG Christian Church!',
        Renderer::render(
            'Hello {first_name}, welcome to {church_name}!',
            [
                'first_name' => 'Juan',
                'church_name' => 'SDG Christian Church',
            ]
        )
    );
});

check('Repeated placeholders rendered', function () {
    assertSameValue(
        'Juan, welcome Juan!',
        Renderer::render(
            '{first_name}, welcome {first_name}!',
            ['first_name' => 'Juan']
        )
    );
});

check('Missing placeholder value rejected', function () {
    expectInvalid(fn() =>
        Renderer::render(
            'Hello {first_name}',
            []
        )
    );
});

check('Empty placeholder value rejected', function () {
    expectInvalid(fn() =>
        Renderer::render(
            'Hello {first_name}',
            ['first_name' => '']
        )
    );
});

check('Rendered message over 1000 characters rejected', function () {
    expectInvalid(fn() =>
        Renderer::render(
            'Hello {first_name}',
            ['first_name' => str_repeat('A', 1000)]
        )
    );
});

check('GSM-7 short message', function () {
    $result = Renderer::smsLength('Hello Juan');

    assertSameValue('GSM-7', $result['encoding']);
    assertSameValue(10, $result['characters']);
    assertSameValue(10, $result['units']);
    assertSameValue(1, $result['segments']);
});

check('GSM-7 extended characters count twice', function () {
    $result = Renderer::smsLength('Hello {Juan}');

    assertSameValue('GSM-7', $result['encoding']);
    assertSameValue(12, $result['characters']);
    assertSameValue(14, $result['units']);
    assertSameValue(1, $result['segments']);
});

check('GSM-7 single segment boundary', function () {
    assertSameValue(
        1,
        Renderer::smsLength(str_repeat('A', 160))['segments']
    );
});

check('GSM-7 multipart boundary', function () {
    assertSameValue(
        2,
        Renderer::smsLength(str_repeat('A', 161))['segments']
    );
});

check('Unicode message', function () {
    $result = Renderer::smsLength('Welcome 😊');

    assertSameValue('UCS-2/Unicode', $result['encoding']);
    assertSameValue(9, $result['characters']);
    assertSameValue(10, $result['units']);
    assertSameValue(1, $result['segments']);
});

check('Unicode multipart boundary', function () {
    assertSameValue(
        2,
        Renderer::smsLength(str_repeat('漢', 71))['segments']
    );
});


check('Emoji uses two UTF-16 code units', function () {
    $result = Renderer::smsLength('😊');

    assertSameValue('UCS-2/Unicode', $result['encoding']);
    assertSameValue(1, $result['characters']);
    assertSameValue(2, $result['units']);
    assertSameValue(1, $result['segments']);
});

check('Invalid UTF-8 template rejected', function () {
    expectInvalid(fn() =>
        Renderer::validate("\xC3\x28")
    );
});

check('Invalid UTF-8 SMS rejected', function () {
    expectInvalid(fn() =>
        Renderer::smsLength("\xC3\x28")
    );
});

check('Placeholder values are not recursively expanded', function () {
    assertSameValue(
        'Hello {church_name}',
        Renderer::render(
            'Hello {first_name}',
            ['first_name' => '{church_name}']
        )
    );
});

echo "\nResults: {$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
