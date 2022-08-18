<?php

namespace Conveyor\SocketHandlers;

use Conveyor\SocketHandlers\Interfaces\UserAssocPersistenceInterface;
use Swoole\Table;

class SocketUserAssocPersistenceTable implements UserAssocPersistenceInterface
{
    protected Table $table;

    public function __construct()
    {
        $this->table = new Table(10024);
        $this->table->column('user_id', Table::TYPE_INT, 10);
        $this->table->create();
    }

    public function assoc(int $fd, int $userId): void
    {
        $this->table->set($fd, ['user_id' => $userId]);
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
        return $this->table->get($fd);
    }

    public function getAllAssocs(): array
    {
        $collection = [];
        foreach ($this->table as $key => $value) {
            $collection[$key] = $value['user_id'];
        }
        return $collection;
    }
}
