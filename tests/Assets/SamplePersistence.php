<?php

namespace Tests\Assets;

use Conveyor\SocketHandlers\Interfaces\PersistenceInterface;

class SamplePersistence implements PersistenceInterface
{
    protected array $data = [];
    protected array $listeners = [];
    protected array $associations = [];

    public function getListener(int $fd): array
    {
        return $this->listeners[$fd];
    }

    public function listen(int $fd, string $action): void
    {
        if (!isset($this->listeners[$fd])) {
            $this->listeners[$fd] = [];
        }

        $this->listeners[$fd][] = $action;
    }

    public function getAllListeners(): array
    {
        return $this->listeners;
    }

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

    public function assoc(int $fd, int $userId): void
    {
        $this->associations[$fd] = $userId;
    }

    public function disassoc(int $fd): void
    {
        unset($this->associations[$fd]);
    }

    public function getAssoc(int $fd): int
    {
        return $this->associations[$fd];
    }

    public function getAllAssocs(): array
    {
        return $this->associations;
    }
}
