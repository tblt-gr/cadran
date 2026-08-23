<?php

declare(strict_types=1);

$serverName = getenv('CADRAN_SERVER_NAME') ?: 'localhost';

if (1 !== preg_match('/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/i', $serverName)) {
    exit(1);
}

$context = stream_context_create([
    'http' => [
        'header' => "Host: {$serverName}\r\n",
    ],
    'ssl' => [
        'peer_name' => $serverName,
        'SNI_enabled' => true,
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
]);
$response = @file_get_contents('https://127.0.0.1:8443/api/v1/status', false, $context);

if (false === $response) {
    exit(1);
}

$payload = json_decode($response, true);

exit(['status' => 'ready', 'apiVersion' => 'v1'] === $payload ? 0 : 1);
