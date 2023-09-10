<?php

namespace Conveyor\Models\Abstractions;

abstract class GenericPersistence
{
    /**
     * Truncate the data storage.
     *
     * @param bool $fresh
     * @return static
     */
    abstract public function refresh(bool $fresh = false): static;
}
