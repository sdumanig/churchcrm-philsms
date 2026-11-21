<?php
namespace ChurchCRM\Plugins\PhilSMS;
use ChurchCRM\Plugin\AbstractPlugin;
use ChurchCRM\Plugin\Hook\HookManager;
use ChurchCRM\Plugin\Hooks;
use ChurchCRM\Config\Menu\MenuItem;
use ChurchCRM\Authentication\AuthenticationManager;
final class PhilSMSPlugin extends AbstractPlugin {
 private static ?self $instance = null;
 public function __construct(string $basePath = '') { parent::__construct($basePath); self::$instance=$this; }
 public static function getInstance(): ?self { return self::$instance; }
 public function getId(): string { return 'philsms'; }
 public function getName(): string { return 'PhilSMS Messaging'; }
 public function getDescription(): string { return 'Send SMS to ChurchCRM members using PhilSMS'; }
 public function boot(): void { HookManager::addFilter(Hooks::MENU_BUILDING, [$this,'menu'], 10); }
 public function menu(array $menus): array {
  if (!$this->isEnabled() || !AuthenticationManager::getCurrentUser()->isAdmin()) return $menus;
  $menus['PhilSMS'] = new MenuItem('PhilSMS', 'plugins/philsms/manage', true, 'fa-comment-sms');
  return $menus;
 }
 public function activate(): void {} public function deactivate(): void {} public function uninstall(): void {}
 public function isConfigured(): bool { return getenv('PHILSMS_API_TOKEN') !== false && getenv('PHILSMS_API_TOKEN') !== ''; }
 public function getConfigurationError(): ?string { return $this->isConfigured()?null:'Set PHILSMS_API_TOKEN in the PHP-FPM/Apache environment'; }
}
