<?php

namespace Tests\Assets;

use Conveyor\SocketHandlers\Interfaces\ChannelPersistenceInterface;

class SampleChannelPersistence implements ChannelPersistenceInterface
{
    protected array $data = [];
    protected array $listeners = [];
    protected array $associations = [];

    public function connect(int $fd, string $channel): void
    {
        $this->data[$fd] = $channel;
    }

    public function disconnect(int $fd): void
    {
        unset($this->data[$fd]);
    }

    public function getAllConnections(): array
    {
        return $this->data;
    }

    public function refresh(): void
    {
        $this->data = [];
    }
}
