<?php

namespace Conveyor\Actions\Traits;

use Conveyor\SocketHandlers\Interfaces\PersistenceInterface;

trait HasPersistence
{
    protected PersistenceInterface $persistence;

    public function setPersistence(PersistenceInterface $persistence): void
    {
        $this->persistence = $persistence;
    }
}