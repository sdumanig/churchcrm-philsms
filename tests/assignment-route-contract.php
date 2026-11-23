<?php

declare(strict_types=1);

/**
 * Offline contract checks for the RC3 assignment route.
 * These checks do not substitute for HTTP/middleware integration testing.
 */
$route = file_get_contents(__DIR__ . '/../routes/routes.php');
if ($route === false) {
    throw new RuntimeException('Unable to read routes.');
}
$start = strpos($route, "\$app->post('/philsms/assignments/create'");
if ($start === false) {
    throw new RuntimeException('Assignment creation route missing.');
}
$end = strpos($route, '})->add(AdminRoleAuthMiddleware::class);', $start);
if ($end === false) {
    throw new RuntimeException('Assignment route lacks administrator middleware.');
}
$body = substr($route, $start, $end - $start);
$checks = [
    'POST-only assignment creation route' => str_starts_with($body, "\$app->post("),
    'CSRF session check' => str_contains($body, 'session_status() !== PHP_SESSION_ACTIVE'),
    'CSRF token constant-time comparison' => str_contains($body, 'hash_equals('),
    'CSRF rejected with 403' => str_contains($body, 'return $json(403,'),
    'All assignment identifiers required' =>
        str_contains($body, "'approval_queue_id', 'leader_id', 'contact_person_id'"),
    'Positive integer validation' =>
        str_contains($body, 'FILTER_VALIDATE_INT') &&
        str_contains($body, "'min_range' => 1"),
    'Malformed request rejected with 422' => str_contains($body, 'return $json(422,'),
    'Service invoked after validation' =>
        str_contains($body, 'AssignmentService::assign('),
    'Duplicate event returns conflict' => str_contains($body, 'return $json(409,'),
    'No-store response' => str_contains($body, "'Cache-Control', 'no-store'"),
    'No SMS transport invoked' =>
        !str_contains($body, '->send(') &&
        !str_contains($body, 'NotificationDeliveryService'),
];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
echo "\nResults: " . count($checks) . " passed\n";
