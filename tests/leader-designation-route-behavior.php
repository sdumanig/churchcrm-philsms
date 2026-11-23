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
namespace ChurchCRM\Plugins\PhilSMS {
    final class PhilSMSPlugin {
        public static function getInstance(): object { return new \stdClass(); }
    }
    final class LeaderRepository {
        public static int $calls = 0;
        public static function designate(int $personId): void {
            self::$calls++;
            if ($personId === 997) throw new \InvalidArgumentException('Unknown person');
            if ($personId === 998) throw new \RuntimeException('Maximum of seven active designated leaders reached.');
            if ($personId === 999) throw new \RuntimeException('Unable to acquire leader designation lock.');
            if ($personId === 996) throw new \RuntimeException('Administrator access required.');
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
    $route = $app->routes['POST /philsms/leaders/designate'] ?? null;
    if ($route === null || !in_array(
        'ChurchCRM\\Slim\\Middleware\\Request\\Auth\\AdminRoleAuthMiddleware',
        $route->middleware, true
    )) throw new \RuntimeException('Administrator middleware missing.');

    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['philsms_csrf'] = str_repeat('a', 48);
    $valid = ['csrf' => $_SESSION['philsms_csrf'], 'person_id' => '123'];
    $cases = [
        ['Missing CSRF', array_diff_key($valid, ['csrf' => true]), 403, false],
        ['Incorrect CSRF', array_replace($valid, ['csrf' => 'bad']), 403, false],
        ['Malformed body', null, 422, false],
        ['Missing person', array_diff_key($valid, ['person_id' => true]), 422, false],
        ['Zero ID', array_replace($valid, ['person_id' => '0']), 422, false],
        ['Non-numeric ID', array_replace($valid, ['person_id' => 'abc']), 422, false],
        ['Array injection', array_replace($valid, ['person_id' => ['123']]), 422, false],
        ['Unknown person', array_replace($valid, ['person_id' => '997']), 422, true],
        ['Capacity reached', array_replace($valid, ['person_id' => '998']), 409, true],
        ['Lock unavailable', array_replace($valid, ['person_id' => '999']), 503, true],
        ['Unauthorized repository', array_replace($valid, ['person_id' => '996']), 403, true],
        ['Valid designation', $valid, 200, true],
    ];
    foreach ($cases as [$label, $data, $status, $shouldCall]) {
        $before = \ChurchCRM\Plugins\PhilSMS\LeaderRepository::$calls;
        $response = ($route->handler)(new FakeRequest($data), new FakeResponse());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() !== $status ||
            !isset($payload['success']) ||
            $payload['success'] !== ($status === 200) ||
            ($response->headers['Cache-Control'] ?? null) !== 'no-store' ||
            ($response->headers['Content-Type'] ?? null) !== 'application/json; charset=UTF-8' ||
            \ChurchCRM\Plugins\PhilSMS\LeaderRepository::$calls !== $before + ($shouldCall ? 1 : 0)) {
            throw new \RuntimeException('FAIL: ' . $label);
        }
        echo 'PASS: ' . $label . "\n";
    }
    echo 'Results: ' . count($cases) . " passed\n";
}
