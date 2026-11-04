<?php

namespace Conveyor\SubProtocols\Pusher;

use OpenSwoole\Table;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\PresenceChannelPersistenceInterface;

class PusherChannelRepository
{
    public const MAX_TABLE_SIZE = 50000;
    protected Table $subscriptions;
    private PusherMutex $lock;
    private PusherLimits $limits;
    private ?PresenceChannelPersistenceInterface $presence = null;

    public function usePresencePersistence(
        PresenceChannelPersistenceInterface $presence,
    ): void {
        $this->presence = $presence;
    }

    public function __construct(private ?SocketIdRepository $socketIds = null, ?PusherLimits $limits = null)
    {
        $this->limits = $limits ?? new PusherLimits();
        $this->lock = $socketIds?->mutex() ?? new PusherMutex();
        $this->subscriptions = new Table(self::MAX_TABLE_SIZE);
        $this->subscriptions->column('fd', Table::TYPE_INT, 8);
        $this->subscriptions->column('channel', Table::TYPE_STRING, 164);
        $this->subscriptions->column('app', Table::TYPE_STRING, 64);
        $this->subscriptions->column('generation', Table::TYPE_STRING, 40);
        $this->subscriptions->column('state', Table::TYPE_INT, 1);
        $this->subscriptions->column('pid', Table::TYPE_INT, 8);
        $this->subscriptions->create();
    }

    /**
     * The callback must not yield, push or call network services.
     * @template T
     * @param callable(): T $op
     * @return T
     */
    public function transaction(callable $op): mixed
    {
        $recover = !$this->lock->held();
        return $this->lock->run(function () use ($op, $recover): mixed {
            if ($recover) {
                $this->recover();
            }
            return $op();
        });
    }

    public function subscribe(int $fd, string $channel, ?string $appKey = null): void
    {
        $this->transaction(fn() => $this->insert($fd, $channel, $appKey));
    }

    /** Internal transaction primitive used with presence persistence. */
    public function insert(int $fd, string $channel, ?string $appKey = null, bool $pending = false): void
    {
        PusherValidation::channel($channel);
        $generation = $this->generation($fd, $appKey);
        if ($this->isSubscribed($fd, $channel, $appKey)) {
            return;
        }
        $socket = $app = $total = $presence = 0;
        foreach ($this->subscriptions as $row) {
            // An unresolved journal must not share the adapter's fd/scope with a replacement.
            if (
                (int) $row['fd'] === $fd && $row['channel'] === $channel
                && $row['app'] === ($appKey ?? '') && $row['state'] !== 1
            ) {
                throw new \RuntimeException('Presence cleanup pending');
            }
            $total++;
            if ($row['app'] === ($appKey ?? '')) {
                $app++;
                $socket += (int) ((int) $row['fd'] === $fd && $row['generation'] === $generation);
                $presence += (int) ($row['channel'] === $channel);
            }
        }
        if (
            $socket >= $this->limits->get('memberships_per_socket')
            || $app >= $this->limits->get('memberships_per_app') || $total >= $this->limits->get('memberships_total')
            || (str_starts_with($channel, 'presence-') && $presence >= $this->limits->get('presence_per_channel'))
        ) {
            throw new \OverflowException('Membership capacity reached');
        }
        $key = $this->key($fd, $channel, $appKey, $generation);
        $row = [
            'fd' => $fd, 'channel' => $channel, 'app' => $appKey ?? '',
            'generation' => $generation, 'state' => $pending ? 0 : 1, 'pid' => getmypid(),
        ];
        if ($this->subscriptions->exists($key) || !$this->subscriptions->set($key, $row)) {
            throw new \RuntimeException('Membership storage unavailable');
        }
        if ($this->subscriptions->get($key) !== $row) {
            $this->subscriptions->del($key);
            throw new \RuntimeException('Membership storage mismatch');
        }
    }

    public function unsubscribe(int $fd, string $channel, ?string $appKey = null): void
    {
        $this->transaction(fn() => $this->remove($fd, $channel, $appKey));
    }

    public function remove(int $fd, string $channel, ?string $appKey = null): void
    {
        $generation = $this->generation($fd, $appKey);
        $row = $this->subscriptions->get($this->key($fd, $channel, $appKey, $generation));
        if (
            $row !== false && (int) $row['fd'] === $fd && $row['channel'] === $channel
            && $row['app'] === ($appKey ?? '') && $row['generation'] === $generation
        ) {
            $this->subscriptions->del($this->key($fd, $channel, $appKey, $this->generation($fd, $appKey)));
        }
    }

