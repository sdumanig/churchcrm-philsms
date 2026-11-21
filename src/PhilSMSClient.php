<?php
namespace ChurchCRM\Plugins\PhilSMS;
final class PhilSMSClient {
 private string $token;
 public function __construct(string $token) { if ($token==='') throw new \RuntimeException('PhilSMS token not configured'); $this->token=$token; }
 public static function normalize(string $number): ?string {
  $n=preg_replace('/[^0-9+]/','',$number);
  if (str_starts_with($n,'+')) $n=substr($n,1);
  if (preg_match('/^09[0-9]{9}$/',$n)) $n='63'.substr($n,1);
  if (preg_match('/^9[0-9]{9}$/',$n)) $n='63'.$n;
  return preg_match('/^639[0-9]{9}$/',$n)?$n:null;
 }
 public function call(string $method,string $path,?array $payload=null): array {
  $url='https://dashboard.philsms.com/api/v3/'.$path;
  $ch=curl_init($url);
  $headers=['Authorization: Bearer '.$this->token,'Accept: application/json'];
  if ($payload!==null) $headers[]='Content-Type: application/json';
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false]);
  if ($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));
  $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $error=curl_error($ch); curl_close($ch);
  if ($body===false) throw new \RuntimeException('PhilSMS connection failed: '.$error);
  $data=json_decode($body,true);
  if ($status<200||$status>=300) throw new \RuntimeException('PhilSMS HTTP '.$status.': '.substr(is_array($data)?json_encode($data):$body,0,350));
  return is_array($data)?$data:['raw'=>$body];
 }
 public function balance(): array { return $this->call('GET','balance'); }
 public function send(string $number, string $message): array {
    $n = self::normalize($number);

    if (!$n) {
        throw new \InvalidArgumentException(
            'Invalid Philippine mobile number'
        );
    }

    if (trim($message) === '' || mb_strlen($message) > 1000) {
        throw new \InvalidArgumentException(
            'Message must be 1–1000 characters'
        );
    }

    $sender = trim((string) getenv('PHILSMS_SENDER_ID'));

    if ($sender === '' || strlen($sender) > 11) {
        throw new \RuntimeException(
            'Configure a valid PHILSMS_SENDER_ID before sending SMS'
        );
    }

    $payload = [
        'recipient' => $n,
        'sender_id' => $sender,
        'type' => preg_match('/[^\x00-\x7F]/', $message)
            ? 'unicode'
            : 'plain',
        'message' => $message
    ];

    $result = $this->call('POST', 'sms/send', $payload);

    if (($result['status'] ?? '') !== 'success') {
        throw new \RuntimeException(
            'PhilSMS rejected the message: ' .
            (string) ($result['message'] ?? 'Unknown error')
        );
    }

    return $result;
 }
}
