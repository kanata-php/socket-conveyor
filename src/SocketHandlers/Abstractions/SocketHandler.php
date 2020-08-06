<?php

namespace Conveyor\SocketHandlers\Abstractions;

use Slim\Container;
use Conveyor\SocketHandlers\Interfaces\SocketHandlerInterface;
use Conveyor\Actions\Interfaces\ActionInterface;

abstract class SocketHandler implements SocketHandlerInterface
{
    /**
     * @param string $data
     */
    public function __invoke(string $data)
    {
        /** @var ActionInterface */
        $action = $this->parseData($data);
        
        return $action->execute();
    }

    /**
     * This method identifies the action to be executed
     *
     * @param string $data
     *
     * @return ActionInterface
     *
     * @throws InvalidArgumentException
     */
    abstract public function parseData(string $data) : ActionInterface;

    /**
     * @param array $data
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    abstract public function validateData(array $data) : void;
}
