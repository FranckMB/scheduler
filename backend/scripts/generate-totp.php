<?php
declare(strict_types=1);
// Generate encrypted TOTP secret and current code
require_once __DIR__ . '/../vendor/autoload.php';

use App\Security\TotpService;

$appSecret = $_ENV['APP_SECRET'] ?? 'test';
$service = new TotpService($appSecret);

$rawSecret = 'JBSWY3DPEHPK3PXP';
$encrypted = $service->encrypt($rawSecret);
$code = $service->code($rawSecret, time());

echo 'ENCRYPTED_SECRET=' . $encrypted . "\n";
echo 'TOTP_CODE=' . $code . "\n";
