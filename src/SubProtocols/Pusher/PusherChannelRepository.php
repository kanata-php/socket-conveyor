<?php

namespace Conveyor\SubProtocols\Pusher;

use OpenSwoole\Table;

class PusherChannelRepository
{
    public const MAX_TABLE_SIZE = 50000;

    protected Table $subscriptions;

    public function __construct()
    {
        $this->createTable();
    }

    public function subscribe(int $fd, string $channel): void
    {
        $this->subscriptions->set($this->key($fd, $channel), [
            'fd' => $fd,
            'channel' => $channel,
        ]);
    }

    public function unsubscribe(int $fd, string $channel): void
    {
        $this->subscriptions->del($this->key($fd, $channel));
    }

    /**
     * @return array<array-key, string> Removed channel names.
     */
    public function unsubscribeAll(int $fd): array
    {
        $removed = [];

        foreach ($this->subscriptions as $key => $value) {
            if ((int) $value['fd'] !== $fd) {
                continue;
            }

            $removed[] = $value['channel'];
            $this->subscriptions->del($key);
        }

        return $removed;
    }

    /**
     * @return array<array-key, string> Format: [fd => channel].
     */
    public function subscribersOf(string $channel): array
    {
        $subscribers = [];

        foreach ($this->subscriptions as $value) {
            if ($value['channel'] === $channel) {
                $subscribers[(int) $value['fd']] = $channel;
            }
        }

        return $subscribers;
    }

    /**
     * @return array<array-key, string>
     */
    public function allSubscriptions(): array
    {
        $subscriptions = [];

        foreach ($this->subscriptions as $key => $value) {
            $subscriptions[$key] = $value['channel'];
        }

        return $subscriptions;
    }

    public function isSubscribed(int $fd, string $channel): bool
    {
        return $this->subscriptions->get($this->key($fd, $channel), 'channel') !== false;
    }

    public function destroyTable(): void
    {
        $this->subscriptions->destroy();
    }

    private function createTable(): void
    {
        $this->subscriptions = new Table(self::MAX_TABLE_SIZE);
        $this->subscriptions->column('fd', Table::TYPE_INT, 8);
        $this->subscriptions->column('channel', Table::TYPE_STRING, 200);
        $this->subscriptions->create();
    }

    private function key(int $fd, string $channel): string
    {
        return $fd . ':' . $channel;
    }
}
