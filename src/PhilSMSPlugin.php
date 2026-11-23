<?php
namespace ChurchCRM\Plugins\PhilSMS;
use ChurchCRM\Plugin\AbstractPlugin;
use ChurchCRM\Plugin\Hook\HookManager;
use ChurchCRM\Plugin\Hooks;
use ChurchCRM\Config\Menu\MenuItem;
use ChurchCRM\Config\Menu\MenuCounter;
use ChurchCRM\Authentication\AuthenticationManager;
use ChurchCRM\Utils\LoggerUtils;
final class PhilSMSPlugin extends AbstractPlugin {
 private static ?self $instance = null;
 public function __construct(string $basePath = '') { parent::__construct($basePath); self::$instance=$this; }
 public static function getInstance(): ?self { return self::$instance; }
 public function getId(): string { return 'philsms'; }
 public function getName(): string { return 'PhilSMS Messaging'; }
 public function getDescription(): string { return 'Send SMS to ChurchCRM members using PhilSMS'; }
 public function boot(): void {
  HookManager::addFilter(Hooks::MENU_BUILDING, [$this, 'menu'], 10);
  HookManager::addAction(Hooks::PERSON_UPDATED, [$this, 'onPersonUpdated'], 10);
  HookManager::addAction(Hooks::FAMILY_UPDATED, [$this, 'onFamilyUpdated'], 10);
 }

 public function onPersonUpdated(\ChurchCRM\model\ChurchCRM\Person $person, array $oldData): void {
  if (
   (int) ($oldData['NeedsReview'] ?? 0) !== 1 ||
   $person->getNeedsReview() ||
   $person->getEnteredBy() !== \ChurchCRM\model\ChurchCRM\Person::SELF_REGISTER
  ) return;

  $inserted = NotificationQueue::enqueue(
   'individual_approved',
   (int) $person->getId(),
   (int) $person->getId(),
   $person->getFamId() !== null ? (int) $person->getFamId() : null,
   null,
   null,
   null
  );

  LoggerUtils::getAppLogger()->info(
   '[PhilSMS] Individual approval notification ' .
   ($inserted ? 'queued' : 'already exists'),
   ['person_id' => (int) $person->getId()]
  );
 }

 public function onFamilyUpdated(\ChurchCRM\model\ChurchCRM\Family $family, array $oldData): void {
  if (
   (int) ($oldData['NeedsReview'] ?? 0) !== 1 ||
   $family->getNeedsReview() ||
   $family->getEnteredBy() !== \ChurchCRM\model\ChurchCRM\Person::SELF_REGISTER
  ) return;

  $inserted = NotificationQueue::enqueue(
   'family_approved',
   (int) $family->getId(),
   null,
   (int) $family->getId(),
   null,
   null,
   null
  );

  LoggerUtils::getAppLogger()->info(
   '[PhilSMS] Family approval notification ' .
   ($inserted ? 'queued' : 'already exists'),
   ['family_id' => (int) $family->getId()]
  );
 }
 public function menu(array $menus): array {
  if (!$this->isEnabled() || !AuthenticationManager::getCurrentUser()->isAdmin()) return $menus;

  $menuItem = new MenuItem(
   'PhilSMS',
   'plugins/philsms/manage',
   true,
   'fa-comment-sms'
  );

  try {
   $pendingCount = PendingApprovalCounter::count();

   if ($pendingCount > 0) {
    $menuItem->addCounter(new MenuCounter(
     'philsmsPendingApprovals',
     'bg-warning text-dark',
     $pendingCount,
     'ChurchCRM registrations awaiting approval'
    ));
   }
  } catch (\Throwable $e) {
   LoggerUtils::getAppLogger()->warning(
    '[PhilSMS] Sidebar pending approval count unavailable',
    ['exception' => get_class($e)]
   );
  }

  $menus['PhilSMS'] = $menuItem;
  return $menus;
 }
 public function testWithSettings(array $settings): array {
  $token = trim((string) ($settings['apiKey'] ?? ''));

  if ($token === '') {
   $token = trim($this->getConfigValue('apiKey'));
  }

  if ($token === '') {
   $token = trim((string) getenv('PHILSMS_API_TOKEN'));
  }

  if ($token === '') {
   return [
    'success' => false,
    'message' => 'PhilSMS API key is not configured.'
   ];
  }

  try {
   $client = new PhilSMSClient($token);
   $client->balance();

   return [
    'success' => true,
    'message' => 'PhilSMS API connection successful. Sender ID approval has not been verified.'
   ];
  } catch (\Throwable $e) {
   return [
    'success' => false,
    'message' => 'Unable to verify PhilSMS API credentials. Check the API key and connection.'
   ];
  }
 }

 public function activate(): void {
  // Activation is schema-neutral. Operators must explicitly initialize RC2
  // and apply RC3 migrations through the isolated, guarded CLI workflows.
  // Never perform implicit DDL or send SMS from the plugin lifecycle.
 }

 public function deactivate(): void {}

 public function uninstall(): void {
  // Preserve notification history on plugin removal.
 }
 public function getApiToken(): string {
  $saved = trim($this->getConfigValue('apiKey'));

  return $saved !== ''
   ? $saved
   : trim((string) getenv('PHILSMS_API_TOKEN'));
 }

 public function getSenderId(): string {
  $saved = trim($this->getConfigValue('senderId'));

  return $saved !== ''
   ? $saved
   : trim((string) getenv('PHILSMS_SENDER_ID'));
 }

 public function getSafeSettingsSummary(): array {
  return [
   'apiKeyConfigured' => $this->isConfigured(),
   'senderId' => $this->getSenderId(),
   'liveSendingRequested' =>
    $this->getConfigValue('liveSending') === '1',
   'serverLiveSendingAllowed' =>
    getenv('PHILSMS_LIVE_SEND') === '1',
   'effectiveLiveSending' =>
    $this->isLiveSendingEnabled(),
  ];
 }

 public function isLiveSendingEnabled(): bool {
  return getenv('PHILSMS_LIVE_SEND') === '1'
   && $this->getConfigValue('liveSending') === '1';
 }

 public function isConfigured(): bool {
  return trim($this->getConfigValue('apiKey')) !== ''
   || trim((string) getenv('PHILSMS_API_TOKEN')) !== '';
 }

 public function getConfigurationError(): ?string {
  return $this->isConfigured()
   ? null
   : 'Configure the PhilSMS API Key in Plugin Management or set PHILSMS_API_TOKEN.';
 }
}
