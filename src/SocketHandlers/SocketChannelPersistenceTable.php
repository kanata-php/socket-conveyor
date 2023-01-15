<?php

namespace Conveyor\SocketHandlers;

use Conveyor\SocketHandlers\Interfaces\ChannelPersistenceInterface;
use OpenSwoole\Table;

class SocketChannelPersistenceTable implements ChannelPersistenceInterface
{
    protected Table $table;

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

    /**
     * Truncate the data storage.
     *
     * @return void
     */
    public function refresh(): void
    {
        $this->destroyTable();
        $this->createTable();
    }

    private function createTable()
    {
        $this->table = new Table(10024);
        $this->table->column('channel', Table::TYPE_STRING, 40);
        $this->table->create();
    }

    private function destroyTable()
    {
        $this->table->destroy();
    }
}
