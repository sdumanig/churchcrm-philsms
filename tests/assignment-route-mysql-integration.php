<?php
declare(strict_types=1);

namespace Psr\Http\Message {
    interface ServerRequestInterface { public function getParsedBody(); }
    interface ResponseInterface {
        public function getBody();
        public function withStatus($code);
        public function withHeader($name, $value);
        public function getStatusCode();
    }
}
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
namespace ChurchCRM\Plugins\PhilSMS {
    final class PhilSMSPlugin {
        public static function getInstance(): object { return new \stdClass(); }
    }
}
namespace {
    use ChurchCRM\Authentication\AuthenticationManager as Auth;
    use ChurchCRM\model\ChurchCRM\PersonQuery;
    use ChurchCRM\model\ChurchCRM\FamilyQuery;
    use Propel\Runtime\Propel;

    final class TestBody {
        public string $content = '';
        public function write(string $value): void { $this->content .= $value; }
        public function __toString(): string { return $this->content; }
    }
    final class TestRequest implements \Psr\Http\Message\ServerRequestInterface {
        public function __construct(private mixed $body) {}
        public function getParsedBody(): mixed { return $this->body; }
    }
    final class TestResponse implements \Psr\Http\Message\ResponseInterface {
        private TestBody $body;
        private int $status = 200;
        public array $headers = [];
        public function __construct() { $this->body = new TestBody(); }
        public function getBody(): TestBody { return $this->body; }
        public function withStatus($code): self { $next = clone $this; $next->status = $code; return $next; }
        public function withHeader($name, $value): self {
            $next = clone $this; $next->headers[$name] = $value; return $next;
        }
        public function getStatusCode(): int { return $this->status; }
    }
    final class TestRoute {
        public array $middleware = [];
        public function __construct(public $handler) {}
        public function add($name): self { $this->middleware[] = $name; return $this; }
    }
    final class TestApp {
        public array $routes = [];
        public function get(string $path, callable $handler): TestRoute {
            return $this->routes['GET ' . $path] = new TestRoute($handler);
        }
        public function post(string $path, callable $handler): TestRoute {
            return $this->routes['POST ' . $path] = new TestRoute($handler);
        }
    }

