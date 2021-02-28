<?php

namespace Conveyor\SocketHandlers\Interfaces;

interface ExceptionHandlerInterface
{
    /**
     * @param $server The socket server instance useful for
     *                sending back the expected error message.
     */
    public function handle($server = null);
}
