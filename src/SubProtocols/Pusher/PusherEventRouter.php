<?php

namespace Conveyor\SubProtocols\Pusher;

use Conveyor\Constants;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\PresenceChannelPersistenceInterface;
use Conveyor\SubProtocols\Pusher\Frame\PusherEvent;
use Conveyor\SubProtocols\Pusher\Frame\PusherFrame;
use Hook\Action;
use OpenSwoole\WebSocket\Server;

class PusherEventRouter
{
    private PusherSigner $signer;

    /** @param array<array-key, GenericPersistenceInterface> $persistence */
    public function __construct(
        private Server $server,
        private array $persistence,
        private SocketIdRepository $socketIds,
        private PusherChannelRepository $channels,
        private AppManager $appManager,
        private ?PusherAdmission $admission = null,
    ) {
        $this->signer = new PusherSigner();
    }

    public function handle(int $fd, string $raw, ?string $generation = null): void
    {
        try {
            if (strlen($raw) > 65536) {
                throw new \InvalidArgumentException('Frame too large');
            }
            $generation ??= $this->socketIds->socketIdFor($fd);
            $app = $this->boundApp($fd, $generation);
            $frame = PusherValidation::object($raw);
            $event = PusherValidation::event($frame['event'] ?? null);
            Action::doAction(Constants::ACTION_PUSHER_MESSAGE_RECEIVED, $fd, $raw, $frame, $this->server);
            $this->boundApp($fd, $generation);
            if ($event === PusherEvent::PING) {
                $this->push($fd, PusherFrame::pong(), $generation);
                return;
            }
            if (str_starts_with($event, 'client-')) {
                $channel = PusherValidation::channel($frame['channel'] ?? null);
                $data = PusherValidation::data(array_key_exists('data', $frame) ? $frame['data'] : '{}');
                if (
                    !$app->enableClientMessages || (!str_starts_with($channel, 'private-')
                    && !str_starts_with($channel, 'presence-'))
                    || !$this->channels->isSubscribed($fd, $channel, $app->key)
                ) {
                    throw new \InvalidArgumentException('Client event not authorized');
                }
                $this->admission?->event($app->key, $generation);
                $this->deliver($event, $channel, $data, $generation, $app->key);
                return;
            }
            if ($event !== PusherEvent::SUBSCRIBE && $event !== PusherEvent::UNSUBSCRIBE) {
                return;
            }
            $input = $frame['data'] ?? null;
            $data = is_string($input) ? PusherValidation::object($input)
                : ($input instanceof \stdClass ? (array) $input : null);
            if ($data === null) {
                throw new \InvalidArgumentException('Invalid subscription data');
            }
            $channel = PusherValidation::channel($data['channel'] ?? null);
            if ($event === PusherEvent::UNSUBSCRIBE) {
                $this->unsubscribe($fd, $channel, $app, $generation);
                return;
            }
            $this->subscribe($fd, $channel, $data, $app, $generation);
        } catch (\InvalidArgumentException | \JsonException | \OverflowException $error) {
            PusherDiagnostics::record($error);
            $this->push(
                $fd,
                PusherFrame::error(PusherEvent::ERROR_GENERIC, 'Invalid request or capacity reached'),
                $generation,
            );
        } catch (\Throwable $error) {
            self::diagnostic($error);
            $this->push(
                $fd,
                PusherFrame::error(PusherEvent::ERROR_GENERIC, 'Request could not be processed'),
                $generation,
            );
        }
    }

    public function handleClose(int $fd, ?string $generation = null): void
    {
        $generation ??= $this->socketIds->socketIdFor($fd);
        if ($generation === null) {
            return;
        }
        try {
            $notifications = $this->channels->transaction(function () use ($fd, $generation): array {
                if ($this->socketIds->socketIdFor($fd) !== $generation) {
                    return [];
                }
                try {
                    $key = $this->socketIds->appKeyFor($fd);
                    $removed = [];
                    if ($key !== null) {
                        foreach (array_unique($this->channels->allSubscriptions($key)) as $channel) {
                            if (!$this->channels->isSubscribed($fd, $channel, $key)) {
                                continue;
                            }
                            $id = null;
                            if (str_starts_with($channel, 'presence-')) {
                                $scope = PusherValidation::scope($key, $channel);
                                $raw = $this->presence()->getMembers($scope)[$fd] ?? null;
                                $id = $raw === null ? null : (string) PusherValidation::presence($raw)['user_id'];
                                try {
                                    $this->channels->mark($fd, $channel, $key, 2);
                                    $this->presence()->remove($fd, $scope);
                                    if (isset($this->presence()->getMembers($scope)[$fd])) {
                                        throw new \RuntimeException('Presence removal failed');
                                    }
                                } catch (\Throwable $error) {
                                    self::diagnostic($error);
                                    // Keep the removal journal charged and hidden until recovery succeeds.
                                    continue;
                                }
                            }
                            $this->channels->remove($fd, $channel, $key);
                            if ($id !== null && !in_array($id, $this->roster($channel, $key)['ids'], true)) {
                                $removed[] = [$key, $channel, $id];
                            }
                        }
                    }
                    return $removed;
                } finally {
                    // Adapter reads, parsing and removal must never keep a closed identity live.
                    $this->socketIds->forget($fd, $generation);
                }
            });
            foreach ($notifications as [$key, $channel, $id]) {
                $this->deliver(
                    PusherEvent::MEMBER_REMOVED,
                    $channel,
                    json_encode(['user_id' => $id], JSON_THROW_ON_ERROR),
                    null,
                    $key
                );
            }
        } catch (\Throwable $error) {
            self::diagnostic($error);
        } finally {
            // Leases and per-socket rates belong to this generation, including failed closes.
            $this->admission?->forget($generation);
        }
    }