    $dsn = getenv('PHILSMS_TEST_DSN') ?: '';
    if (getenv('PHILSMS_TEST_ALLOW_DDL') !== 'YES' ||
        getenv('PHILSMS_LIVE_SEND') !== '0' ||
        !str_starts_with($dsn, 'mysql:') ||
        !preg_match('/(?:^|;)dbname=philsms_test_[a-zA-Z0-9_]+(?:;|$)/', $dsn)) {
        fwrite(STDERR, "REFUSED: isolated MySQL test schema and disabled SMS required.\n");
        exit(2);
    }
    $db = new \PDO($dsn, getenv('PHILSMS_TEST_USER') ?: '',
        getenv('PHILSMS_TEST_PASSWORD') ?: '',
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    if (!preg_match('/\Aphilsms_test_[a-zA-Z0-9_]+\z/D',
        (string) $db->query('SELECT DATABASE()')->fetchColumn())) {
        throw new \RuntimeException('Refusing non-test database.');
    }
    Propel::$connection = $db;
    foreach (['001_notification_queue', '004_leader_registry',
              '005_leader_assignments', '006_assignment_activity'] as $migration) {
        $db->exec(file_get_contents(__DIR__ . '/../migrations/' . $migration . '.sql'));
    }
    require __DIR__ . '/../src/AssignmentService.php';
    foreach ([902, 9917, 17111, 17112, 18111, 18112] as $id) {
        PersonQuery::$people[$id] = new class($id) {
            public function __construct(private int $id) {}
            public function getId(): int { return $this->id; }
        };
    }
    FamilyQuery::$families[18210] = new class {
        public function getAdults(): array { return [PersonQuery::$people[18111]]; }
    };
    // The CI job runs multiple integration suites in one disposable schema.
    // Use a distinct fixture identity so earlier suites cannot collide.
    $db->exec('INSERT INTO philsms_leaders (person_id, created_by) VALUES (9917, 902)');
    $leaderId = (int) $db->lastInsertId();
    $insert = $db->prepare(
        'INSERT INTO philsms_notification_queue
         (event_type, event_entity_id, person_id, family_id, status)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute(['individual_approved', 17111, 17111, null, 'failed']);
    $individual = (int) $db->lastInsertId();
    $insert->execute(['family_approved', 18210, null, 18210, 'sent']);
    $family = (int) $db->lastInsertId();

    $app = new TestApp();
    require __DIR__ . '/../routes/routes.php';
    $route = $app->routes['POST /philsms/assignments/create'] ?? null;
    if ($route === null ||
        !in_array('ChurchCRM\\Slim\\Middleware\\Request\\Auth\\AdminRoleAuthMiddleware',
            $route->middleware, true)) {
        throw new \RuntimeException('Administrator middleware missing.');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    $_SESSION['philsms_csrf'] = bin2hex(random_bytes(24));
    Auth::$user = new class {
        public function isAdmin(): bool { return true; }
        public function getId(): int { return 902; }
    };
    $handler = $route->handler;
    $base = [
        'csrf' => $_SESSION['philsms_csrf'],
        'approval_queue_id' => (string) $individual,
        'leader_id' => (string) $leaderId,
        'contact_person_id' => '17111'
    ];
    function attempt(callable $handler, array $body, int $expected, string $label): array {
        $response = $handler(new TestRequest($body), new TestResponse());
        $json = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() !== $expected ||
            ($response->headers['Cache-Control'] ?? '') !== 'no-store') {
            throw new \RuntimeException('FAIL: ' . $label . ' HTTP ' . $response->getStatusCode());
        }
        echo 'PASS: ' . $label . "\n";
        return $json;
    }
    function rowCountFor(\PDO $db, string $table): int {
        return (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
    $queueBefore = $db->query(
        'SELECT * FROM philsms_notification_queue ORDER BY id'
    )->fetchAll(\PDO::FETCH_ASSOC);
    $assignmentsBefore = rowCountFor($db, 'philsms_leader_assignments');
    $activityBefore = rowCountFor($db, 'philsms_assignment_activity');
    attempt($handler, array_replace($base, ['csrf' => 'invalid']), 403, 'Invalid CSRF');
    attempt($handler, array_replace($base, ['contact_person_id' => '17112']), 422,
        'Invalid individual contact');
    Auth::$user = new class {
        public function isAdmin(): bool { return false; }
        public function getId(): int { return 902; }
    };
    attempt($handler, $base, 403, 'Non-admin rejected by service');
    Auth::$user = new class {
        public function isAdmin(): bool { return true; }
        public function getId(): int { return 902; }
    };
    if (rowCountFor($db, 'philsms_leader_assignments') !== $assignmentsBefore ||
        rowCountFor($db, 'philsms_assignment_activity') !== $activityBefore) {
        throw new \RuntimeException('Rejected requests created rows.');
    }
    echo "PASS: rejected requests do not write\n";

    $created = attempt($handler, $base, 201, 'Individual assigned through route');
    $id = (int) ($created['assignment_id'] ?? 0);
    if ($id <= 0) { throw new \RuntimeException('Assignment ID missing.'); }
    $duplicate = attempt($handler, $base, 409, 'Duplicate approval rejected');
    if (($duplicate['message'] ?? '') !== 'Approval event already assigned.' ||
        ($duplicate['success'] ?? null) !== false) {
        throw new \RuntimeException('Unexpected duplicate assignment response.');
    }
    if (rowCountFor($db, 'philsms_leader_assignments') !== $assignmentsBefore + 1 ||
        rowCountFor($db, 'philsms_assignment_activity') !== $activityBefore + 1) {
        throw new \RuntimeException('Duplicate request created additional records.');
    }
    echo "PASS: duplicate request creates no additional records\n";
    $familyCreated = attempt($handler, array_replace($base, [
        'approval_queue_id' => (string) $family,
        'contact_person_id' => '18111'
    ]), 201, 'Family adult assigned through route');
    if ((int) ($familyCreated['assignment_id'] ?? 0) <= $id) {
        throw new \RuntimeException('Family assignment missing.');
    }
    $rows = $db->query(
        'SELECT a.assigned_by, a.contact_person_id, a.status,
                h.actor_person_id, h.action, h.new_status
         FROM philsms_leader_assignments a
         JOIN philsms_assignment_activity h ON h.assignment_id = a.id
         WHERE a.approval_queue_id IN (' . (int) $individual . ', ' . (int) $family . ')
         ORDER BY a.id'
    )->fetchAll(\PDO::FETCH_ASSOC);
    if (count($rows) !== 2 ||
        (int) $rows[0]['contact_person_id'] !== 17111 ||
        (int) $rows[1]['contact_person_id'] !== 18111) {
        throw new \RuntimeException('Unexpected assignment records.');
    }
    foreach ($rows as $row) {
        if ((int) $row['assigned_by'] !== 902 ||
            (int) $row['actor_person_id'] !== 902 ||
            $row['status'] !== 'pending' ||
            $row['action'] !== 'assigned' ||
            $row['new_status'] !== 'pending') {
            throw new \RuntimeException('Invalid assignment audit record.');
        }
    }
    echo "PASS: transactional assignments and audit entries persisted\n";
    $queueAfter = $db->query(
        'SELECT * FROM philsms_notification_queue ORDER BY id'
    )->fetchAll(\PDO::FETCH_ASSOC);
    if ($queueBefore !== $queueAfter) {
        throw new \RuntimeException('Assignment modified the SMS notification queue.');
    }
    echo "PASS: SMS notification queue remains unchanged\n";
}
