<?php

namespace Conveyor\Traits;

use BadMethodCallException;

trait HasProperties
{
    /**
     * @param string $name
     * @param array<mixed> $arguments
     * @return $this
     */
    public function __call(string $name, array $arguments): static
    {
        if (!property_exists($this, $name)) {
            throw new BadMethodCallException("Undefined method called: {$name}");
        }

        $this->$name = $arguments[0];

        return $this;
    }
}