    public function deliver(
        string $event,
        string $channel,
        string $data,
        ?string $excludeSocketId = null,
        ?string $appKey = null,
    ): void {
        $appKey = $this->context($appKey);
        $frame = PusherFrame::encode($event, $data, $channel);
        $excludeFd = $excludeSocketId === null ? null : $this->socketIds->fdFor($excludeSocketId);
        if ($excludeFd !== null && $this->socketIds->appKeyFor($excludeFd) !== $appKey) {
            $excludeFd = null;
        }
        foreach ($this->channels->subscribersOf($channel, $appKey) as $fd => $unused) {
            $fd = (int) $fd;
            $generation = $this->socketIds->socketIdFor($fd);
            if (
                $fd === $excludeFd || !$this->server->isEstablished($fd) || $generation === null
                || $this->socketIds->appKeyFor($fd) !== $appKey
                || !$this->channels->isSubscribed($fd, $channel, $appKey)
            ) {
                continue;
            }
            // Broadcast hooks may yield; recheck the identity after filtering in the push helper.
            $this->push($fd, $frame, $generation, $appKey, $channel);
        }
    }

    /** @return array<array-key, string> */
    public function subscribersOf(string $channel, ?string $appKey = null): array
    {
        return $this->channels->subscribersOf($channel, $this->context($appKey));
    }

    /** @return array<array-key, string> */
    public function allSubscriptions(?string $appKey = null): array
    {
        return $this->channels->allSubscriptions($this->context($appKey));
    }

    /** @return array{count: int, ids: array<array-key, string>, hash: array<string, mixed>} */
    public function roster(string $channel, ?string $appKey = null): array
    {
        $appKey = $this->context($appKey);
        $ids = $hash = [];
        foreach ($this->presence()->getMembers(PusherValidation::scope($appKey, $channel)) as $fd => $raw) {
            if (!$this->channels->isSubscribed((int) $fd, $channel, $appKey)) {
                continue;
            }
            $data = PusherValidation::presence($raw);
            $id = (string) $data['user_id'];
            if (array_key_exists($id, $hash)) {
                continue;
            }
            $ids[] = $id;
            $hash[$id] = $data['user_info'] ?? null;
        }
        return ['count' => count($ids), 'ids' => $ids, 'hash' => $hash];
    }

    /** @param array<string, mixed> $data */
    private function subscribe(int $fd, string $channel, array $data, PusherApp $app, string $generation): void
    {
        $presence = str_starts_with($channel, 'presence-');
        $channelData = $data['channel_data'] ?? null;
        $parsed = $presence ? PusherValidation::presence($channelData) : null;
        if ($presence || str_starts_with($channel, 'private-')) {
            try {
                $auth = PusherValidation::auth($data['auth'] ?? null, $app->key);
            } catch (\InvalidArgumentException $error) {
                $this->push(
                    $fd,
                    PusherFrame::error(PusherEvent::ERROR_UNAUTHORIZED, 'Invalid auth signature'),
                    $generation,
                );
                return;
            }
            if (
                !$this->signer->verifyChannelAuth(
                    $app->key,
                    $app->secret,
                    $auth,
                    $generation,
                    $channel,
                    $presence ? $channelData : null
                )
            ) {
                $this->push(
                    $fd,
                    PusherFrame::error(PusherEvent::ERROR_UNAUTHORIZED, 'Invalid auth signature'),
                    $generation,
                );
                return;
            }
        }
        $announce = $this->channels->transaction(function () use (
            $fd,
            $channel,
            $app,
            $generation,
            $presence,
            $channelData,
            $parsed,
        ): bool {
            $this->boundApp($fd, $generation);
            if ($this->channels->isSubscribed($fd, $channel, $app->key)) {
                return false;
            }
            $first = $presence
                && !in_array((string) $parsed['user_id'], $this->roster($channel, $app->key)['ids'], true);
            $this->channels->insert($fd, $channel, $app->key, $presence);
            if ($presence) {
                $scope = PusherValidation::scope($app->key, $channel);
                try {
                    $this->presence()->add($fd, $scope, $channelData);
                    if (($this->presence()->getMembers($scope)[$fd] ?? null) !== $channelData) {
                        throw new \RuntimeException('Presence storage mismatch');
                    }
                    $this->channels->mark($fd, $channel, $app->key, 1);
                } catch (\Throwable $error) {
                    // The pending row remains the rollback journal if cleanup also fails.
                    $this->presence()->remove($fd, $scope);
                    if (isset($this->presence()->getMembers($scope)[$fd])) {
                        throw new \RuntimeException('Presence rollback failed', 0, $error);
                    }
                    $this->channels->remove($fd, $channel, $app->key);
                    throw $error;
                }
            }
            return $first;
        });
        $roster = $presence ? $this->roster($channel, $app->key) : null;
        $this->push($fd, PusherFrame::subscriptionSucceeded($channel, $roster), $generation);
        if ($announce) {
            $this->deliver(PusherEvent::MEMBER_ADDED, $channel, json_encode([
                'user_id' => $parsed['user_id'], 'user_info' => $parsed['user_info'] ?? null,
            ], JSON_THROW_ON_ERROR), $generation, $app->key);
        }
    }

