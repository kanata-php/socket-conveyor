<?php

namespace Conveyor\Actions\Interfaces;

use Conveyor\Config\ConveyorOptions;

interface ActionInterface
{
    /**
     * @param array<array-key, mixed> $data
     * @return mixed
     */
    public function execute(array $data): mixed;

    public function send(string $data, ?int $fd = null, bool $toChannel = false): void;

    public function getName(): string;

    public function setFd(int $fd): void;

    public function setServer(mixed $server): void;

    public function setFresh(bool $fresh): void;

    public function setConveyorOptions(ConveyorOptions $conveyorOptions): void;

    /**
     * @param array<array-key, mixed> $data
     * @return mixed
     */
    public function __invoke(array $data): mixed;
}
