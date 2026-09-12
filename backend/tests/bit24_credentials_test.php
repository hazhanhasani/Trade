<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$admin=(string)file_get_contents($root.'/public/admin/exchanges.php');
$store=(string)file_get_contents($root.'/src/MarketData/MarketDataCredentialStore.php');
$hub=(string)file_get_contents($root.'/src/MarketData/MarketDataHub.php');

function bit24Assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

bit24Assert(str_contains($admin,'<input type="hidden" name="source" value="bit24">'),'Bit24 source form is missing.');
bit24Assert(str_contains($admin,'<label>API Key بیت۲۴</label>'),'Bit24 API key field is missing.');
bit24Assert(str_contains($admin,'name="api_key" required'),'Bit24 API key must be required.');
bit24Assert(!str_contains($admin,'<label>کلید اختصاصی / Secret Key بیت۲۴</label>'),'Bit24 read-only Market Data form must not require a POST signing secret.');
bit24Assert(!str_contains($admin,'name="secret" required autocomplete="new-password" style="direction:ltr"></div></div><div class="actions"><button class="btn">ذخیره امن هر دو کلید</button>'),'Bit24 UI still requires two credentials.');
bit24Assert(str_contains($admin,'ذخیره امن API Key'),'Bit24 UI must clearly save the Market Data API key only.');
bit24Assert(str_contains($store,"private const SECRET_REQUIRED = ['bitpin']")||str_contains($store,"private const SECRET_REQUIRED=['bitpin']"),'Bit24 must not be in the shared secret-required policy.');
bit24Assert(substr_count($store,'$this->requiresSecret($source)')>=3,'Shared secret policy must still guard save, configured state and status for sources that actually require it.');
bit24Assert(str_contains($store,"'requires_secret' => \$this->requiresSecret(\$source)")||str_contains($store,"'requires_secret'=>\$this->requiresSecret(\$source)"),'Credential status must expose source-specific secret requirements.');
bit24Assert(str_contains($hub,'X-BIT24-APIKEY: '),'Bit24 read-only request must authenticate with X-BIT24-APIKEY.');
bit24Assert(!str_contains($hub,'X-BIT24-SIGNATURE')&&!str_contains($hub,'BIT24-PRIVATE'),'Bit24 Market Data GET must not use the private signing key.');

echo "Bit24 API-key-only Market Data credential regression tests passed.\n";
