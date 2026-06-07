<?php

namespace Conveyor\SubProtocols\Conveyor\Persistence\WebSockets\Table;

use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\PresenceChannelPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\WebSockets\Table\Abstracts\TablePersistence;
use OpenSwoole\Table;

class SocketPresenceChannelPersistenceTable extends TablePersistence implements PresenceChannelPersistenceInterface
{
    protected Table $table;

    public function __construct()
    {
        $this->createTable();
    }

    public function add(int $fd, string $channel, string $channelData): void
    {
        $this->table->set($this->key($fd, $channel), [
            'fd' => $fd,
            'channel' => $channel,
            'channel_data' => $channelData,
        ]);
    }

    public function remove(int $fd, string $channel): void
    {
        $this->table->del($this->key($fd, $channel));
    }

    public function removeConnection(int $fd): array
    {
        $removed = [];

        foreach ($this->table as $key => $value) {
            if ($value['fd'] === $fd) {
                $removed[] = [$value['channel'], $value['channel_data']];
                $this->table->del($key);
            }
        }

        return $removed;
    }

    public function getMembers(string $channel): array
    {
        $collection = [];
        foreach ($this->table as $value) {
            if ($value['channel'] === $channel) {
                $collection[$value['fd']] = $value['channel_data'];
            }
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
        $this->table->column('fd', Table::TYPE_INT, 8);
        $this->table->column('channel', Table::TYPE_STRING, 100);
        $this->table->column('channel_data', Table::TYPE_STRING, 2048);
        $this->table->create();
    }

    public function destroyTable(): void
    {
        $this->table->destroy();
    }

    private function key(int $fd, string $channel): string
    {
        return $fd . ':' . $channel;
    }
}
