<?php

/**
 * PHP version 8.2
 *
 * Development router for exercising Pusher-compatible browser clients.
 *
 * @category Examples
 * @package  Conveyor
 * @author   Savio Resende <savio@savioresende.com>
 * @license  https://opensource.org/licenses/MIT MIT
 * @link     https://github.com/kanata-php/socket-conveyor
 */

use Conveyor\SubProtocols\Pusher\PusherSigner;
use GuzzleHttp\Client;

require __DIR__ . '/../../vendor/autoload.php';

$appId = getenv('PUSHER_APP_ID') ?: 'local-app';
$appKey = getenv('PUSHER_APP_KEY') ?: 'local-key';
$appSecret = getenv('PUSHER_APP_SECRET') ?: 'local-secret';
$conveyorHost = getenv('CONVEYOR_HOST') ?: '127.0.0.1';
$conveyorPort = (int) (getenv('CONVEYOR_PORT') ?: 8990);

/**
 * Decode the current JSON request body.
 *
 * @return array<string, mixed>
 */
$jsonBody = static function (): array {
    $body = file_get_contents('php://input') ?: '{}';
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : [];
};

/**
 * Send a JSON response.
 *
 * @param array<string, mixed> $data
 */
$jsonResponse = static function (array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
};

/**
 * Trigger an event through the Conveyor REST endpoint.
 *
 * @return array{status: int, body: string, request: array<string, mixed>}
 */
$triggerEvent = static function (
    string $appId,
    string $appKey,
    string $appSecret,
    string $conveyorHost,
    int $conveyorPort,
    string $channel,
    string $event,
    ?string $socketId,
): array {
    $signer = new PusherSigner();
    $path = '/apps/' . rawurlencode($appId) . '/events';
    $body = json_encode(
        [
        'name' => $event,
        'channels' => [$channel],
        'data' => json_encode(
            [
            'message' => 'Hello from the real REST trigger',
            'channel' => $channel,
            'time' => date(DATE_ATOM),
            ],
            JSON_UNESCAPED_SLASHES
        ),
        'socket_id' => $socketId,
        ],
        JSON_UNESCAPED_SLASHES
    );

    $params = [
        'auth_key' => $appKey,
        'auth_timestamp' => (string) time(),
        'auth_version' => '1.0',
        'body_md5' => $signer->bodyMd5($body),
    ];
    $params['auth_signature'] = $signer->requestSignature(
        $appSecret,
        'POST',
        $path,
        $params,
    );

    $response = (new Client(['http_errors' => false]))->post(
        'http://' . $conveyorHost . ':' . $conveyorPort . $path,
        [
            'query' => $params,
            'body' => $body,
            'headers' => ['Content-Type' => 'application/json'],
        ],
    );

    return [
        'status' => $response->getStatusCode(),
        'body' => (string) $response->getBody(),
        'request' => [
            'channel' => $channel,
            'event' => $event,
            'socket_id' => $socketId,
        ],
    ];
};

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/web/index.html');
    return;
}

if ($path === '/echo.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/web/echo.html');
    return;
}

if ($path === '/config.js') {
    header('Content-Type: application/javascript');
    echo 'window.PUSHER_REAL_CONFIG = ' . json_encode(
        [
        'appKey' => $appKey,
        'wsHost' => $conveyorHost,
        'wsPort' => $conveyorPort,
        ],
        JSON_UNESCAPED_SLASHES
    ) . ';';
    return;
}

if ($path === '/broadcasting/auth') {
    $socketId = $_POST['socket_id'] ?? '';
    $channel = $_POST['channel_name'] ?? '';

    if (
        !is_string($socketId)
        || !is_string($channel)
        || $socketId === ''
        || $channel === ''
    ) {
        $jsonResponse(['error' => 'socket_id and channel_name are required'], 422);
        return;
    }

    $signer = new PusherSigner();
    $response = [
        'auth' => $signer->channelAuth($appKey, $appSecret, $socketId, $channel),
    ];

    if (str_starts_with($channel, 'presence-')) {
        $channelData = json_encode(
            [
            'user_id' => (string) ($_POST['user_id'] ?? random_int(1000, 9999)),
            'user_info' => [
                'name' => (string) ($_POST['name'] ?? 'Browser User'),
            ],
            ],
            JSON_UNESCAPED_SLASHES
        );

        $response['channel_data'] = $channelData;
        $response['auth'] = $signer->channelAuth(
            $appKey,
            $appSecret,
            $socketId,
            $channel,
            $channelData,
        );
    }

    $jsonResponse($response);
    return;
}

if ($path === '/trigger') {
    $payload = $jsonBody();
    $channel = is_string($payload['channel'] ?? null)
        ? $payload['channel']
        : 'public-demo';
    $event = is_string($payload['event'] ?? null) ? $payload['event'] : 'DemoEvent';
    $socketId = is_string($payload['socket_id'] ?? null)
        ? $payload['socket_id']
        : null;

    $result = $triggerEvent(
        appId: $appId,
        appKey: $appKey,
        appSecret: $appSecret,
        conveyorHost: $conveyorHost,
        conveyorPort: $conveyorPort,
        channel: $channel,
        event: $event,
        socketId: $socketId,
    );

    $jsonResponse($result, $result['status']);
    return;
}

$jsonResponse(['error' => 'Not found'], 404);
