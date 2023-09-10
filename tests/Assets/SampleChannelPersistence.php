<?php

namespace Tests\Assets;

use Conveyor\Models\Interfaces\ChannelPersistenceInterface;

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

    public function refresh(bool $fresh = false): static
    {
        if ($fresh) {
            $this->data = [];
        }
        return $this;
    }

    public function getChannel(int $fd): ?string
    {
        return $this->data[$fd] ?? null;
    }
}
