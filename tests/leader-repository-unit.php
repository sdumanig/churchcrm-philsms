<?php

declare(strict_types=1);

// Offline repository tests: stub ChurchCRM authentication, person lookup and Propel.
// No database connection, migration, web request or SMS is performed.
namespace ChurchCRM\Authentication {
    final class AuthenticationManager {
        public static ?object $user = null;
        public static function getCurrentUser(): object {
            if (self::$user === null) {
                throw new \RuntimeException('No authenticated user');
            }
            return self::$user;
        }
    }
}

namespace ChurchCRM\model\ChurchCRM {
    final class PersonQuery {
        public static array $people = [];
        public static function create(): self { return new self(); }
        public function findPk(int $id): ?object {
            return self::$people[$id] ?? null;
        }
    }
}

namespace Propel\Runtime {
    final class Propel {
        public static \TestConnection $connection;
        public static function getWriteConnection(string $name): \TestConnection {
            return self::$connection;
        }
        public static function getReadConnection(string $name): \TestConnection {
            return self::$connection;
        }
    }
}

namespace {
    use ChurchCRM\Authentication\AuthenticationManager as Auth;
    use ChurchCRM\model\ChurchCRM\PersonQuery;
    use ChurchCRM\Plugins\PhilSMS\LeaderRepository;
    use Propel\Runtime\Propel;

    final class TestStatement {
        public array $params = [];
        public function __construct(private TestConnection $db, private string $sql) {}
        public function execute(array $params = []): bool {
            $this->params = $params;
            $this->db->calls[] = ['sql' => $this->sql, 'params' => $params];
            if (str_contains($this->sql, 'INSERT INTO philsms_leaders')) {
                [$person, $creator, $updater] = $params;
                $old = $this->db->leaders[$person] ?? null;
                $this->db->leaders[$person] = [
                    'id' => $old['id'] ?? count($this->db->leaders) + 1,
                    'person_id' => $person, 'is_active' => 1,
                    'created_by' => $old['created_by'] ?? $creator,
                    'updated_by' => $updater,
                ];
                $this->db->affected = 1;
            } elseif (str_contains($this->sql, 'UPDATE philsms_leaders')) {
                [$actor, $person] = $params;
                $this->db->affected = isset($this->db->leaders[$person]) &&
                    $this->db->leaders[$person]['is_active'] === 1 ? 1 : 0;
                if ($this->db->affected) {
                    $this->db->leaders[$person]['is_active'] = 0;
                    $this->db->leaders[$person]['updated_by'] = $actor;
                }
            }
            return true;
        }
        public function rowCount(): int { return $this->db->affected; }
        public function fetch(int $mode = 0): array|false {
            $person = $this->params[0] ?? null;
            return $this->db->leaders[$person] ?? false;
        }
        public function fetchAll(int $mode = 0): array {
            return array_values($this->db->leaders);
        }
    }
    final class TestConnection {
        public array $calls = [];
        public array $leaders = [];
        public int $affected = 0;
        public function prepare(string $sql): TestStatement {
            return new TestStatement($this, $sql);
        }
    }

    require_once __DIR__ . '/../src/LeaderRepository.php';

    $db = new TestConnection();
    Propel::$connection = $db;
    PersonQuery::$people = [10 => new \stdClass(), 20 => new \stdClass()];

    $passed = 0;
    function verify(bool $ok, string $description): void {
        global $passed;
        if (!$ok) { throw new \RuntimeException('FAIL: ' . $description); }
        echo 'PASS: ' . $description . "\n";
        ++$passed;
    }
    function rejects(callable $action, string $exception, string $description): void {
        try {
            $action();
        } catch (\Throwable $error) {
            verify($error instanceof $exception, $description);
            return;
        }
        throw new \RuntimeException('FAIL: ' . $description);
    }

    Auth::$user = new class {
        public function isAdmin(): bool { return false; }
        public function getId(): int { return 10; }
    };
    rejects(fn() => LeaderRepository::designate(20), \RuntimeException::class,
        'Non-administrator cannot designate');
    rejects(fn() => LeaderRepository::deactivate(20), \RuntimeException::class,
        'Non-administrator cannot deactivate');
    rejects(fn() => LeaderRepository::listAll(), \RuntimeException::class,
        'Non-administrator cannot list leaders');
    verify(count($db->calls) === 0, 'Denied actions never reach the database');

    Auth::$user = new class {
        public function isAdmin(): bool { return true; }
        public function getId(): int { return 10; }
    };
    rejects(fn() => LeaderRepository::designate(999), \InvalidArgumentException::class,
        'Unknown ChurchCRM person rejected');
    rejects(fn() => LeaderRepository::designate(0), \InvalidArgumentException::class,
        'Invalid person ID rejected');

    LeaderRepository::designate(20);
    verify($db->leaders[20]['created_by'] === 10 &&
        $db->leaders[20]['updated_by'] === 10,
        'Authenticated administrator ID recorded as actor');
    LeaderRepository::designate(20);
    verify(count($db->leaders) === 1, 'Repeated designation does not duplicate leader');
    verify(LeaderRepository::deactivate(20), 'Active leader can be deactivated');
    verify(!LeaderRepository::deactivate(20), 'Repeated deactivation reports no change');
    verify($db->leaders[20]['created_by'] === 10 &&
        $db->leaders[20]['updated_by'] === 10,
        'Deactivation preserves original creator');
    LeaderRepository::designate(20);
    verify($db->leaders[20]['is_active'] === 1, 'Designation reactivates leader');
    verify(LeaderRepository::findByPersonId(20)['person_id'] === 20,
        'Administrator can retrieve leader');

    echo "\nResults: {$passed} passed\n";
}
