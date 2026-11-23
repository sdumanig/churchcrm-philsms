<?php
declare(strict_types=1);

namespace Psr\Http\Message {
    interface ServerRequestInterface {
        public function getParsedBody();
    }
    interface ResponseInterface {
        public function getBody();
        public function withStatus($code);
        public function withHeader($name, $value);
        public function getStatusCode();
    }
}
namespace ChurchCRM\Plugins\PhilSMS {
    final class PhilSMSPlugin {
        public static function getInstance(): object { return new \stdClass(); }
    }
    final class AssignmentService {
        public static int $calls = 0;
        public static function assign(int $approval, int $leader, int $contact): int {
            self::$calls++;
            if ($approval === 999) {
                throw new \RuntimeException('Approval event already assigned.');
            }
            if ($approval === 998) {
                throw new \InvalidArgumentException('Invalid contact.');
            }
            return 42;
        }
    }
}
namespace {
    final class FakeBody {
        public string $text = '';
        public function write(string $text): void { $this->text .= $text; }
        public function __toString(): string { return $this->text; }
    }
    final class FakeRequest implements \Psr\Http\Message\ServerRequestInterface {
        public function __construct(private mixed $data) {}
        public function getParsedBody(): mixed { return $this->data; }
    }
    final class FakeResponse implements \Psr\Http\Message\ResponseInterface {
        private FakeBody $body;
        private int $status = 200;
        public array $headers = [];
        public function __construct() { $this->body = new FakeBody(); }
        public function getBody(): FakeBody { return $this->body; }
        public function withStatus($code): self { $copy = clone $this; $copy->status = $code; return $copy; }
        public function withHeader($name, $value): self {
            $copy = clone $this; $copy->headers[$name] = $value; return $copy;
        }
        public function getStatusCode(): int { return $this->status; }
    }
    final class FakeRoute {
        public array $middleware = [];
        public function __construct(public $handler) {}
        public function add($middleware): self { $this->middleware[] = $middleware; return $this; }
    }
    final class FakeApp {
        public array $routes = [];
        public function get(string $path, callable $handler): FakeRoute {
            return $this->routes['GET ' . $path] = new FakeRoute($handler);
        }
        public function post(string $path, callable $handler): FakeRoute {
            return $this->routes['POST ' . $path] = new FakeRoute($handler);
        }
    }
    $app = new FakeApp();
    require __DIR__ . '/../routes/routes.php';
    $route = $app->routes['POST /philsms/assignments/create'] ?? null;
    if ($route === null || !in_array(
        'ChurchCRM\\Slim\\Middleware\\Request\\Auth\\AdminRoleAuthMiddleware',
        $route->middleware, true
    )) {
        throw new \RuntimeException('Administrator middleware missing.');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['philsms_csrf'] = str_repeat('a', 48);
    $valid = [
        'csrf' => $_SESSION['philsms_csrf'],
        'approval_queue_id' => '101',
        'leader_id' => '202',
        'contact_person_id' => '303'
    ];
    $handler = $route->handler;
    $cases = [
        ['Missing CSRF', array_diff_key($valid, ['csrf' => true]), 403],
        ['Incorrect CSRF', array_replace($valid, ['csrf' => 'invalid']), 403],
        ['Malformed body', null, 422],
        ['Missing leader', array_diff_key($valid, ['leader_id' => true]), 422],
        ['Zero contact', array_replace($valid, ['contact_person_id' => '0']), 422],
        ['Non-numeric ID', array_replace($valid, ['leader_id' => 'abc']), 422],
        ['Array injection', array_replace($valid, ['leader_id' => ['202']]), 422],
        ['Duplicate approval', array_replace($valid, ['approval_queue_id' => '999']), 409],
        ['Ineligible contact', array_replace($valid, ['approval_queue_id' => '998']), 422],
        ['Valid request', $valid, 201],
    ];
    foreach ($cases as [$label, $data, $expected]) {
        $before = \ChurchCRM\Plugins\PhilSMS\AssignmentService::$calls;
        $result = $handler(new FakeRequest($data), new FakeResponse());
        $payload = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if ($result->getStatusCode() !== $expected ||
            !isset($payload['success']) ||
            ($result->headers['Cache-Control'] ?? null) !== 'no-store') {
            throw new \RuntimeException('FAIL: ' . $label);
        }
        if ($expected === 201 && ($payload['assignment_id'] ?? null) !== 42) {
            throw new \RuntimeException('FAIL: success response');
        }
        if (in_array($expected, [403], true) && \ChurchCRM\Plugins\PhilSMS\AssignmentService::$calls !== $before) {
            throw new \RuntimeException('FAIL: service called before CSRF validation');
        }
        echo 'PASS: ' . $label . "\n";
    }
    echo 'Results: ' . count($cases) . " passed\n";
}
