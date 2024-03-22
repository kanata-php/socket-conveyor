<?php

namespace Conveyor\Persistence\WebSockets\Table;

use Conveyor\Persistence\Interfaces\ChannelPersistenceInterface;
use Conveyor\Persistence\WebSockets\Table\Abstracts\TablePersistence;
use OpenSwoole\Table;

class SocketChannelPersistenceTable extends TablePersistence implements ChannelPersistenceInterface
{
    protected Table $table;

    public function __construct()
    {
        $this->createTable();
    }

    public function connect(int $fd, string $channel): void
    {
        $this->table->set((string) $fd, ['channel' => $channel]);
    }

    public function disconnect(int $fd): void
    {
        $this->table->del((string) $fd);
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
     * @param bool $fresh
     * @return static
     */
    public function refresh(bool $fresh = false): static
    {
        $this->destroyTable();
        $this->createTable();

        return $this;
    }

    private function createTable(): void
    {
        $this->table = new Table(self::MAX_TABLE_SIZE);
        $this->table->column('channel', Table::TYPE_STRING, 40);
        $this->table->create();
    }

    public function destroyTable(): void
    {
        $this->table->destroy();
    }

    public function getChannel(int $fd): ?string
    {
        return $this->table->get((string) $fd, 'channel');
    }
}
