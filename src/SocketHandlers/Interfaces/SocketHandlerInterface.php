<?php

namespace Conveyor\SocketHandlers\Interfaces;

use Exception;
use Conveyor\Actions\Interfaces\ActionInterface;

interface SocketHandlerInterface
{
    /**
     * @param string $data Data to be processed.
     * @param int $fd File descriptor (connection).
     * @param mixed $server Server object, e.g. Swoole\WebSocket\Frame.
     */
    public function handle(string $data, int $fd, mixed $server);

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
