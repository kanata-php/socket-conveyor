<?php

namespace Conveyor\SocketHandlers\Abstractions;

use Slim\Container;
use Conveyor\SocketHandlers\Interfaces\SocketHandlerInterface;
use Conveyor\Actions\Interfaces\ActionInterface;

abstract class SocketHandler implements SocketHandlerInterface
{
    /**
     * @param string   $data   Data to be processed.
     * @param int|null $fd     File descriptor (connection).
     * @param mixed    $server Server object, e.g. Swoole\WebSocket\Frame.
     */
    public function __invoke(string $data, $fd = null, $server = null)
    {
        return $this->handle($data, $fd, $server);
    }

    /**
     * @param string   $data   Data to be processed.
     * @param int|null $fd     File descriptor (connection).
     * @param mixed    $server Server object, e.g. Swoole\WebSocket\Frame.
     */
    public function handle(string $data, $fd = null, $server = null)
    {
        /** @var ActionInterface */
        $action = $this->parseData($data);

        $this->maybeSetFd($fd);
        $this->maybeSetServer($server);

        return $action->execute($this->parsedData);
    }

    /**
     * Set $fd (File descriptor) if method "setFd" exists.
     *
     * @param int|null $fd File descriptor.
     *
     * @return void
     */
    public function maybeSetFd($fd = null): void
    {
        $parsedData = $this->getParsedData();
        $action = $this->getAction($parsedData['action']);

        if ($fd && method_exists($action, 'setFd')) {
            $action->setFd($fd);
        }
    }

    /**
     * Set $server if method "setServer" exists.
     *
     * @param mixed $server Server object, e.g. Swoole\WebSocket\Frame.
     *
     * @return void
     */
    public function maybeSetServer($server = null): void
    {
        $parsedData = $this->getParsedData();
        $action = $this->getAction($parsedData['action']);

        if ($server) {
            $this->server = $server;
        }

        if ($server && method_exists($action, 'setServer')) {
            $action->setServer($server);
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
