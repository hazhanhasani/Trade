<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$admin = file_get_contents($root . '/public/admin/exchanges.php');
$store = file_get_contents($root . '/src/MarketData/MarketDataCredentialStore.php');

if (!is_string($admin) || !is_string($store)) {
    throw new RuntimeException('Unable to inspect Bit24 credential integration.');
}

function bit24Assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

bit24Assert(str_contains($admin, '<input type="hidden" name="source" value="bit24">'), 'Bit24 source form is missing.');
bit24Assert(str_contains($admin, '<label>API Key بیت۲۴</label>'), 'Bit24 API key field is missing.');
bit24Assert(str_contains($admin, '<label>کلید اختصاصی / Secret Key بیت۲۴</label>'), 'Bit24 secret/private key field is missing.');
bit24Assert(str_contains($admin, 'name="api_key" required'), 'Bit24 API key must be required.');
bit24Assert(str_contains($admin, 'name="secret" required'), 'Bit24 secret/private key must be required.');
bit24Assert(str_contains($admin, 'ذخیره امن هر دو کلید'), 'Bit24 UI must explicitly save both credentials.');

bit24Assert(str_contains($store, "$source === 'bit24' && $secret === ''"), 'Bit24 secret must be enforced server-side.');
bit24Assert(str_contains($store, "$source === 'bit24' && trim((string)($credentials['secret'] ?? '')) === ''"), 'Bit24 configured status must require a non-empty secret.');
bit24Assert(str_contains($store, "'requires_secret'=>$source === 'bit24'"), 'Bit24 status must expose its secret requirement.');

echo "Bit24 two-key credential regression tests passed.\n";
