<?php

namespace Conveyor\SubProtocols\Conveyor\Persistence\WebSockets\Table;

use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\MessageAcknowledgementPersistenceInterface;
use Conveyor\SubProtocols\Conveyor\Persistence\WebSockets\Table\Abstracts\TablePersistence;
use OpenSwoole\Table;

// phpcs:ignore
class SocketMessageAcknowledgmentPersistenceTable extends TablePersistence implements MessageAcknowledgementPersistenceInterface
{
    protected Table $table;

    public function __construct()
    {
        $this->createTable();
    }

    public function register(string $messageHash, int $count): void
    {
        $this->table->set($messageHash, ['count' => $count]);
    }

    public function subtract(string $messageHash): void
    {
        if ($this->table->exists($messageHash)) {
            $this->table->decr($messageHash, 'count');
            if ($this->table->get($messageHash)['count'] < 1) {
                $this->table->del($messageHash);
            }
        }
    }

    public function has(string $messageHash): bool
    {
        return $this->table->exists($messageHash);
    }

    public function acknowledge(string $messageHash): void
    {
        if ($this->table->exists($messageHash)) {
            $this->table->del($messageHash);
        }
    }

    private function createTable(): void
    {
        $this->table = new Table(self::MAX_TABLE_SIZE);
        // this is the coung of times remaining for attempt
        $this->table->column('count', Table::TYPE_INT, 10);
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
