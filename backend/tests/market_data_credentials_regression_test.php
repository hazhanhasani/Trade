<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Security/Crypto.php';

use Trade\Security\Crypto;

function marketCredentialAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$key=Crypto::generateKey();
$emptyEncrypted=Crypto::encrypt('',$key);
marketCredentialAssert(Crypto::decrypt($emptyEncrypted,$key)==='','AES-GCM empty secret round-trip failed.');
marketCredentialAssert(strlen((string)base64_decode($emptyEncrypted,true))===28,'Empty AES-GCM envelope must be accepted at 28 bytes.');

$sample='market-data-secret';
marketCredentialAssert(Crypto::decrypt(Crypto::encrypt($sample,$key),$key)===$sample,'Non-empty credential round-trip failed.');

$root=dirname(__DIR__);
$store=(string)file_get_contents($root.'/src/MarketData/MarketDataCredentialStore.php');
$exchangeAdmin=(string)file_get_contents($root.'/public/admin/exchanges.php');
$dashboard=(string)file_get_contents($root.'/public/admin/index.php');
$hub=(string)file_get_contents($root.'/src/MarketData/MarketDataHub.php');

marketCredentialAssert(str_contains($store,"'abantether', 'bit24', 'tabdeal', 'bitpin'")||str_contains($store,"'abantether','bit24','tabdeal','bitpin'"),'Expected all four Iranian Market Data credential sources.');
marketCredentialAssert(str_contains($store,"private const SECRET_REQUIRED = ['bitpin']")||str_contains($store,"private const SECRET_REQUIRED=['bitpin']"),'Only Bitpin may require the optional paired Market Data secret; Bit24 GET Market Data must work with API token only.');
marketCredentialAssert(!str_contains($store,"SECRET_REQUIRED = ['bit24', 'bitpin']")&&!str_contains($store,"SECRET_REQUIRED=['bit24','bitpin']"),'Bit24 must not require a POST-signing secret for read-only GET Market Data.');
marketCredentialAssert(str_contains($store,"'execution_allowed' => false")||str_contains($store,"'execution_allowed'=>false"),'Market-data credential sources must never be execution-enabled.');
marketCredentialAssert(str_contains($exchangeAdmin,'name="source" value="abantether"')&&str_contains($exchangeAdmin,'name="source" value="bitpin"'),'AbanTether and Bitpin credential forms must be present.');
marketCredentialAssert(str_contains($exchangeAdmin,'API Key بیت‌پین')&&str_contains($exchangeAdmin,'Secret Key بیت‌پین'),'Bitpin Market Data credential fields are missing.');
marketCredentialAssert(!str_contains($dashboard,"$exchanges['bitpin']"),'Dashboard still indexes Bitpin as an execution exchange.');
marketCredentialAssert(str_contains($dashboard,"is_array($exchanges['nobitex'] ?? null)"),'Dashboard must guard missing Nobitex exchange status.');
marketCredentialAssert(!str_contains($hub,'/usr/authenticate/')&&!str_contains($hub,'/odr/orders/')&&!str_contains($hub,'/wlt/wallets/'),'MarketDataHub must stay free of Bitpin private/execution endpoints.');

// The Bit24 read-only order-book plan must use only X-BIT24-APIKEY.
marketCredentialAssert(str_contains($hub,'X-BIT24-APIKEY:'),'Bit24 Market Data token header is missing.');
marketCredentialAssert(!str_contains($hub,'X-BIT24-SIGNATURE')&&!str_contains($hub,'BIT24-PRIVATE'),'Bit24 Market Data GET must not use a private signing key.');

echo "Market data credential regression tests passed.\n";
