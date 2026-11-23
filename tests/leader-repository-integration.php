<?php

declare(strict_types=1);

/**
 * Isolated MySQL integration test for the real LeaderRepository SQL.
 * Uses only a disposable philsms_test_* schema, stubbed ChurchCRM identities,
 * and no application bootstrap or SMS provider.
 */
namespace ChurchCRM\Authentication {
    final class AuthenticationManager {
        public static ?object $user = null;
        public static function getCurrentUser(): object {
            if (self::$user === null) {
                throw new \RuntimeException('Not authenticated');
            }
            return self::$user;
        }
    }
}
namespace ChurchCRM\model\ChurchCRM {
    final class PersonQuery {
        public static array $people = [];
        public static function create(): self { return new self(); }
        public function findPk(int $id): ?object { return self::$people[$id] ?? null; }
    }
}
namespace Propel\Runtime {
    final class Propel {
        public static \PDO $connection;
        public static function getReadConnection(string $name): \PDO { return self::$connection; }
        public static function getWriteConnection(string $name): \PDO { return self::$connection; }
    }
}
namespace {
    use ChurchCRM\Authentication\AuthenticationManager as Auth;
    use ChurchCRM\model\ChurchCRM\PersonQuery;
    use ChurchCRM\Plugins\PhilSMS\LeaderRepository;
    use Propel\Runtime\Propel;

