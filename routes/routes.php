<?php
use ChurchCRM\Plugins\PhilSMS\PhilSMSPlugin;
use ChurchCRM\Plugins\PhilSMS\PhilSMSClient;
use ChurchCRM\model\ChurchCRM\PersonQuery;
use ChurchCRM\Slim\Middleware\Request\Auth\AdminRoleAuthMiddleware;
use ChurchCRM\dto\SystemURLs;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;
$plugin=PhilSMSPlugin::getInstance(); if (!$plugin) return;
$app->get('/philsms/manage', function(
    Request $request,
    Response $response
): Response {
    $people = [];

    foreach (PersonQuery::create()->orderByLastName()->orderByFirstName()->find() as $person) {
        $phone = PhilSMSClient::normalize(
            (string) $person->getCellPhone()
        );

        if ($phone === null) {
            continue;
        }

        $people[] = [
            'id' => (int) $person->getId(),
            'name' => trim(
                (string) $person->getFirstName() . ' ' .
                (string) $person->getLastName()
            ),
            'phone' => $phone,
        ];
    }

     // Retrieve the current PhilSMS balance
    $credits = null;
    $creditsError = null;

    try {
        $client = new PhilSMSClient(
            (string) (getenv('PHILSMS_API_TOKEN') ?: '')
        );

        $balance = $client->balance();

        if (($balance['status'] ?? '') !== 'success') {
            throw new \RuntimeException(
                (string) ($balance['message'] ?? 'Balance lookup failed')
            );
        }

        $credits = $balance['data']['remaining_balance'] ?? null;

        if (!is_string($credits) || $credits === '') {
            throw new \RuntimeException('Balance unavailable');
        }

    } catch (\Throwable $e) {
        error_log('PhilSMS balance lookup failed: ' . $e->getMessage());
        $creditsError = 'Balance temporarily unavailable';
    }

    $renderer = new PhpRenderer(__DIR__ . '/../views/');

    return $renderer->render($response, 'manage.php', [
        'sRootPath' => SystemURLs::getRootPath(),
        'sPageTitle' => 'PhilSMS Messaging',
        'configured' => (bool) getenv('PHILSMS_API_TOKEN'),
        'people' => $people,
        'credits' => $credits,
        'creditsError' => $creditsError,
    ]);

})->add(AdminRoleAuthMiddleware::class);
$app->post('/philsms/send', function(Request $request,Response $response):Response {
 $data=(array)$request->getParsedBody();
 // Use the authenticated PHP session for request forgery protection.
 if (session_status()!==PHP_SESSION_ACTIVE) { return $response->withStatus(403); }
 if (!isset($_SESSION['philsms_csrf'])||!hash_equals($_SESSION['philsms_csrf'],(string)($data['csrf']??''))) return $response->withStatus(403);
 $out=[]; $message=trim((string)($data['message']??''));
 $ids=array_values(array_unique(array_filter(array_map('intval',preg_split('/[\s,]+/',(string)($data['person_ids']??''))))));
 if (count($ids)>25 || !$ids || mb_strlen($message)>1000 || $message==='') $out[]='Invalid request: provide 1–25 member IDs and message up to 1000 characters.';
 else {
  try {
   $client=new PhilSMSClient((string)(getenv('PHILSMS_API_TOKEN')?:''));
   $dry=(getenv('PHILSMS_LIVE_SEND')!=='1');
   foreach ($ids as $id) {
    $person=PersonQuery::create()->findPk($id);
    if (!$person) { $out[]="Member #$id not found"; continue; }
    $phone=PhilSMSClient::normalize((string)$person->getCellPhone());
    if (!$phone) { $out[]="Member #$id has no valid mobile number"; continue; }
    if ($dry) { $out[]="DRY RUN: member #$id -> $phone"; continue; }
    try { $result=$client->send($phone,$message); $out[]="Member #$id: submitted (".htmlspecialchars((string)($result['status']??'accepted'),ENT_QUOTES).")"; }
    catch (\Throwable $e) { $out[]="Member #$id: failed: ".$e->getMessage(); }
   }
  } catch (\Throwable $e) { $out[]=$e->getMessage(); }
 }
 $response->getBody()->write('<!doctype html><meta charset="utf-8"><title>PhilSMS result</title><h2>PhilSMS result</h2><p><a href="'.htmlspecialchars(SystemURLs::getRootPath().'/plugins/philsms/manage',ENT_QUOTES).'">Back to PhilSMS</a></p><pre>'.htmlspecialchars(implode("\n",$out),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</pre>');
 return $response->withHeader('Content-Type','text/html; charset=UTF-8')->withHeader('Cache-Control','no-store');
})->add(AdminRoleAuthMiddleware::class);
$app->get('/philsms/balance', function(Request $request,Response $response):Response {
 try { $result=(new PhilSMSClient((string)(getenv('PHILSMS_API_TOKEN')?:'')))->balance(); $response->getBody()->write('<pre>'.htmlspecialchars(json_encode($result,JSON_PRETTY_PRINT),ENT_QUOTES).'</pre>'); }
 catch (\Throwable $e) { $response->getBody()->write('Balance lookup failed: '.htmlspecialchars($e->getMessage(),ENT_QUOTES)); }
 return $response->withHeader('Content-Type','text/html; charset=UTF-8')->withHeader('Cache-Control','no-store');
})->add(AdminRoleAuthMiddleware::class);
