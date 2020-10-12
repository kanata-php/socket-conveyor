<?php

namespace Conveyor\SocketHandlers\Abstractions;

use Slim\Container;
use Conveyor\SocketHandlers\Interfaces\SocketHandlerInterface;
use Conveyor\Actions\Interfaces\ActionInterface;

abstract class SocketHandler implements SocketHandlerInterface
{
    /**
     * @param string $data
     * @param int $fd
     */
    public function __invoke(string $data, $fd = null)
    {
        /** @var ActionInterface */
        $action = $this->parseData($data);

        $this->maybeSetFd($fd);
        
        return $action->execute();
    }

    public function maybeSetFd($fd) {
        $parsedData = $this->getParsedData();
        $action = $this->getAction($parsedData['action']);

        if ($fd && method_exists($action, 'setFd')) {
            $action->setFd($fd);
        }
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
