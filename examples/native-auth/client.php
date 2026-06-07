<?php

use Conveyor\SubProtocols\Conveyor\Actions\ChannelConnectAction;
use GuzzleHttp\Client as HttpClient;
use WebSocket\Client as WsClient;

require __DIR__ . '/../../vendor/autoload.php';

$host = getenv('CONVEYOR_HOST') ?: '127.0.0.1';
$port = (int) (getenv('CONVEYOR_PORT') ?: 8989);
$serverToken = getenv('CONVEYOR_SERVER_TOKEN') ?: 'local-server-token';
$channel = 'orders.1';

$http = new HttpClient(['http_errors' => false]);

$authResponse = $http->post(
    "http://{$host}:{$port}/conveyor/auth?token={$serverToken}",
    [
        'body' => json_encode(['channel' => $channel]),
        'headers' => ['Content-Type' => 'application/json'],
    ],
);

if ($authResponse->getStatusCode() !== 200) {
    fwrite(STDERR, "Auth request failed: {$authResponse->getBody()}" . PHP_EOL);
    exit(1);
}

$authBody = json_decode((string) $authResponse->getBody(), true);
$temporaryToken = $authBody['auth'] ?? null;

if (!is_string($temporaryToken) || $temporaryToken === '') {
    fwrite(STDERR, 'Auth response did not include a token.' . PHP_EOL);
    exit(1);
}

$socket = new WsClient("ws://{$host}:{$port}/?token=" . urlencode($temporaryToken), ['timeout' => 5]);
echo 'Connected: ' . $socket->receive() . PHP_EOL;

$socket->send(json_encode([
    'action' => ChannelConnectAction::NAME,
    'channel' => $channel,
    'auth' => $temporaryToken,
]));

sleep(1);

$messageResponse = $http->post(
    "http://{$host}:{$port}/conveyor/message?token={$serverToken}",
    [
        'body' => json_encode([
            'channel' => $channel,
            'message' => 'order updated',
        ]),
        'headers' => ['Content-Type' => 'application/json'],
    ],
);

if ($messageResponse->getStatusCode() !== 200) {
    fwrite(STDERR, "Message request failed: {$messageResponse->getBody()}" . PHP_EOL);
    exit(1);
}

echo 'Received: ' . $socket->receive() . PHP_EOL;

$socket->close();
