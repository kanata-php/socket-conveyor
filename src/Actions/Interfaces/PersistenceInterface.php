<?php

namespace Conveyor\Actions\Interfaces;

interface PersistenceInterface
{
    public function connect(int $fd, string $channel): void;
    public function disconnect(int $fd): void;
    public function getAllConnections(): array;
}