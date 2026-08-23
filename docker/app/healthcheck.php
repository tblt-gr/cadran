<?php

declare(strict_types=1);

$context = stream_context_create([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
]);
$response = @file_get_contents('https://localhost:8443/api/v1/status', false, $context);

if (false === $response) {
    exit(1);
}

$payload = json_decode($response, true);

exit(['status' => 'ready', 'apiVersion' => 'v1'] === $payload ? 0 : 1);
