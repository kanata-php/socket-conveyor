<?php

namespace Conveyor\SubProtocols\Pusher;

use Conveyor\SubProtocols\Pusher\Frame\PusherEvent;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

class PusherRestController
{
    private const TIMESTAMP_TOLERANCE_SECONDS = 600;
    private const MAX_EVENT_DATA_BYTES = 10000;
    private const MAX_BATCH_EVENTS = 10;

    private PusherSigner $signer;

    public function __construct(
        private PusherEventRouter $router,
        private AppManager $appManager,
    ) {
        $this->signer = new PusherSigner();
    }

    public function handle(Request $request, Response $response): void
    {
        $method = strtoupper((string) ($request->server['request_method'] ?? ''));
        $path = $this->path($request);

        $route = $this->matchRoute($method, $path);
        if ($route === null) {
            $this->json($response, [], 404);
            return;
        }

        $app = $this->appManager->findByAppId($route['app_id']);
        if ($app === null || !$app->enabled) {
            $this->json($response, ['error' => 'Unknown app'], 401);
            return;
        }

        if (!$this->authenticate($request, $app, $method, $path, $response)) {
            return;
        }

        match ($route['action']) {
            'events' => $this->handleEvents($request, $response),
            'batch_events' => $this->handleBatchEvents($request, $response),
            'channels' => $this->handleChannels($response),
            'channel' => $this->handleChannel($response, $route['channel']),
            'users' => $this->handleUsers($response, $route['channel']),
            default => $this->json($response, [], 404),
        };
    }

    /**
     * @return array{action: string, app_id: string, channel?: string}|null
     */
    private function matchRoute(string $method, string $path): ?array
    {
        if ($method === 'POST' && preg_match('#^/apps/([^/]+)/events$#', $path, $matches) === 1) {
            return ['action' => 'events', 'app_id' => $matches[1]];
        }

        if ($method === 'POST' && preg_match('#^/apps/([^/]+)/batch_events$#', $path, $matches) === 1) {
            return ['action' => 'batch_events', 'app_id' => $matches[1]];
        }

        if ($method === 'GET' && preg_match('#^/apps/([^/]+)/channels$#', $path, $matches) === 1) {
            return ['action' => 'channels', 'app_id' => $matches[1]];
        }

        if ($method === 'GET' && preg_match('#^/apps/([^/]+)/channels/([^/]+)$#', $path, $matches) === 1) {
            return ['action' => 'channel', 'app_id' => $matches[1], 'channel' => urldecode($matches[2])];
        }

        if ($method === 'GET' && preg_match('#^/apps/([^/]+)/channels/([^/]+)/users$#', $path, $matches) === 1) {
            return ['action' => 'users', 'app_id' => $matches[1], 'channel' => urldecode($matches[2])];
        }

        return null;
    }

    private function authenticate(
        Request $request,
        PusherApp $app,
        string $method,
        string $path,
        Response $response,
    ): bool {
        /** @var array<array-key, mixed> $params */
        $params = $request->get ?? [];
        $authKey = $params['auth_key'] ?? null;
        $timestamp = $params['auth_timestamp'] ?? null;
        $version = $params['auth_version'] ?? null;
        $signature = $params['auth_signature'] ?? null;

        if (!is_string($authKey) || !hash_equals($app->key, $authKey)) {
            $this->json($response, ['error' => 'Unauthorized'], 401);
            return false;
        }

        if (!is_string($timestamp) || !ctype_digit($timestamp)) {
            $this->json($response, ['error' => 'Missing or invalid auth_timestamp'], 400);
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            $this->json($response, ['error' => 'Stale auth_timestamp'], 400);
            return false;
        }

        if ($version !== '1.0' || !is_string($signature)) {
            $this->json($response, ['error' => 'Unauthorized'], 401);
            return false;
        }

        $body = $request->getContent();
        if ($body !== '') {
            $bodyMd5 = $params['body_md5'] ?? null;
            if (!is_string($bodyMd5) || !hash_equals($this->signer->bodyMd5($body), $bodyMd5)) {
                $this->json($response, ['error' => 'Unauthorized'], 401);
                return false;
            }
        }

        if (!$this->signer->verifyRequest($app->secret, $method, $path, $params, $signature)) {
            $this->json($response, ['error' => 'Unauthorized'], 401);
            return false;
        }

        return true;
    }

