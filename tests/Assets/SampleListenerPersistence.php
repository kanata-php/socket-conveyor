<?php

namespace Tests\Assets;

use Conveyor\Helpers\Arr;
use Conveyor\SocketHandlers\Interfaces\ListenerPersistenceInterface;

class SampleListenerPersistence implements ListenerPersistenceInterface
{
    protected array $data = [];
    protected array $listeners = [];

    public function getListener(int $fd): ?array
    {
        return Arr::get($this->listeners, $fd);
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

    public function stopListener(int $fd, string $action): bool
    {
        $this->listeners[$fd] = array_filter($this->listeners[$fd], function($item) use ($action) {
            return $item !== $action;
        });
        return true;
    }

    public function stopListenersForFd(int $fd): bool
    {
        unset($this->listeners[$fd]);
        return true;
    }

    public function refresh(): void
    {
        $this->listeners = [];
    }
}

