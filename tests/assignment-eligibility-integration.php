<?php

declare(strict_types=1);

namespace ChurchCRM\Authentication {
    final class AuthenticationManager {
        public static ?object $user = null;
        public static function getCurrentUser(): ?object { return self::$user; }
    }
}
namespace Propel\Runtime {
    final class Propel {
        public static \PDO $connection;
        public static function getReadConnection(string $name): \PDO { return self::$connection; }
    }
}
namespace {
    use ChurchCRM\Authentication\AuthenticationManager as Auth;
    use ChurchCRM\Plugins\PhilSMS\AssignmentEligibility;
    use Propel\Runtime\Propel;

    $dsn = getenv('PHILSMS_TEST_DSN') ?: '';
    if (getenv('PHILSMS_TEST_ALLOW_DDL') !== 'YES' ||
        getenv('PHILSMS_LIVE_SEND') === '1' ||
        !str_starts_with($dsn, 'mysql:') ||
        !preg_match('/(?:^|;)dbname=philsms_test_[a-zA-Z0-9_]+(?:;|$)/', $dsn)) {
        fwrite(STDERR, "REFUSED: Disposable MySQL test schema required.\n");
        exit(2);
    }
    $db = new \PDO($dsn, getenv('PHILSMS_TEST_USER') ?: '',
        getenv('PHILSMS_TEST_PASSWORD') ?: '',
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    if (!preg_match('/\Aphilsms_test_[a-zA-Z0-9_]+\z/D',
        (string) $db->query('SELECT DATABASE()')->fetchColumn())) {
        throw new \RuntimeException('Not a scratch database');
    }
    Propel::$connection = $db;
    $db->exec(file_get_contents(__DIR__ . '/../migrations/001_notification_queue.sql'));
    $db->exec(file_get_contents(__DIR__ . '/../migrations/004_leader_registry.sql'));
    $db->exec(file_get_contents(__DIR__ . '/../migrations/005_leader_assignments.sql'));
    require_once __DIR__ . '/../src/AssignmentEligibility.php';

    $passed = 0;
    function check(bool $ok, string $label): void {
        global $passed;
        if (!$ok) { throw new \RuntimeException('FAIL: ' . $label); }
        echo 'PASS: ' . $label . "\n";
        $passed++;
    }
    Auth::$user = new class { public function isAdmin(): bool { return false; } };
    try {
        AssignmentEligibility::listUnassigned();
        throw new \RuntimeException('FAIL: Non-admin access allowed');
    } catch (\RuntimeException $e) {
        check($e->getMessage() === 'Administrator access required.',
            'Non-admin cannot list eligible approvals');
    }
    Auth::$user = new class { public function isAdmin(): bool { return true; } };
    $insert = $db->prepare(
        'INSERT INTO philsms_notification_queue
         (event_type, event_entity_id, person_id, family_id, status)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute(['individual_approved', 7001, 7001, null, 'sent']);
    $individual = (int) $db->lastInsertId();
    $insert->execute(['family_approved', 8001, null, 8001, 'failed']);
    $family = (int) $db->lastInsertId();
    $insert->execute(['individual_approved', 7002, 9999, null, 'pending']);
    $mismatch = (int) $db->lastInsertId();
    $insert->execute(['family_approved', 8002, null, 9999, 'pending']);
    $familyMismatch = (int) $db->lastInsertId();

    $rows = AssignmentEligibility::listUnassigned();
    $ids = array_map(fn(array $row): int => (int) $row['approval_queue_id'], $rows);
    check(in_array($individual, $ids, true) && in_array($family, $ids, true),
        'Sent and failed SMS outcomes do not block approved events');
    check(!in_array($mismatch, $ids, true) && !in_array($familyMismatch, $ids, true),
        'Mismatched approval entity IDs excluded');

    $db->exec('INSERT INTO philsms_leaders
        (person_id, is_active, created_by) VALUES (901, 1, 901)');
    $leaderId = (int) $db->lastInsertId();
    $stmt = $db->prepare('INSERT INTO philsms_leader_assignments
        (approval_queue_id, leader_id, contact_person_id, assigned_by)
        VALUES (?, ?, ?, ?)');
    $stmt->execute([$individual, $leaderId, 7001, 901]);
    $ids = array_map(fn(array $row): int => (int) $row['approval_queue_id'],
        AssignmentEligibility::listUnassigned());
    check(!in_array($individual, $ids, true) && in_array($family, $ids, true),
        'Already assigned approval excluded without hiding unassigned family');
    echo "\nResults: {$passed} passed\n";
}
