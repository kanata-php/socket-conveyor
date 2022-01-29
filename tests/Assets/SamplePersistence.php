<?php

namespace Tests\Assets;

use Conveyor\Actions\Interfaces\PersistenceInterface;

class SamplePersistence implements PersistenceInterface
{
    protected array $data = [];

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
}
