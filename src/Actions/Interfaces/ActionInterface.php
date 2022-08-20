<?php

namespace Conveyor\Actions\Interfaces;

interface ActionInterface
{
    public function execute(array $data): mixed;
    public function send(string $data, ?int $fd = null, bool $toChannel = false): void;
    public function getName() : string;
    public function setFd(int $fd): void;
    public function setServer(mixed $server): void;
    public function __invoke(array $data): mixed;
}
