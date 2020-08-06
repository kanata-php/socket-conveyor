<?php

namespace Conveyor\SocketHandlers\Interfaces;

use Exception;
use Conveyor\Actions\Interfaces\ActionInterface;

interface SocketHandlerInterface
{
    /**
     * @param string $data
     *
     * @return ActionInterface
     */
    public function parseData(string $data) : ActionInterface;

    /**
     * @param array $data
     *
     * @throws Exception
     */
    public function validateData(array $data);
}
