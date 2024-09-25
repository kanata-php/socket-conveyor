<?php

namespace Conveyor\Traits;

use Conveyor\Config\ConveyorOptions;
use Conveyor\SubProtocols\Conveyor\Persistence\Interfaces\GenericPersistenceInterface;

trait HasProperties
{
    public function host(string $host): static
    {
        $this->host = $host;

        return $this;
    }

    public function port(int $port): static
    {
        $this->port = $port;

        return $this;
    }

    public function mode(int $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    public function socketType(int $socketType): static
    {
        $this->socketType = $socketType;

        return $this;
    }

    /**
     * @param array<mixed> $serverOptions
     * @return $this
     */
    public function serverOptions(array $serverOptions): static
    {
        $this->serverOptions = $serverOptions;

        return $this;
    }

    /**
     * @param array<mixed>|ConveyorOptions $conveyorOptions
     * @return $this
     */
    public function conveyorOptions(array|ConveyorOptions $conveyorOptions): static
    {
        $this->conveyorOptions = $conveyorOptions;

        return $this;
    }

    /**
     * @param array<callable|array<string>> $eventListeners
     * @return $this
     */
    public function eventListeners(array $eventListeners): static
    {
        $this->eventListeners = $eventListeners;

        return $this;
    }

    /**
     * @param array<GenericPersistenceInterface> $persistence
     * @return $this
     */
    public function persistence(array $persistence): static
    {
        $this->persistence = $persistence;

        return $this;
    }
}