    /** @return array<array-key, string> */
    public function unsubscribeAll(int $fd, ?string $appKey = null): array
    {
        return $this->transaction(function () use ($fd, $appKey): array {
            $removed = [];
            foreach ($this->subscribersRows($appKey) as $row) {
                if ((int) $row['fd'] === $fd) {
                    $removed[] = $row['channel'];
                    $this->remove($fd, $row['channel'], $appKey);
                }
            }
            return $removed;
        });
    }

    /** @return array<array-key, string> */
    public function subscribersOf(string $channel, ?string $appKey = null): array
    {
        $result = [];
        foreach ($this->subscribersRows($appKey) as $row) {
            if ($row['channel'] === $channel) {
                $result[(int) $row['fd']] = $channel;
            }
        }
        return $result;
    }

    /** @return array<array-key, string> */
    public function allSubscriptions(?string $appKey = null): array
    {
        return array_column($this->subscribersRows($appKey), 'channel');
    }

    public function isSubscribed(int $fd, string $channel, ?string $appKey = null): bool
    {
        if (
            $this->socketIds !== null && ($this->socketIds->socketIdFor($fd) === null
            || $this->socketIds->appKeyFor($fd) !== $appKey)
        ) {
            return false;
        }
        $gen = $this->generation($fd, $appKey);
        $row = $this->subscriptions->get($this->key($fd, $channel, $appKey, $gen));
        return $row !== false && (int) $row['fd'] === $fd && $row['channel'] === $channel
            && $row['app'] === ($appKey ?? '') && $row['generation'] === $gen && $row['state'] === 1;
    }

    /**
     * @return array<array-key, array{fd: int, channel: string, app: string, generation: string, state: int, pid: int}>
     */
    private function subscribersRows(?string $appKey): array
    {
        $rows = [];
        foreach ($this->subscriptions as $row) {
            if (
                $row['state'] === 1 && $row['app'] === ($appKey ?? '') && ($this->socketIds === null
                || ($this->socketIds->socketIdFor($row['fd']) === $row['generation']
                    && $this->socketIds->appKeyFor($row['fd']) === $appKey))
            ) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    public function mark(int $fd, string $channel, ?string $app, int $state): void
    {
        $key = $this->key($fd, $channel, $app, $this->generation($fd, $app));
        $row = $this->subscriptions->get($key);
        if (
            $row === false || (int) $row['fd'] !== $fd || $row['channel'] !== $channel
            || $row['app'] !== ($app ?? '')
            || !$this->subscriptions->set($key, ['state' => $state, 'pid' => getmypid()])
        ) {
            throw new \RuntimeException('Membership transaction unavailable');
        }
    }

    private function recover(): void
    {
        foreach ($this->subscriptions as $key => $row) {
            $stale = $this->socketIds !== null && $this->socketIds->socketIdFor($row['fd']) !== $row['generation'];
            // Under the exclusive outer lock, an incomplete row belongs to an abandoned transaction.
            $dead = $row['state'] !== 1;
            if (!$stale && !$dead) {
                continue;
            }
            try {
                // Hide stale rows even when their adapter cannot currently remove them.
                if (!$this->subscriptions->set($key, ['state' => 2, 'pid' => getmypid()])) {
                    throw new \RuntimeException('Membership recovery unavailable');
                }
                if ($this->presence !== null && str_starts_with($row['channel'], 'presence-')) {
                    // Native adapters identify members by fd/scope, without a generation column.
                    // A committed replacement owns that slot; an old journal must not delete it.
                    $replacement = false;
                    foreach ($this->subscribersRows($row['app']) as $active) {
                        if ((int) $active['fd'] === (int) $row['fd'] && $active['channel'] === $row['channel']) {
                            $replacement = true;
                            break;
                        }
                    }
                    if (!$replacement) {
                        $scope = PusherValidation::scope($row['app'], $row['channel']);
                        $this->presence->remove($row['fd'], $scope);
                        if (isset($this->presence->getMembers($scope)[$row['fd']])) {
                            throw new \RuntimeException('Presence recovery failed');
                        }
                    }
                }
            } catch (\Throwable $error) {
                // Preserve quota accounting and retry on the next outer transaction.
                PusherDiagnostics::record($error);
                continue;
            }
            $this->subscriptions->del($key);
        }
    }

    private function generation(int $fd, ?string $app): string
    {
        if ($this->socketIds === null) {
            return '';
        }
        $generation = $this->socketIds->socketIdFor($fd);
        if ($generation === null || $this->socketIds->appKeyFor($fd) !== $app) {
            throw new \RuntimeException('Connection is not bound');
        }
        return $generation;
    }

    private function key(int $fd, string $channel, ?string $app, string $generation): string
    {
        return substr(hash('sha256', json_encode([$app, $generation, $fd, $channel], JSON_THROW_ON_ERROR)), 0, 48);
    }

    public function destroyTable(): void
    {
        $this->subscriptions->destroy();
    }
}
