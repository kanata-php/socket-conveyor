<?php

namespace Conveyor\Actions\Interfaces;

interface ActionInterface
{
    public function execute(array $data, int $fd, $server);
    public function getName() : string;
}
