<?php

namespace Conveyor\SocketHandlers\Interfaces;

interface PersistenceInterface
{
    public function connect(int $fd, string $channel): void;
    public function disconnect(int $fd): void;
    public function listen(int $fd, string $action): void;
    public function getListener(int $fd): array;

    /**
     * @return array Format: [fd => [listener1, listener2, ...]]
     */
    public function getAllListeners(): array;

    /**
     * @return array Format: [fd => channel-name, ...]
     */
    public function getAllConnections(): array;
}