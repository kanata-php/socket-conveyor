<?php

namespace Conveyor\Models\Abstractions;

use OpenSwoole\Table;

abstract class GenericPersistence
{
    protected ?Table $table = null;

    abstract protected function createTable(): void;

    /**
     * Truncate the data storage.
     *
     * @param bool $fresh
     * @return static
     */
    public function refresh(bool $fresh = false): static
    {
        if (!$fresh) {
            return $this;
        }

        if ($this->table) {
            $this->destroyTable();
        }

        if (!$this->table) {
            $this->createTable();
        }

        return $this;
    }

    protected function destroyTable()
    {
        $this->table->destroy();
    }
}