    private function unsubscribe(int $fd, string $channel, PusherApp $app, string $generation): void
    {
        $removed = $this->channels->transaction(function () use ($fd, $channel, $app, $generation): ?string {
            $this->boundApp($fd, $generation);
            if (!$this->channels->isSubscribed($fd, $channel, $app->key)) {
                return null;
            }
            $userId = null;
            if (str_starts_with($channel, 'presence-')) {
                $scope = PusherValidation::scope($app->key, $channel);
                $raw = $this->presence()->getMembers($scope)[$fd] ?? null;
                $userId = $raw === null ? null : (string) PusherValidation::presence($raw)['user_id'];
                $this->channels->mark($fd, $channel, $app->key, 2);
                $this->presence()->remove($fd, $scope);
                if (isset($this->presence()->getMembers($scope)[$fd])) {
                    throw new \RuntimeException('Presence removal failed');
                }
            }
            $this->channels->remove($fd, $channel, $app->key);
            return $userId !== null && !in_array($userId, $this->roster($channel, $app->key)['ids'], true)
                ? $userId : null;
        });
        if ($removed !== null) {
            $this->deliver(
                PusherEvent::MEMBER_REMOVED,
                $channel,
                json_encode(['user_id' => $removed], JSON_THROW_ON_ERROR),
                null,
                $app->key,
            );
        }
    }

    private function boundApp(int $fd, ?string $generation): PusherApp
    {
        $key = $this->socketIds->appKeyFor($fd);
        $app = $key === null ? null : $this->appManager->findByKey($key);
        if (
            $generation === null || $this->socketIds->socketIdFor($fd) !== $generation
            || $app === null || !$app->enabled
        ) {
            throw new \InvalidArgumentException('Connection is not bound');
        }
        return $app;
    }

    private function context(?string $key): string
    {
        if ($key === null) {
            $enabled = array_values(array_filter($this->appManager->all(), fn(PusherApp $app) => $app->enabled));
            $key = count($enabled) === 1 ? $enabled[0]->key : null;
        }
        $app = $key === null ? null : $this->appManager->findByKey($key);
        if ($app === null || !$app->enabled) {
            throw new \InvalidArgumentException('App context required');
        }
        return $app->key;
    }

    private function push(
        int $fd,
        string $data,
        ?string $generation = null,
        ?string $app = null,
        ?string $channel = null,
    ): void {
        $generation ??= $this->socketIds->socketIdFor($fd);
        $data = \Hook\Filter::applyFilters(Constants::FILTER_PUSH_MESSAGE, $data, $fd, $this->server);
        if (
            $generation === null || $generation !== $this->socketIds->socketIdFor($fd)
            || ($app !== null && ($this->socketIds->appKeyFor($fd) !== $app
                || ($channel !== null && !$this->channels->isSubscribed($fd, $channel, $app))))
        ) {
            return;
        }
        if ($this->server->isEstablished($fd) && !$this->server->push($fd, $data)) {
            $this->server->close($fd);
        }
        Action::doAction(Constants::ACTION_AFTER_PUSH_MESSAGE, $fd, $data, $this->server, null);
    }

    private function presence(): PresenceChannelPersistenceInterface
    {
        /** @var PresenceChannelPersistenceInterface $presence */
        $presence = $this->persistence[Constants::PRESENCE_CHANNELS];
        return $presence;
    }

    public static function diagnostic(\Throwable $error): void
    {
        // Bounded values only: no input, auth material, message or trace.
        PusherDiagnostics::record($error);
    }
}
