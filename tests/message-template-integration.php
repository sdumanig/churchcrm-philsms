<?php

declare(strict_types=1);

require '/var/www/html/vendor/autoload.php';
require '/var/www/html/Include/Config.php';
require __DIR__ . '/../src/MessageTemplateRenderer.php';
require __DIR__ . '/../src/MessageTemplateRepository.php';

use ChurchCRM\Plugins\PhilSMS\MessageTemplateRepository as Repository;
use Propel\Runtime\Propel;

$connection = Propel::getWriteConnection('default');
$key = 'guest_individual_welcome';
$passed = 0;
$failed = 0;

function check(bool $condition, string $description): void
{
    global $passed, $failed;

    echo ($condition ? 'PASS: ' : 'FAIL: ') . $description . "\n";

    if ($condition) {
        $passed++;
    } else {
        $failed++;
    }
}

try {
    if (getenv('PHILSMS_LIVE_SEND') === '1') {
        throw new RuntimeException('Live sending must be disabled.');
    }

    if ($connection->inTransaction()) {
        throw new RuntimeException('Unexpected existing transaction.');
    }

    $original = Repository::findByKey($key);

    if ($original === null) {
        throw new RuntimeException(
            'Required default template is missing. No changes made.'
        );
    }

    $connection->beginTransaction();

    Repository::save(
        $key,
        'Updated Individual Welcome',
        'Hello {first_name}, welcome to {church_name}!',
        false
    );

    $statement = $connection->prepare(
        'SELECT template_name, message_body, is_enabled
         FROM philsms_message_templates
         WHERE template_key = ?'
    );
    $statement->execute([$key]);
    $updated = $statement->fetch(PDO::FETCH_ASSOC);

    check(
        $updated !== false,
        'Existing template retrieved after update'
    );

    check(
        ($updated['template_name'] ?? null) ===
            'Updated Individual Welcome',
        'Template name updated'
    );

    check(
        ($updated['message_body'] ?? null) ===
            'Hello {first_name}, welcome to {church_name}!',
        'Template message updated'
    );

    check(
        (int) ($updated['is_enabled'] ?? -1) === 0,
        'Template disabled'
    );

    $statement = $connection->prepare(
        'SELECT COUNT(*)
         FROM philsms_message_templates
         WHERE template_key = ?'
    );
    $statement->execute([$key]);

    check(
        (int) $statement->fetchColumn() === 1,
        'Template remains unique'
    );

} catch (Throwable $e) {
    $failed++;
    echo 'FAIL: ' . $e->getMessage() . "\n";
} finally {
    if ($connection->inTransaction()) {
        $connection->rollBack();
        echo "Transaction rolled back.\n";
    }
}

if (isset($original) && $original !== null) {
    $restored = Repository::findByKey($key);

    check(
        $restored !== null
        && $restored['template_name'] === $original['template_name']
        && $restored['message_body'] === $original['message_body']
        && (int) $restored['is_enabled'] ===
            (int) $original['is_enabled'],
        'Original template values restored after rollback'
    );
}

echo "\nResults: {$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
