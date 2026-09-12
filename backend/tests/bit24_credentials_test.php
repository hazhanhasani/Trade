<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$admin=(string)file_get_contents($root.'/public/admin/exchanges.php');
$store=(string)file_get_contents($root.'/src/MarketData/MarketDataCredentialStore.php');

function bit24Assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

bit24Assert(str_contains($admin,'<input type="hidden" name="source" value="bit24">'),'Bit24 source form is missing.');
bit24Assert(str_contains($admin,'<label>API Key بیت۲۴</label>'),'Bit24 API key field is missing.');
bit24Assert(str_contains($admin,'<label>کلید اختصاصی / Secret Key بیت۲۴</label>'),'Bit24 secret field is missing.');
bit24Assert(str_contains($admin,'name="api_key" required'),'Bit24 API key must be required.');
bit24Assert(str_contains($admin,'name="secret" required'),'Bit24 secret must be required.');
bit24Assert(str_contains($admin,'ذخیره امن هر دو کلید'),'Bit24 UI must save both credentials.');
bit24Assert(str_contains($store,"private const SECRET_REQUIRED = ['bit24', 'bitpin']")||str_contains($store,"private const SECRET_REQUIRED=['bit24','bitpin']"),'Bit24 secret must be enforced by the shared server-side policy.');
bit24Assert(substr_count($store,'$this->requiresSecret($source)')>=3,'Shared secret policy must guard save, configured state and status.');
bit24Assert(str_contains($store,"'requires_secret' => \$this->requiresSecret(\$source)")||str_contains($store,"'requires_secret'=>\$this->requiresSecret(\$source)"),'Bit24 status must expose its secret requirement.');

echo "Bit24 two-key credential regression tests passed.\n";
