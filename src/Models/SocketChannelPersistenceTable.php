<?php

namespace Conveyor\Models;

use Conveyor\Models\Abstractions\GenericPersistence;
use Conveyor\Models\Interfaces\ChannelPersistenceInterface;
use OpenSwoole\Table;

class SocketChannelPersistenceTable extends GenericPersistence implements ChannelPersistenceInterface
{
    public function __construct()
    {
        $this->createTable();
    }

    public function connect(int $fd, string $channel): void
    {
        $this->table->set($fd, ['channel' => $channel]);
    }

    public function disconnect(int $fd): void
    {
        $this->table->del($fd);
    }

    public function getAllConnections(): array
    {
        $collection = [];
        foreach ($this->table as $key => $value) {
            $collection[$key] = $value['channel'];
        }
        return $collection;
    }

    protected function createTable(): void
    {
        $this->table = new Table(10024);
        $this->table->column('channel', Table::TYPE_STRING, 40);
        $this->table->create();
    }
}
