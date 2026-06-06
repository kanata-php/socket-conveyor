<?php

namespace Conveyor\SubProtocols\Pusher;

use Conveyor\Constants;
use Conveyor\SubProtocols\Conveyor\Broadcast;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\ChannelPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\GenericPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\PresenceChannelPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\UserAssocPersistenceInterface;
use Conveyor\SubProtocols\Pusher\Frame\PusherEvent;
use Conveyor\SubProtocols\Pusher\Frame\PusherFrame;
use OpenSwoole\WebSocket\Server;

/**
 * Routes decoded Pusher wire frames to channel/presence behaviour and owns the
 * per-channel delivery primitive reused by the REST publish endpoint.
 */
class PusherEventRouter
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private const PRIVATE_PREFIX = 'private-';
    private const PRESENCE_PREFIX = 'presence-';
    private const CLIENT_PREFIX = 'client-';

    private PusherSigner $signer;

    /**
     * @param array<array-key, GenericPersistenceInterface> $persistence
     */
    public function __construct(
        private Server $server,
        private array $persistence,
        private SocketIdRepository $socketIds,
        private AppManager $appManager,
    ) {
        $this->signer = new PusherSigner();
    }

    /**
     * Entry point for an inbound client frame (raw JSON text).
     */
    public function handle(int $fd, string $raw): void
    {
        $frame = PusherFrame::decode($raw);
        $event = $frame['event'] ?? null;

        if (!is_string($event)) {
            return;
        }

        if (str_starts_with($event, self::CLIENT_PREFIX)) {
            $this->handleClientEvent($fd, $event, $frame);
            return;
        }

        match ($event) {
            PusherEvent::PING => $this->push($fd, PusherFrame::pong()),
            PusherEvent::SUBSCRIBE => $this->handleSubscribe($fd, PusherFrame::dataArray($frame)),
            PusherEvent::UNSUBSCRIBE => $this->handleUnsubscribe($fd, PusherFrame::dataArray($frame)),
            default => null,
        };
    }

    /**
     * Tear down a disconnected connection: drop its memberships, notify the
     * presence channels it leaves, and release its socket_id.
     */
    public function handleClose(int $fd): void
    {
        $removed = $this->presence()->removeConnection($fd);

        // Remove the channel mapping before notifying so the leaver is excluded.
        $this->channels()->disconnect($fd);

        foreach ($removed as [$channel, $channelData]) {
            $userId = $this->userIdFromChannelData($channelData);
            if ($userId !== null && !$this->userStillPresent($channel, $userId)) {
                $this->deliver(
                    PusherEvent::MEMBER_REMOVED,
                    $channel,
                    $this->jsonData(['user_id' => $userId]),
                );
            }
        }

        $this->socketIds->forget($fd);
    }

    /**
     * Push an {event, channel, data} frame to every subscriber of a channel,
     * excluding the connection that owns $excludeSocketId when supplied.
     *
     * This is the shared delivery path used by client events ("to others") and
     * by the REST publish endpoint.
     */
    public function deliver(string $event, string $channel, string $data, ?string $excludeSocketId = null): void
    {
        $frame = PusherFrame::encode($event, $data, $channel);

        $excludeFd = $excludeSocketId !== null
            ? $this->socketIds->fdFor($excludeSocketId)
            : null;

        Broadcast::broadcastToChannel(
            data: $frame,
            channel: $channel,
            currentFd: $excludeFd ?? 0,
            server: $this->server,
            channelPersistence: $this->channels(),
            ackPersistence: null,
            includeSelf: $excludeFd === null,
        );
    }

    /**
     * Resolve the current subscribers of a channel as [fd => channel].
     *
     * @return array<array-key, string>
     */
    public function subscribersOf(string $channel): array
    {
        return array_filter(
            $this->channels()->getAllConnections(),
            fn($subscribed) => $subscribed === $channel,
        );
    }

    /**
     * Resolve every recorded channel subscription.
     *
     * @return array<array-key, string>
     */
    public function allSubscriptions(): array
    {
        return $this->channels()->getAllConnections();
    }

    /**
     * Compute the presence roster for a channel from presence persistence.
     *
     * Rosters are per-user: connections sharing a user_id collapse to one entry.
     *
     * @return array{count: int, ids: array<array-key, string>, hash: array<string, mixed>}
     */
    public function roster(string $channel): array
    {
        $ids = [];
        $hash = [];

        foreach ($this->presence()->getMembers($channel) as $channelData) {
            $parsed = json_decode($channelData, true);
            if (!is_array($parsed) || !isset($parsed['user_id'])) {
                continue;
            }

            $userId = (string) $parsed['user_id'];
            if (isset($hash[$userId])) {
                continue;
            }

            $ids[] = $userId;
            $hash[$userId] = $parsed['user_info'] ?? null;
        }

        return [
            'count' => count($ids),
            'ids' => $ids,
            'hash' => $hash,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function handleSubscribe(int $fd, array $data): void
    {
        $channel = $data['channel'] ?? null;
        if (!is_string($channel) || $channel === '') {
            return;
        }

        $app = $this->appFor($fd);
        $socketId = $this->socketIds->socketIdFor($fd) ?? '';

        if (str_starts_with($channel, self::PRESENCE_PREFIX)) {
            $this->handlePresenceSubscribe($fd, $channel, $data, $app, $socketId);
            return;
        }

        if (str_starts_with($channel, self::PRIVATE_PREFIX)) {
            $auth = (string) ($data['auth'] ?? '');
            if (
                $app === null
                || !$this->signer->verifyChannelAuth($app->key, $app->secret, $auth, $socketId, $channel)
            ) {
                $this->push($fd, PusherFrame::error(PusherEvent::ERROR_UNAUTHORIZED, 'Invalid auth signature'));
                return;
            }

            $this->channels()->connect($fd, $channel);
            $this->push($fd, PusherFrame::subscriptionSucceeded($channel));
            return;
        }

        // Public channel: no authentication required.
        $this->channels()->connect($fd, $channel);
        $this->push($fd, PusherFrame::subscriptionSucceeded($channel));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function handlePresenceSubscribe(
        int $fd,
        string $channel,
        array $data,
        ?PusherApp $app,
        string $socketId,
    ): void {
        $channelData = $data['channel_data'] ?? null;
        $auth = (string) ($data['auth'] ?? '');

        if (
            $app === null
            || !is_string($channelData)
            || !$this->signer->verifyChannelAuth($app->key, $app->secret, $auth, $socketId, $channel, $channelData)
        ) {
            $this->push($fd, PusherFrame::error(PusherEvent::ERROR_UNAUTHORIZED, 'Invalid auth signature'));
            return;
        }

        $parsed = json_decode($channelData, true);
        if (!is_array($parsed) || !isset($parsed['user_id'])) {
            $this->push($fd, PusherFrame::error(PusherEvent::ERROR_UNAUTHORIZED, 'Invalid channel data'));
            return;
        }

        $userId = $parsed['user_id'];
        $userInfo = $parsed['user_info'] ?? null;

        $this->channels()->connect($fd, $channel);
        $this->presence()->add($fd, $channel, $channelData);

        if (is_numeric($userId)) {
            $this->userAssoc()->assoc($fd, (int) $userId);
        }

        // Roster already includes the just-added member.
        $this->push($fd, PusherFrame::subscriptionSucceeded($channel, $this->roster($channel)));

        $this->deliver(
            PusherEvent::MEMBER_ADDED,
            $channel,
            $this->jsonData(['user_id' => $userId, 'user_info' => $userInfo]),
            $socketId,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function handleUnsubscribe(int $fd, array $data): void
    {
        $channel = $data['channel'] ?? null;
        if (!is_string($channel) || $channel === '') {
            return;
        }

        $isPresence = str_starts_with($channel, self::PRESENCE_PREFIX);
        $userId = $isPresence ? $this->presenceUserId($fd, $channel) : null;

        if ($isPresence) {
            $this->presence()->remove($fd, $channel);
        }

        $this->channels()->disconnect($fd);

        if ($isPresence && $userId !== null && !$this->userStillPresent($channel, $userId)) {
            $this->deliver(
                PusherEvent::MEMBER_REMOVED,
                $channel,
                $this->jsonData(['user_id' => $userId]),
            );
        }
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function handleClientEvent(int $fd, string $event, array $frame): void
    {
        $channel = $frame['channel'] ?? null;
        if (!is_string($channel) || $channel === '') {
            return;
        }

        // Client events are only allowed on authenticated channels.
        if (
            !str_starts_with($channel, self::PRIVATE_PREFIX)
            && !str_starts_with($channel, self::PRESENCE_PREFIX)
        ) {
            $this->push($fd, PusherFrame::error(
                PusherEvent::ERROR_GENERIC,
                'Client events are only supported on private and presence channels',
            ));
            return;
        }

        $app = $this->appFor($fd);
        if ($app === null || !$app->enableClientMessages) {
            $this->push($fd, PusherFrame::error(
                PusherEvent::ERROR_GENERIC,
                'Client events are not enabled for this app',
            ));
            return;
        }

        $payload = $frame['data'] ?? '{}';
        $data = is_string($payload) ? $payload : $this->jsonData($payload);

        // Relay to every other subscriber; never echo back to the sender.
        $this->deliver($event, $channel, $data, $this->socketIds->socketIdFor($fd));
    }

    private function appFor(int $fd): ?PusherApp
    {
        $appKey = $this->socketIds->appKeyFor($fd);
        if ($appKey === null) {
            return null;
        }

        return $this->appManager->findByKey($appKey);
    }

    private function presenceUserId(int $fd, string $channel): ?string
    {
        $members = $this->presence()->getMembers($channel);

        return isset($members[$fd])
            ? $this->userIdFromChannelData($members[$fd])
            : null;
    }

    private function userIdFromChannelData(string $channelData): ?string
    {
        $parsed = json_decode($channelData, true);

        return is_array($parsed) && isset($parsed['user_id'])
            ? (string) $parsed['user_id']
            : null;
    }

    private function userStillPresent(string $channel, string $userId): bool
    {
        foreach ($this->presence()->getMembers($channel) as $channelData) {
            if ($this->userIdFromChannelData($channelData) === $userId) {
                return true;
            }
        }

        return false;
    }

    private function push(int $fd, string $data): void
    {
        Broadcast::push($fd, $data, $this->server, null);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function jsonData(array $payload): string
    {
        $encoded = json_encode($payload, self::JSON_FLAGS);

        return $encoded === false ? '{}' : $encoded;
    }

    private function channels(): ChannelPersistenceInterface
    {
        /** @var ChannelPersistenceInterface $channels */
        $channels = $this->persistence[Constants::CHANNELS];

        return $channels;
    }

    private function presence(): PresenceChannelPersistenceInterface
    {
        /** @var PresenceChannelPersistenceInterface $presence */
        $presence = $this->persistence[Constants::PRESENCE_CHANNELS];

        return $presence;
    }

    private function userAssoc(): UserAssocPersistenceInterface
    {
        /** @var UserAssocPersistenceInterface $userAssoc */
        $userAssoc = $this->persistence[Constants::USER_ASSOCIATIONS];

        return $userAssoc;
    }
}
