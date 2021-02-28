<?php

namespace Conveyor\SocketHandlers\Interfaces;

use Exception;

interface ExceptionHandlerInterface
{
    /**
     * @param Exception $e          Current exception being handled.
     * @param array     $parsedData Parsed data at the current message.
     * @param mixed     $fd         File descriptor (connection).
     * @param mixed     $server     The socket server instance useful for
     *                              sending back the expected error message.
     */
    public function handle(Exception $e, array $data, $fd = null, $server = null);
}
