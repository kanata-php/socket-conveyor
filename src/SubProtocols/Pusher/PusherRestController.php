<?php

namespace Conveyor\SubProtocols\Pusher;

use Conveyor\Constants;
use Hook\Action;
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
        private ?PusherAdmission $admission = null,
        private ?PusherLimits $limits = null,
    ) {
        $this->signer = new PusherSigner();
    }

    public function handle(Request $request, Response $response): void
    {
        try {
            $this->handleRequest($request, $response);
        } catch (\OverflowException $error) {
            PusherDiagnostics::record($error);
            $this->json($response, ['error' => 'Rate limit reached'], 429);
        } catch (\InvalidArgumentException | \JsonException $error) {
            PusherDiagnostics::record($error);
            $this->json($response, ['error' => 'Invalid request'], 400);
        } catch (\Throwable $error) {
            PusherEventRouter::diagnostic($error);
            $this->json($response, ['error' => 'Request could not be processed'], 500);
        }
    }

    private function handleRequest(Request $request, Response $response): void
    {
        if (strlen($request->getContent()) > ($this->limits ?? new PusherLimits())->get('http_body_bytes')) {
            $this->json($response, ['error' => 'Body too large'], 413);
            return;
        }
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

        if (isset($route['channel'])) {
            PusherValidation::channel($route['channel']);
        }
        match ($route['action']) {
            'events' => $this->handleEvents($request, $response, $app->key),
            'batch_events' => $this->handleBatchEvents($request, $response, $app->key),
            'channels' => $this->handleChannels($response, $app->key),
            'channel' => $this->handleChannel($response, $route['channel'], $app->key),
            'users' => $this->handleUsers($response, $route['channel'], $app->key),
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
        foreach ($params as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Invalid query parameter');
            }
        }
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

    private function handleEvents(Request $request, Response $response, string $appKey): void
    {
        $payload = $this->jsonBody($request);
        if ($payload === null) {
            $this->json($response, ['error' => 'Invalid JSON body'], 400);
            return;
        }

        $event = $this->validateEvent($payload);
        if ($event === null) {
            $this->json($response, ['error' => 'Invalid event'], 400);
            return;
        }

        // Acknowledge before fanout: Pusher REST gives no delivery guarantee.
        // The server's non-yielding Pusher pushes enforce backpressure; an early
        // response alone cannot prevent later publish coroutines from accumulating
        // behind a nonreading subscriber.
        $this->admission?->event($appKey);
        $this->json($response, []);
        $this->deliverEvent($event, $appKey);
    }

    private function handleBatchEvents(Request $request, Response $response, string $appKey): void
    {
        $payload = $this->jsonBody($request);
        if ($payload === null || !isset($payload['batch']) || !is_array($payload['batch'])) {
            $this->json($response, ['error' => 'Invalid JSON body'], 400);
            return;
        }

        if (
            count($payload['batch']) === 0 || !array_is_list($payload['batch'])
            || count($payload['batch']) > self::MAX_BATCH_EVENTS
        ) {
            $this->json($response, ['error' => 'Batch too large'], 400);
            return;
        }

        // Validate the whole batch before responding so an invalid event still
        // yields a 400, then respond and fan out (see handleEvents).
        $events = [];
        foreach ($payload['batch'] as $event) {
            if (!is_array($event) || ($validated = $this->validateEvent($event)) === null) {
                $this->json($response, ['error' => 'Invalid event'], 400);
                return;
            }
            $events[] = $validated;
        }

        $this->admission?->event($appKey, null, count($events));
        $this->json($response, []);

        foreach ($events as $validated) {
            $this->deliverEvent($validated, $appKey);
        }
    }

    /**
     * Validate and normalise a publish payload, returning the delivery-ready
     * event or null when the payload is invalid.
     *
     * @param array<array-key, mixed> $payload
     * @return array{
     *     name: string,
     *     channels: array<array-key, string>,
     *     data: string,
     *     socketId: ?string,
     *     payload: array<array-key, mixed>
     * }|null
     */
    private function validateEvent(array $payload): ?array
    {
        $name = PusherValidation::event($payload['name'] ?? null, true);
        $data = $payload['data'] ?? null;
        $socketId = $payload['socket_id'] ?? null;
        $channels = $this->channelsFromPayload($payload);

        if (
            !is_string($name)
            || $name === ''
            || !is_string($data)
            || strlen($data) > self::MAX_EVENT_DATA_BYTES
            || $channels === []
            || ($socketId !== null && !PusherValidation::socketId($socketId))
        ) {
            return null;
        }

        return [
            'name' => $name,
            'channels' => $channels,
            'data' => $data,
            'socketId' => $socketId,
            'payload' => $payload,
        ];
    }

    /**
     * Fan a validated event out to its channels. Safe to call after the HTTP
     * response has been sent.
     *
     * @param array{
     *     name: string,
     *     channels: array<array-key, string>,
     *     data: string,
     *     socketId: ?string,
     *     payload: array<array-key, mixed>
     * } $event
     */
    private function deliverEvent(array $event, string $appKey): void
    {
        Action::doAction(
            Constants::ACTION_PUSHER_REST_EVENT_RECEIVED,
            $event['payload'],
            $event['name'],
            $event['channels'],
            $event['data'],
            $event['socketId'],
        );

        foreach ($event['channels'] as $channel) {
            $this->router->deliver($event['name'], $channel, $event['data'], $event['socketId'], $appKey);
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<array-key, string>
     */
    private function channelsFromPayload(array $payload): array
    {
        $channels = array_key_exists('channels', $payload) ? $payload['channels'] : [$payload['channel'] ?? null];
        if (!is_array($channels) || !array_is_list($channels) || count($channels) === 0 || count($channels) > 100) {
            throw new \InvalidArgumentException('Invalid channel list');
        }
        foreach ($channels as $channel) {
            PusherValidation::channel($channel);
        }
        return array_values(array_unique($channels));
    }

    private function handleChannels(Response $response, string $appKey): void
    {
        $channels = [];
        foreach ($this->occupiedChannels($appKey) as $channel) {
            $channels[$channel] = ['occupied' => true];
        }

        $this->json($response, ['channels' => $channels]);
    }

    private function handleChannel(Response $response, string $channel, string $appKey): void
    {
        $subscribers = $this->router->subscribersOf($channel, $appKey);
        $payload = [
            'occupied' => $subscribers !== [],
            'subscription_count' => count($subscribers),
        ];

        if (str_starts_with($channel, 'presence-')) {
            $payload['user_count'] = $this->router->roster($channel, $appKey)['count'];
        }

        $this->json($response, $payload);
    }

    private function handleUsers(Response $response, string $channel, string $appKey): void
    {
        if (!str_starts_with($channel, 'presence-')) {
            $this->json($response, ['users' => []]);
            return;
        }

        $users = array_map(
            fn(string $id) => ['id' => $id],
            $this->router->roster($channel, $appKey)['ids'],
        );

        $this->json($response, ['users' => $users]);
    }

    /**
     * @return array<array-key, string>
     */
    private function occupiedChannels(string $appKey): array
    {
        $channels = [];
        foreach ($this->router->allSubscriptions($appKey) as $channel) {
            $channels[$channel] = true;
        }

        return array_keys($channels);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonBody(Request $request): ?array
    {
        // Convert objects recursively for the existing batch validation API.
        PusherValidation::object($request->getContent());
        return json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
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
