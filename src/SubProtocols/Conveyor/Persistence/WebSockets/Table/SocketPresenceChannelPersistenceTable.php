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
        if (strlen($channel) > 100 || strlen($channelData) > 2048) {
            throw new \InvalidArgumentException('Presence storage limit');
        }
        $key = $this->key($fd, $channel);
        $existing = $this->table->get($key);
        if ($existing !== false && ((int) $existing['fd'] !== $fd || $existing['channel'] !== $channel)) {
            throw new \RuntimeException('Presence storage collision');
        }
        $row = ['fd' => $fd, 'channel' => $channel, 'channel_data' => $channelData];
        if (!$this->table->set($key, $row) || $this->table->get($key) !== $row) {
            $this->table->del($key);
            throw new \RuntimeException('Presence storage unavailable');
        }
    }

    public function remove(int $fd, string $channel): void
    {
        $key = $this->key($fd, $channel);
        $row = $this->table->get($key);
        if ($row !== false && ((int) $row['fd'] !== $fd || $row['channel'] !== $channel)) {
            throw new \RuntimeException('Presence storage collision');
        }
        $this->table->del($key);
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
        return substr(hash('sha256', json_encode([$fd, $channel], JSON_THROW_ON_ERROR)), 0, 48);
    }
}