    if (getenv('PHILSMS_TEST_ALLOW_DDL') !== 'YES' || getenv('PHILSMS_LIVE_SEND') === '1') {
        fwrite(STDERR, "REFUSED: Scratch database opt-in required and live SMS must be off.\n");
        exit(2);
    }
    $dsn = getenv('PHILSMS_TEST_DSN') ?: '';
    if (!str_starts_with($dsn, 'mysql:') ||
        !preg_match('/(?:^|;)dbname=(philsms_test_[a-zA-Z0-9_]+)(?:;|$)/', $dsn)) {
        fwrite(STDERR, "REFUSED: Only philsms_test_* MySQL databases allowed.\n");
        exit(2);
    }
    $pdo = new \PDO($dsn, getenv('PHILSMS_TEST_USER') ?: '',
        getenv('PHILSMS_TEST_PASSWORD') ?: '',
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
         \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
         \PDO::ATTR_TIMEOUT => 3]);
    if (!preg_match('/\Aphilsms_test_[a-zA-Z0-9_]+\z/D',
        (string) $pdo->query('SELECT DATABASE()')->fetchColumn())) {
        throw new \RuntimeException('Connected database is not a scratch database.');
    }
    Propel::$connection = $pdo;
    $pdo->exec(file_get_contents(__DIR__ . '/../migrations/004_leader_registry.sql'));
    require_once __DIR__ . '/../src/LeaderRepository.php';

    // Test-only IDs: no ChurchCRM person table or production data is accessed.
    PersonQuery::$people = [901 => new \stdClass(), 902 => new \stdClass(), 903 => new \stdClass()];
    $passed = 0;
    function check(bool $condition, string $label): void {
        global $passed;
        if (!$condition) { throw new \RuntimeException('FAIL: ' . $label); }
        echo 'PASS: ' . $label . "\n";
        ++$passed;
    }
    function expectReject(callable $callback, string $label): void {
        try { $callback(); } catch (\RuntimeException $e) {
            check(true, $label);
            return;
        }
        throw new \RuntimeException('FAIL: ' . $label);
    }
    Auth::$user = new class {
        public function isAdmin(): bool { return false; }
        public function getId(): int { return 901; }
    };
    expectReject(fn() => LeaderRepository::designate(903), 'Non-admin cannot designate');
    check((int) $pdo->query('SELECT COUNT(*) FROM philsms_leaders')->fetchColumn() === 0,
        'Denied designation creates no row');

    Auth::$user = new class {
        public function isAdmin(): bool { return true; }
        public function getId(): int { return 901; }
    };
    LeaderRepository::designate(903);
    $leader = LeaderRepository::findByPersonId(903);
    check($leader !== null && (int) $leader['created_by'] === 901 &&
        (int) $leader['updated_by'] === 901, 'Creator and updater derive from authenticated admin');
    LeaderRepository::designate(903);
    check((int) $pdo->query('SELECT COUNT(*) FROM philsms_leaders WHERE person_id = 903')->fetchColumn() === 1,
        'Duplicate designation preserves one row');

    Auth::$user = new class {
        public function isAdmin(): bool { return true; }
        public function getId(): int { return 902; }
    };
    check(LeaderRepository::deactivate(903), 'Different admin deactivates leader');
    $leader = LeaderRepository::findByPersonId(903);
    check((int) $leader['is_active'] === 0 && (int) $leader['created_by'] === 901 &&
        (int) $leader['updated_by'] === 902, 'Original creator retained; updater changes to acting admin');
    check(!LeaderRepository::deactivate(903), 'Repeated deactivation is a no-op');
    LeaderRepository::designate(903);
    $leader = LeaderRepository::findByPersonId(903);
    check((int) $leader['is_active'] === 1 && (int) $leader['created_by'] === 901 &&
        (int) $leader['updated_by'] === 902, 'Reactivation retains creator and records current admin');
    check(count(LeaderRepository::listAll()) >= 1, 'Admin can list persisted leaders');
    foreach (range(910, 916) as $id) PersonQuery::$people[$id] = new \stdClass();
    foreach (range(910, 915) as $id) LeaderRepository::designate($id);
    check((int) $pdo->query('SELECT COUNT(*) FROM philsms_leaders WHERE is_active = 1')->fetchColumn() === 7,
        'Seven active leaders allowed');
    expectReject(fn() => LeaderRepository::designate(916), 'Eighth active leader rejected');
    LeaderRepository::designate(903);
    check((int) $pdo->query('SELECT COUNT(*) FROM philsms_leaders WHERE is_active = 1')->fetchColumn() === 7,
        'Repeated active designation allowed at capacity');
    check(LeaderRepository::deactivate(910), 'Deactivate to free a designation slot');
    LeaderRepository::designate(916);
    check((int) $pdo->query('SELECT COUNT(*) FROM philsms_leaders WHERE is_active = 1')->fetchColumn() === 7,
        'Vacated slot assigned without exceeding capacity');
    expectReject(fn() => LeaderRepository::designate(910), 'Reactivation at capacity rejected');
    // Independent MySQL session simulates a concurrent administrator holding
    // the designation lock. The repository must refuse rather than bypass it.
    $competitor = new \PDO($dsn, getenv('PHILSMS_TEST_USER') ?: '',
        getenv('PHILSMS_TEST_PASSWORD') ?: '',
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    check((int) $competitor->query(
        "SELECT GET_LOCK('philsms_leader_designation', 0)"
    )->fetchColumn() === 1, 'Independent session acquires designation lock');
    try {
        check(LeaderRepository::deactivate(915), 'Free one slot while designation lock is held');
        $before = (int) $pdo->query(
            'SELECT COUNT(*) FROM philsms_leaders WHERE is_active = 1'
        )->fetchColumn();
        expectReject(fn() => LeaderRepository::designate(910),
            'Competing designation refuses unavailable advisory lock');
        check((int) $pdo->query(
            'SELECT COUNT(*) FROM philsms_leaders WHERE is_active = 1'
        )->fetchColumn() === $before,
            'Lock contention cannot change active leader count');
    } finally {
        check((int) $competitor->query(
            "SELECT RELEASE_LOCK('philsms_leader_designation')"
        )->fetchColumn() === 1, 'Independent session releases designation lock');
    }
    LeaderRepository::designate(910);
    check((int) $pdo->query(
        'SELECT COUNT(*) FROM philsms_leaders WHERE is_active = 1'
    )->fetchColumn() === 7, 'Designation succeeds after lock release');
    expectReject(fn() => LeaderRepository::designate(915),
        'Eighth active designation still rejected after contention');
    echo "\\nResults: {$passed} passed\\n";
}
