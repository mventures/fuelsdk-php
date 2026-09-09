<?php

// Run with: php tests/soap-compatibility.php
// This check uses local files and needs no Salesforce credentials or Composer dependencies.
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

require __DIR__ . '/../src/ET_Client.php';
require __DIR__ . '/../src/ET_Util.php';

$reflection = new ReflectionClass(FuelSdk\ET_Client::class);
$client = $reflection->newInstanceWithoutConstructor();
$oauth = $reflection->getProperty('useOAuth2Authentication');
if (PHP_VERSION_ID < 80100) {
    $oauth->setAccessible(true);
}
$oauth->setValue($client, true);
$expiration = new DateTime('+1 hour');
foreach ([null, '', 'other-tenant'] as $tenant) {
    $token = 'offline-test-token-' . ($tenant ?? 'default');
    $client->setAuthToken($tenant, $token, $expiration);
    $client->setInternalAuthToken($tenant, $token . '-internal');
    $client->setRefreshToken($tenant, $token . '-refresh');
    if ($client->getAuthToken($tenant) !== $token
        || $client->getAuthTokenExpiration($tenant) !== $expiration
        || $client->getInternalAuthToken($tenant) !== $token . '-internal'
        || $client->getRefreshToken($tenant) !== $token . '-refresh'
    ) {
        throw new RuntimeException('Tenant tokens must round-trip for default and named tenants.');
    }
}
if ($client->getAuthToken(null) !== 'offline-test-token-') {
    throw new RuntimeException('Null and empty tenant keys must share the existing default token bucket.');
}

$request = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body/></soap:Envelope>';
$response = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><result>OK</result></soap:Body></soap:Envelope>';
$fixture = tempnam(sys_get_temp_dir(), 'fuelsdk-soap-');
file_put_contents($fixture, $response);

try {
    if ($client->__doRequest($request, 'file://' . $fixture, 'Retrieve', SOAP_1_1, false) !== $response) {
        throw new RuntimeException('The five-argument SOAP call must return the response unchanged.');
    }
    if ($client->__doRequest($request, 'file://' . $fixture, 'Retrieve', SOAP_1_1, false, null) !== $response) {
        throw new RuntimeException('The PHP 8.5 six-argument SOAP call must return the response unchanged.');
    }
    if ($client->__doRequest($request, 'file://' . $fixture, 'Retrieve', SOAP_1_1, true, null) !== null) {
        throw new RuntimeException('A one-way SOAP call must return null.');
    }

    try {
        $client->__doRequest($request, 'file://' . $fixture . '/missing', 'Retrieve', SOAP_1_1);
        throw new RuntimeException('A failed transport must throw a SOAP fault.');
    } catch (SoapFault $fault) {
        if ($fault->faultcode !== 'HTTP' || $fault->getMessage() === '') {
            throw new RuntimeException('A transport SOAP fault must retain the cURL error.');
        }
    }
} finally {
    unlink($fixture);
}

echo 'SOAP compatibility checks passed on PHP ' . PHP_VERSION . PHP_EOL;
