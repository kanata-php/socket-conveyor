<?php

namespace Conveyor\Persistence\WebSockets\Table;

use Conveyor\Persistence\Interfaces\ListenerPersistenceInterface;
use Conveyor\Persistence\WebSockets\Table\Abstracts\TablePersistence;
use OpenSwoole\Table;

class SocketListenerPersistenceTable extends TablePersistence implements ListenerPersistenceInterface
{
    protected Table $table;

    public function __construct()
    {
        $this->createTable();
    }

    public function listen(int $fd, string $action): void
    {
        $listening = $this->table->get((string) $fd, 'listening');
        $listeningArray = explode(',', $listening);
        $listeningArray = array_filter($listeningArray);
        $listeningArray[] = $action;
        $this->table->set((string) $fd, [
            'listening' => implode(',', $listeningArray),
        ]);
    }

    public function getListener(int $fd): ?array
    {
        $record = $this->table->get((string) $fd, 'listening');

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

        return $this->table->set((string) $fd, [
            'listening' => implode(',', array_filter($actions, fn($a) => $a !== $action)),
        ]);
    }

    public function stopListenersForFd(int $fd): bool
    {
        return $this->table->del((string) $fd);
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
        $this->table->column('listening', Table::TYPE_STRING, 200);
        $this->table->create();
    }

    public function destroyTable(): void
    {
        $this->table->destroy();
    }
}
