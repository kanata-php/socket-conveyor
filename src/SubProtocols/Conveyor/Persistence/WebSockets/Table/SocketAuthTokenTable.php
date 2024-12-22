<?php

namespace Conveyor\SubProtocols\Conveyor\Persistence\WebSockets\Table;

use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\AuthTokenPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\WebSockets\Table\Abstracts\TablePersistence;
use OpenSwoole\Table;

class SocketAuthTokenTable extends TablePersistence implements AuthTokenPersistenceInterface
{
    protected Table $table;

    public function __construct()
    {
        $this->createTable();
    }

    public function storeToken(string $token, string $channel): void
    {
        $this->table->set($token, ['channel' => $channel]);
    }

    public function byToken(string $token): array|false
    {
        return $this->table->get($token);
    }

    public function consume(string $token): void
    {
        $this->table->del($token);
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
}
