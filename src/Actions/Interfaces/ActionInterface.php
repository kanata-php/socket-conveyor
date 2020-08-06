<?php

namespace Conveyor\Actions\Interfaces;

interface ActionInterface
{
    public function execute();
    public function getName() : string;
}