    private function handleEvents(Request $request, Response $response): void
    {
        $payload = $this->jsonBody($request);
        if ($payload === null) {
            $this->json($response, ['error' => 'Invalid JSON body'], 400);
            return;
        }

        if (!$this->publishEvent($payload)) {
            $this->json($response, ['error' => 'Invalid event'], 400);
            return;
        }

        $this->json($response, []);
    }

    private function handleBatchEvents(Request $request, Response $response): void
    {
        $payload = $this->jsonBody($request);
        if ($payload === null || !isset($payload['batch']) || !is_array($payload['batch'])) {
            $this->json($response, ['error' => 'Invalid JSON body'], 400);
            return;
        }

        if (count($payload['batch']) > self::MAX_BATCH_EVENTS) {
            $this->json($response, ['error' => 'Batch too large'], 400);
            return;
        }

        foreach ($payload['batch'] as $event) {
            if (!is_array($event) || !$this->publishEvent($event)) {
                $this->json($response, ['error' => 'Invalid event'], 400);
                return;
            }
        }

        $this->json($response, []);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function publishEvent(array $payload): bool
    {
        $name = $payload['name'] ?? null;
        $data = $payload['data'] ?? null;
        $socketId = $payload['socket_id'] ?? null;
        $channels = $this->channelsFromPayload($payload);

        if (
            !is_string($name)
            || $name === ''
            || !is_string($data)
            || strlen($data) > self::MAX_EVENT_DATA_BYTES
            || $channels === []
            || ($socketId !== null && !is_string($socketId))
        ) {
            return false;
        }

        foreach ($channels as $channel) {
            $this->router->deliver($name, $channel, $data, $socketId);
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<array-key, string>
     */
    private function channelsFromPayload(array $payload): array
    {
        if (isset($payload['channels']) && is_array($payload['channels'])) {
            return array_values(array_filter(
                $payload['channels'],
                fn($channel) => is_string($channel) && $channel !== '',
            ));
        }

        $channel = $payload['channel'] ?? null;

        return is_string($channel) && $channel !== '' ? [$channel] : [];
    }

    private function handleChannels(Response $response): void
    {
        $channels = [];
        foreach ($this->occupiedChannels() as $channel) {
            $channels[$channel] = ['occupied' => true];
        }

        $this->json($response, ['channels' => $channels]);
    }

    private function handleChannel(Response $response, string $channel): void
    {
        $subscribers = $this->router->subscribersOf($channel);
        $payload = [
            'occupied' => $subscribers !== [],
            'subscription_count' => count($subscribers),
        ];

        if (str_starts_with($channel, 'presence-')) {
            $payload['user_count'] = $this->router->roster($channel)['count'];
        }

        $this->json($response, $payload);
    }

    private function handleUsers(Response $response, string $channel): void
    {
        if (!str_starts_with($channel, 'presence-')) {
            $this->json($response, ['users' => []]);
            return;
        }

        $users = array_map(
            fn(string $id) => ['id' => $id],
            $this->router->roster($channel)['ids'],
        );

        $this->json($response, ['users' => $users]);
    }

    /**
     * @return array<array-key, string>
     */
    private function occupiedChannels(): array
    {
        $channels = [];
        foreach ($this->router->allSubscriptions() as $channel) {
            $channels[$channel] = true;
        }

        return array_keys($channels);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonBody(Request $request): ?array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function path(Request $request): string
    {
        return (string) (
            $request->server['path_info']
            ?? parse_url((string) ($request->server['request_uri'] ?? ''), PHP_URL_PATH)
            ?? ''
        );
    }

    /**
     * @param array<array-key, mixed> $content
     */
    private function json(Response $response, array $content, int $status = 200): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json');
        $response->end($content === [] ? '{}' : json_encode($content));
    }
}
