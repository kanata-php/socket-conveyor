<?php

namespace Conveyor\Actions\Interfaces;

interface ActionInterface
{
    public function execute(array $data);
    public function getName() : string;
}
