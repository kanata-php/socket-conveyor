<?php

namespace Conveyor\SubProtocols\Conveyor\Persistence\WebSockets\Table;

use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\UserAssocPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\WebSockets\Table\Abstracts\TablePersistence;
use OpenSwoole\Table;

class SocketUserAssocPersistenceTable extends TablePersistence implements UserAssocPersistenceInterface
{
    protected Table $table;

    public function __construct()
    {
        $this->createTable();
    }

    public function assoc(int $fd, int $userId): void
    {
        $this->table->set((string) $fd, ['user_id' => $userId]);
    }

    public function disassoc(int $userId): void
    {
        foreach ($this->table as $key => $value) {
            if ($value['user_id'] === $userId) {
                $this->table->del($key);
            }
        }
    }

    public function getAssoc(int $fd): ?int
    {
        $result = $this->table->get((string) $fd, 'user_id');

        if (!$result) {
            return null;
        }

        return $result;
    }

    public function getAllAssocs(): array
    {
        $collection = [];
        foreach ($this->table as $key => $value) {
            $collection[$key] = $value['user_id'];
        }
        return $collection;
    }

    private function createTable(): void
    {
        $this->table = new Table(self::MAX_TABLE_SIZE);
        $this->table->column('user_id', Table::TYPE_INT, 10);
        $this->table->create();
    }

    public function destroyTable(): void
    {
        $this->table->destroy();
    }

    public function refresh(bool $fresh = false): static
    {
        $this->destroyTable();
        $this->createTable();

        return $this;
    }
}
