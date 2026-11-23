<?php

declare(strict_types=1);

namespace ChurchCRM\Authentication {
    final class AuthenticationManager {
        public static ?object $user = null;
        public static function getCurrentUser(): ?object { return self::$user; }
    }
}
namespace ChurchCRM\model\ChurchCRM {
    final class PersonQuery {
        public static array $people = [];
        public static function create(): self { return new self(); }
        public function findPk(int $id): ?object { return self::$people[$id] ?? null; }
    }
    final class FamilyQuery {
        public static array $families = [];
        public static function create(): self { return new self(); }
        public function findPk(int $id): ?object { return self::$families[$id] ?? null; }
    }
}
namespace Propel\Runtime {
    final class Propel {
        public static \PDO $connection;
        public static function getWriteConnection(string $name): \PDO { return self::$connection; }
    }
}
namespace {
    use ChurchCRM\Authentication\AuthenticationManager as Auth;
    use ChurchCRM\model\ChurchCRM\PersonQuery;
    use ChurchCRM\model\ChurchCRM\FamilyQuery;
    use ChurchCRM\Plugins\PhilSMS\AssignmentService;
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
    foreach (['001_notification_queue', '004_leader_registry',
              '005_leader_assignments', '006_assignment_activity'] as $migration) {
        $db->exec(file_get_contents(__DIR__ . '/../migrations/' . $migration . '.sql'));
    }
    require_once __DIR__ . '/../src/AssignmentService.php';
    foreach ([9901, 902, 17001, 17002, 18001, 18002] as $id) {
        PersonQuery::$people[$id] = new class($id) {
            public function __construct(private int $id) {}
            public function getId(): int { return $this->id; }
        };
    }
    FamilyQuery::$families[18100] = new class {
        public function getAdults(): array {
            return [PersonQuery::$people[18001]];
        }
    };
    $passed = 0;
    function check(bool $ok, string $label): void {
        global $passed;
        if (!$ok) { throw new \RuntimeException('FAIL: ' . $label); }
        echo 'PASS: ' . $label . "\n";
        ++$passed;
    }
    function rejects(callable $fn, string $label): void {
        try { $fn(); } catch (\Throwable $e) { check(true, $label); return; }
        throw new \RuntimeException('FAIL: ' . $label);
    }
    $db->exec('INSERT INTO philsms_leaders (person_id, created_by) VALUES (9901, 902)');
    $leader = (int) $db->lastInsertId();
    $event = $db->prepare(
        'INSERT INTO philsms_notification_queue
         (event_type, event_entity_id, person_id, family_id, status)
         VALUES (?, ?, ?, ?, ?)'
    );
    $event->execute(['individual_approved', 17001, 17001, null, 'sent']);
    $individual = (int) $db->lastInsertId();
    $event->execute(['family_approved', 18100, null, 18100, 'failed']);
    $family = (int) $db->lastInsertId();

    Auth::$user = new class {
        public function isAdmin(): bool { return false; }
        public function getId(): int { return 902; }
    };
    rejects(fn() => AssignmentService::assign($individual, $leader, 17001),
        'Non-admin denied');
    Auth::$user = new class {
        public function isAdmin(): bool { return true; }
        public function getId(): int { return 902; }
    };
    rejects(fn() => AssignmentService::assign($individual, $leader, 17002),
        'Individual contact must match approved person');
    rejects(fn() => AssignmentService::assign($family, $leader, 18002),
        'Family contact must be eligible adult');
    $count = $db->prepare(
        'SELECT COUNT(*) FROM philsms_leader_assignments
         WHERE approval_queue_id IN (?, ?)'
    );
    $count->execute([$individual, $family]);
    check((int) $count->fetchColumn() === 0,
        'Rejected assignments leave no rows for test approvals');

    $first = AssignmentService::assign($individual, $leader, 17001);
    check($first > 0, 'Approved individual assigned regardless of SMS delivery');
    rejects(fn() => AssignmentService::assign($individual, $leader, 17001),
        'Second assignment for same approval rejected');
    $second = AssignmentService::assign($family, $leader, 18001);
    check($second > $first, 'Family assigned to explicitly chosen adult');
    $history = $db->query(
        "SELECT a.assigned_by, h.actor_person_id, h.action, h.new_status
         FROM philsms_leader_assignments a
         JOIN philsms_assignment_activity h ON h.assignment_id = a.id"
    )->fetchAll(\PDO::FETCH_ASSOC);
    check(count($history) === 2 &&
        array_reduce($history, fn(bool $ok, array $row): bool =>
            $ok && (int) $row['assigned_by'] === 902 &&
            (int) $row['actor_person_id'] === 902 &&
            $row['action'] === 'assigned' && $row['new_status'] === 'pending', true),
        'Each assignment and activity row records authenticated admin');

    $db->prepare('UPDATE philsms_leaders SET is_active = 0 WHERE id = ?')->execute([$leader]);
    $event->execute(['individual_approved', 17002, 17002, null, 'pending']);
    $third = (int) $db->lastInsertId();
    rejects(fn() => AssignmentService::assign($third, $leader, 17002),
        'Inactive leader cannot receive assignment');
    // A failed audit write must roll back the assignment in the same transaction.
    $db->prepare('UPDATE philsms_leaders SET is_active = 1 WHERE id = ?')->execute([$leader]);
    PersonQuery::$people[17003] = new class {
        public function getId(): int { return 17003; }
    };
    $event->execute(['individual_approved', 17003, 17003, null, 'pending']);
    $rollbackEvent = (int) $db->lastInsertId();
    $db->exec("CREATE TRIGGER philsms_test_reject_activity
        BEFORE INSERT ON philsms_assignment_activity
        FOR EACH ROW SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Intentional audit failure in isolated test'");
    try {
        rejects(fn() => AssignmentService::assign($rollbackEvent, $leader, 17003),
            'Activity insertion failure rejects assignment');
        $count = $db->prepare(
            'SELECT COUNT(*) FROM philsms_leader_assignments WHERE approval_queue_id = ?'
        );
        $count->execute([$rollbackEvent]);
        check((int) $count->fetchColumn() === 0,
            'Failed audit insertion rolls back assignment row');
    } finally {
        $db->exec('DROP TRIGGER philsms_test_reject_activity');
    }
    echo "\nResults: {$passed} passed\n";
}
