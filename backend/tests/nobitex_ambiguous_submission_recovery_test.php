<?php

declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

use Trade\Exchange\NobitexClient;

function ambiguousAssert(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$root=dirname(__DIR__);
$service=(string)file_get_contents($root.'/src/Trading/NobitexOrderService.php');
$client=(string)file_get_contents($root.'/src/Exchange/NobitexClient.php');

ambiguousAssert(NobitexClient::isTransientNetworkError('Nobitex network error (6): Could not resolve host'),'DNS failure must be considered an ambiguous transport failure.');
ambiguousAssert(NobitexClient::isTransientNetworkError('Nobitex network error (7): Failed to connect'),'Connect failure must be considered an ambiguous transport failure.');
ambiguousAssert(NobitexClient::isTransientNetworkError('Nobitex network error (28): Operation timed out'),'Timeout must be considered an ambiguous transport failure.');
ambiguousAssert(!NobitexClient::isTransientNetworkError('Nobitex HTTP 400 [InvalidOrder]'),'Exchange validation errors must not be treated as ambiguous transport failures.');

ambiguousAssert(str_contains($service,'NobitexClient::isTransientNetworkError($e->getMessage())'),'Order service must recognize ambiguous transport failures.');
ambiguousAssert(str_contains($service,'recoverAmbiguousSubmission($client,$clientOrderId)'),'Order service must attempt idempotent recovery using the original clientOrderId.');
ambiguousAssert(str_contains($service,'$client->orderStatus(null,$clientOrderId)'),'Recovery must query order status by exact clientOrderId.');
ambiguousAssert(str_contains($service,"'nobitex.order_ambiguous_recovered'"),'Recovered ambiguous submissions must be auditable.');
ambiguousAssert(str_contains($service,"'ambiguous_submission_recovered'=>"),'Recovered state must be exposed to portfolio orchestration.');

$methodStart=strpos($service,'private function recoverAmbiguousSubmission');
$methodEnd=$methodStart===false?false:strpos($service,'private function isRecoveredRemoteOrder',$methodStart);
ambiguousAssert($methodStart!==false&&$methodEnd!==false,'Ambiguous recovery helper is missing.');
$recoveryBody=substr($service,$methodStart,$methodEnd-$methodStart);
ambiguousAssert(!str_contains($recoveryBody,'createOrder('),'Recovery must NEVER repeat the non-idempotent create-order POST.');
ambiguousAssert(str_contains($recoveryBody,'orderStatus(null,$clientOrderId)'),'Recovery helper must use only the idempotent status lookup.');

// Keep the client-level contract explicit: authenticated reads may retry, while
// createOrder itself directly calls request() and is never wrapped in authenticatedRead().
$createStart=strpos($client,'public function createOrder');
$cancelStart=$createStart===false?false:strpos($client,'public function cancelOrder',$createStart);
ambiguousAssert($createStart!==false&&$cancelStart!==false,'Nobitex createOrder source block is missing.');
$createBody=substr($client,$createStart,$cancelStart-$createStart);
ambiguousAssert(str_contains($createBody,"return $this->request('POST','/market/orders/add'")||str_contains($createBody,"return \$this->request('POST','/market/orders/add'"),'createOrder must submit exactly through the add endpoint.');
ambiguousAssert(!str_contains($createBody,'authenticatedRead('),'createOrder must not be automatically retried by the HTTP client.');

echo "Nobitex ambiguous submission recovery regression tests passed.\n";
