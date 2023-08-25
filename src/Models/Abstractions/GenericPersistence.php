<?php

namespace Conveyor\Models\Abstractions;

use OpenSwoole\Table;

abstract class GenericPersistence
{
    private ?Table $table = null;

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

        $this->createTable();
        return $this;
    }

    protected function destroyTable()
    {
        $this->table->destroy();
    }
}
