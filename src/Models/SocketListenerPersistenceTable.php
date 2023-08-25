<?php

namespace Conveyor\Models;

use Conveyor\Models\Abstractions\GenericPersistence;
use Conveyor\Models\Interfaces\ListenerPersistenceInterface;
use OpenSwoole\Table;

class SocketListenerPersistenceTable extends GenericPersistence implements ListenerPersistenceInterface
{
    public function __construct()
    {
        $this->createTable();
    }

    public function listen(int $fd, string $action): void
    {
        $listening = $this->table->get($fd, 'listening');
        $listeningArray = explode(',', $listening);
        $listeningArray = array_filter($listeningArray);
        $listeningArray[] = $action;
        $this->table->set($fd, [
            'listening' => implode(',', $listeningArray),
        ]);
    }

    public function getListener(int $fd): ?array
    {
        $record = $this->table->get($fd, 'listening');

        if (!$record) {
            return null;
        }

        return array_filter(explode(',', $record));
    }

    public function getAllListeners(): array
    {
        $collection = [];
        foreach ($this->table as $key => $value) {
            $collection[$key] = array_filter(explode(',', $value['listening']));
        }
        return $collection;
    }

    public function stopListener(int $fd, string $action): bool
    {
        $actions = $this->getListener($fd);

        return $this->table->set($fd, [
            'listening' => implode(',', array_filter($actions, fn($a) => $a !== $action)),
        ]);
    }

    public function stopListenersForFd(int $fd): bool
    {
        return $this->table->del($fd);
    }

    protected function createTable(): void
    {
        $this->table = new Table(10024);
        $this->table->column('listening', Table::TYPE_STRING, 200);
        $this->table->create();
    }
}
